<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMove;
use App\Models\Visit;
use App\Services\ReworkDetector;
use App\Services\VisitReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporting in the MVP is deliberately narrow: one Arabic PDF (the visit report)
 * and generic CSV. Building PDF *and* Excel for every screen was cut — it is a
 * horizontal cost that doubles test surface for no operational gain at this stage.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly VisitReportBuilder $builder,
        private readonly ReworkDetector $rework,
    ) {
    }

    public function visitPdf(Visit $visit): Response
    {
        $this->authorize('view', $visit);

        return response($this->builder->render($visit), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="darak-visit-' . $visit->id . '.pdf"',
        ]);
    }

    public function visitsCsv(Request $request): StreamedResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = Visit::with(['workOrder.client', 'site', 'technician'])
            ->whereBetween('scheduled_start', [$from, $to])
            ->orderBy('scheduled_start')
            ->get();

        return $this->stream('darak-visits.csv', [
            'visit_id', 'wo_number', 'client', 'site', 'technician', 'type', 'state',
            'scheduled_start', 'started_at', 'closed_at', 'on_site_minutes',
            'is_rework', 'system_flagged', 'billable',
        ], $rows->map(fn (Visit $v) => [
            $v->id,
            $v->workOrder?->wo_number,
            $v->workOrder?->client?->name,
            $v->site?->name,
            $v->technician?->name,
            $v->workOrder?->type,
            $v->state,
            $v->scheduled_start?->format('Y-m-d H:i'),
            $v->started_at?->format('Y-m-d H:i'),
            $v->closed_at?->format('Y-m-d H:i'),
            intdiv((int) $v->on_site_seconds, 60),
            $v->is_rework ? 'yes' : 'no',
            $v->rework_system_flagged ? 'yes' : 'no',
            $v->is_billable ? 'yes' : 'no',
        ])->all());
    }

    public function stockMovesCsv(Request $request): StreamedResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = StockMove::with(['part', 'fromLocation', 'toLocation', 'visit'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        return $this->stream('darak-stock-moves.csv', [
            'move_id', 'type', 'sku', 'part', 'qty', 'from', 'to', 'visit_id',
            'unit_cost', 'device_timestamp', 'server_received_at',
        ], $rows->map(fn (StockMove $m) => [
            $m->id,
            $m->move_type,
            $m->part?->sku,
            $m->part?->name,
            (float) $m->qty,
            $m->fromLocation?->name,
            $m->toLocation?->name,
            $m->visit_id,
            (float) $m->unit_cost,
            $m->device_timestamp?->format('Y-m-d H:i:s'),
            $m->server_received_at?->format('Y-m-d H:i:s'),
        ])->all());
    }

    /**
     * Two figures, not one: strict first-time-fix and the figure adjusted for
     * supervisor overrides. The gap between them is a supervision signal.
     */
    public function firstTimeFix(Request $request)
    {
        $from = $request->date('from') ?? now()->subDays(90);
        $to = $request->date('to') ?? now();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'first_time_fix' => $this->rework->firstTimeFixRate($from, $to),
            'note' => 'Sector benchmarks quoted during planning are unverified. Build the baseline from the first 90 days of real data before comparing.',
        ]);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function stream(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic correctly
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
