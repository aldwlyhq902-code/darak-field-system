<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PerformanceMetricSetting;
use App\Services\AuditLogger;
use App\Services\PerformanceScoreService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PerformanceDashboardController extends Controller
{
    public function __construct(
        private readonly PerformanceScoreService $scores,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to, $period, $reference] = $this->period($request);

        return view('panel.performance', [
            'dashboard' => $this->scores->dashboard($request->user(), $from, $to),
            'settings' => PerformanceMetricSetting::query()->orderBy('category')->orderBy('id')->get()->groupBy('category'),
            'categoryLabels' => PerformanceScoreService::CATEGORY_LABELS,
            'period' => $period, 'reference' => $reference,
            'selectedCategory' => $request->string('category')->toString() ?: 'technicians',
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $data = $request->validate([
            'category' => ['required', 'in:technicians,supervisors,branches,marketers'],
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.*.target' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'settings.*.minimum_sample' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $models = PerformanceMetricSetting::query()->where('category', $data['category'])
            ->whereIn('id', array_keys($data['settings']))->get()->keyBy('id');
        abort_unless($models->count() === count($data['settings']), 422);
        $weight = collect($data['settings'])->sum(fn (array $row) => (float) $row['weight']);
        if (abs($weight - 100) > 0.01) {
            return back()->withInput()->with('err', 'يجب أن يكون مجموع أوزان مؤشرات الفئة 100%.');
        }

        $before = $models->map->only(['id', 'weight', 'target', 'minimum_sample'])->values()->all();
        DB::transaction(function () use ($models, $data): void {
            foreach ($data['settings'] as $id => $row) {
                $models[(int) $id]->update($row);
            }
        });
        $after = $models->map(fn (PerformanceMetricSetting $model) => $model->fresh()->only(['id', 'weight', 'target', 'minimum_sample']))->values()->all();
        $this->audit->record('performance.settings_updated', null, $before, ['category' => $data['category'], 'settings' => $after], $request->user()->id);

        return back()->with('ok', 'حُفظت أوزان وأهداف التقييم، وستُطبق على النتائج فورًا.');
    }

    public function csv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $dashboard = $this->scores->dashboard($request->user(), $from, $to);
        $filename = 'performance-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($dashboard): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الفئة', 'الترتيب', 'الاسم', 'النطاق/الفرع', 'الدرجة', 'التقدير', 'حالة العينة', 'التغير']);
            foreach ($dashboard['categories'] as $category => $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, $this->safeCsvRow([PerformanceScoreService::CATEGORY_LABELS[$category], $row['rank'], $row['name'], $row['context'], $row['score'], $row['grade'], $row['sufficient'] ? 'مكتملة' : 'أولية', $row['score_delta']]));
                    foreach ($row['metrics'] as $metric) {
                        fputcsv($out, $this->safeCsvRow(['', '', '↳ '.$metric['label'], $metric['display'], $metric['score'], 'الوزن '.$metric['weight'].'%', 'العينة '.$metric['samples'].'/'.$metric['minimum_sample'], '']));
                    }
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<int, mixed> $row
     * @return array<int, mixed>
     */
    private function safeCsvRow(array $row): array
    {
        return array_map(static function (mixed $value): mixed {
            if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
                return "'".$value;
            }

            return $value;
        }, $row);
    }

    public function pdf(Request $request)
    {
        [$from, $to, $period] = $this->period($request);
        $dashboard = $this->scores->dashboard($request->user(), $from, $to);
        $html = view('pdf.performance', compact('dashboard', 'period'))->render();
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L', 'directionality' => 'rtl',
            'default_font' => 'xbriyaz', 'tempDir' => storage_path('app/mpdf'),
        ]);
        $pdf->WriteHTML($html);

        return response($pdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="performance-'.$from->toDateString().'.pdf"',
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: string} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:week,month,quarter,custom'],
            'reference' => ['nullable', 'date'],
            'from' => ['nullable', 'required_if:period,custom', 'date'],
            'to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:from'],
        ]);
        $period = $data['period'] ?? 'month';
        $reference = CarbonImmutable::parse($data['reference'] ?? now())->startOfDay();
        [$from, $to] = match ($period) {
            'week' => [$reference->startOfWeek(Carbon::SUNDAY), $reference->endOfWeek(Carbon::SATURDAY)],
            'quarter' => [$reference->startOfQuarter(), $reference->endOfQuarter()],
            'custom' => [CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()],
            default => [$reference->startOfMonth(), $reference->endOfMonth()],
        };
        abort_if($from->diffInDays($to) > 366, 422, 'الفترة المخصصة لا يمكن أن تتجاوز سنة.');

        return [$from, $to, $period, $reference->toDateString()];
    }
}
