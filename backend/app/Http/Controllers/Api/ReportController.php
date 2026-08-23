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
    ) {}

    public function visitPdf(Visit $visit): Response
    {
        $this->authorize('view', $visit);

        return response($this->builder->render($visit), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="darak-visit-'.$visit->id.'.pdf"',
        ]);
    }

    public function visitsCsv(Request $request): StreamedResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = Visit::query()
            ->leftJoin('work_orders', 'work_orders.id', '=', 'visits.work_order_id')
            ->leftJoin('clients', 'clients.id', '=', 'work_orders.client_id')
            ->leftJoin('sites', 'sites.id', '=', 'visits.site_id')
            ->leftJoin('users', 'users.id', '=', 'visits.assigned_user_id')
            ->select('visits.*')
            ->addSelect([
                'work_orders.wo_number as export_wo_number',
                'work_orders.type as export_work_order_type',
                'clients.name as export_client_name',
                'sites.name as export_site_name',
                'users.name as export_technician_name',
            ])
            ->whereBetween('visits.scheduled_start', [$from, $to])
            ->orderBy('visits.scheduled_start');

        return $this->stream('darak-visits.csv', [
            'visit_id', 'wo_number', 'client', 'site', 'technician', 'type', 'state',
            'scheduled_start', 'started_at', 'closed_at', 'on_site_minutes',
            'is_rework', 'system_flagged', 'billable',
        ], fn () => $rows->cursor()->map(fn (Visit $v) => [
            $v->id,
            $v->export_wo_number,
            $v->export_client_name,
            $v->export_site_name,
            $v->export_technician_name,
            $v->export_work_order_type,
            $v->state,
            $v->scheduled_start?->format('Y-m-d H:i'),
            $v->started_at?->format('Y-m-d H:i'),
            $v->closed_at?->format('Y-m-d H:i'),
            intdiv((int) $v->on_site_seconds, 60),
            $v->is_rework ? 'yes' : 'no',
            $v->rework_system_flagged ? 'yes' : 'no',
            $v->is_billable ? 'yes' : 'no',
        ]));
    }

    public function stockMovesCsv(Request $request): StreamedResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = StockMove::query()
            ->leftJoin('parts', 'parts.id', '=', 'stock_moves.part_id')
            ->leftJoin('stock_locations as from_locations', 'from_locations.id', '=', 'stock_moves.from_location_id')
            ->leftJoin('stock_locations as to_locations', 'to_locations.id', '=', 'stock_moves.to_location_id')
            ->select('stock_moves.*')
            ->addSelect([
                'parts.sku as export_part_sku',
                'parts.name as export_part_name',
                'from_locations.name as export_from_name',
                'to_locations.name as export_to_name',
            ])
            ->whereBetween('stock_moves.created_at', [$from, $to])
            ->orderBy('stock_moves.created_at');

        return $this->stream('darak-stock-moves.csv', [
            'move_id', 'type', 'sku', 'part', 'qty', 'from', 'to', 'visit_id',
            'unit_cost', 'device_timestamp', 'server_received_at',
        ], fn () => $rows->cursor()->map(fn (StockMove $m) => [
            $m->id,
            $m->move_type,
            $m->export_part_sku,
            $m->export_part_name,
            (float) $m->qty,
            $m->export_from_name,
            $m->export_to_name,
            $m->visit_id,
            (float) $m->unit_cost,
            $m->device_timestamp?->format('Y-m-d H:i:s'),
            $m->server_received_at?->format('Y-m-d H:i:s'),
        ]));
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

    /** @param callable(): iterable<int, array<int, mixed>> $rows */
    private function stream(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic correctly
            fputcsv($out, $header);

            foreach ($rows() as $row) {
                fputcsv($out, array_map($this->safeCsvCell(...), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
