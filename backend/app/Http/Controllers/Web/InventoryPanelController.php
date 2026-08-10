<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\StockLocation;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InventoryPanelController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(): View
    {
        $locations = StockLocation::with('vehicle')->where('is_active', true)->get();

        return view('panel.inventory', [
            'parts' => Part::orderBy('name')->get(),
            'locations' => $locations->map(fn (StockLocation $l) => [
                'location' => $l,
                'balances' => $this->inventory->locationBalances($l->id),
            ]),
        ]);
    }

    public function storePart(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:48', 'unique:parts,sku'],
            'name' => ['required', 'string', 'max:190'],
            'unit' => ['required', 'string', 'max:16'],
            // Purchase cost is mandatory: without it no margin can be computed,
            // and a catalogue with no cost quietly disables every profit figure.
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'member_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'heat_sensitive' => ['nullable', 'boolean'],
            // Per-SKU limit from the maker's sheet. There is deliberately no
            // global threshold — the 54C figure used earlier was unproven.
            'max_storage_temp_c' => ['nullable', 'numeric'],
        ]);

        Part::create($data + [
            'heat_sensitive' => $request->boolean('heat_sensitive'),
            'qr_code' => 'PART-' . strtoupper(Str::random(8)),
        ]);

        return back()->with('ok', 'أُضيف الصنف للكتالوج.');
    }

    public function receipt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'to_location_id' => ['required', 'exists:stock_locations,id'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $this->inventory->receipt(
            (string) Str::uuid(),
            $data['part_id'],
            (float) $data['qty'],
            $data['to_location_id'],
            ['user_id' => $request->user()->id, 'note' => $data['note'] ?? null],
        );

        return back()->with('ok', 'سُجّل الاستلام.');
    }

    /**
     * Stocktake correction. The only movement allowed to write stock down to
     * reality, so it demands a reason and is fully audited.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'location_id' => ['required', 'exists:stock_locations,id'],
            'counted_qty' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:190'],
        ]);

        $current = $this->inventory->balance($data['part_id'], $data['location_id']);
        $difference = round((float) $data['counted_qty'] - $current, 3);

        if (abs($difference) < 0.0005) {
            return back()->with('ok', 'الجرد يطابق الرصيد — لا حركة مطلوبة.');
        }

        // A shortfall moves stock out; a surplus moves it in. Either way the
        // difference is recorded as its own movement, never edited into history.
        $this->inventory->adjust(
            (string) Str::uuid(),
            $data['part_id'],
            abs($difference),
            $difference < 0 ? $data['location_id'] : null,
            $difference > 0 ? $data['location_id'] : null,
            $data['reason'] . ' (جرد: عُدّ ' . $data['counted_qty'] . ' مقابل رصيد ' . $current . ')',
        );

        $direction = $difference < 0 ? 'عجز' : 'زيادة';

        return back()->with('ok', "سُجّلت تسوية الجرد: {$direction} {$difference} — والسبب في سجل التدقيق.");
    }

    public function transferToVehicle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'from_location_id' => ['required', 'exists:stock_locations,id'],
            'to_location_id' => ['required', 'exists:stock_locations,id', 'different:from_location_id'],
        ]);

        try {
            $this->inventory->loadVehicle(
                (string) Str::uuid(),
                $data['part_id'],
                (float) $data['qty'],
                $data['from_location_id'],
                $data['to_location_id'],
                ['user_id' => $request->user()->id],
            );
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'زُوّدت السيارة.');
    }
}
