<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OperatingBranch;
use App\Models\StockLocation;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleExpense;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenanceOrder;
use App\Services\AuditLogger;
use App\Services\FleetManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FleetController extends Controller
{
    public function __construct(private readonly FleetManagementService $fleet, private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('panel.fleet', [
            'vehicles' => Vehicle::with(['assignedUser', 'documents' => fn ($query) => $query->where('status', 'active'), 'maintenanceOrders' => fn ($query) => $query->latest('id')->limit(10), 'inspections' => fn ($query) => $query->latest('inspected_at')->limit(5)])->withSum('expenses', 'amount')->orderBy('plate')->get(),
            'expiringDocuments' => VehicleDocument::with('vehicle')->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(90))->orderBy('expires_on')->get(),
            'openOrders' => VehicleMaintenanceOrder::with('vehicle')->whereIn('status', ['open', 'approved', 'in_progress'])->latest('id')->get(),
            'recentInspections' => VehicleInspection::with(['vehicle', 'inspector'])->latest('inspected_at')->limit(30)->get(),
            'branches' => OperatingBranch::with('company')->where('is_active', true)
                ->when($request->user()->operating_branch_id, fn ($query, $branchId) => $query->whereKey($branchId))
                ->orderBy('name')->get(),
        ]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $data = $this->vehicleData($request, null);
        $data['operating_branch_id'] = $this->resolvedBranch($request, $data['operating_branch_id'] ?? null);
        $vehicle = DB::transaction(function () use ($data): Vehicle {
            $vehicle = Vehicle::create($data + ['is_active' => true, 'operational_status' => 'available']);
            StockLocation::create(['type' => StockLocation::TYPE_VEHICLE, 'name' => 'سيارة '.$vehicle->plate, 'vehicle_id' => $vehicle->id, 'operating_branch_id' => $vehicle->operating_branch_id, 'is_active' => true]);

            return $vehicle;
        });
        $this->audit->record('fleet.vehicle_created', $vehicle, null, $vehicle->getAttributes(), $request->user()->id);

        return back()->with('ok', 'أُضيفت السيارة ومستودعها المتنقل.');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $this->vehicleData($request, $vehicle);
        $data['operating_branch_id'] = $this->resolvedBranch($request, $data['operating_branch_id'] ?? $vehicle->operating_branch_id);
        $before = $vehicle->getAttributes();
        $vehicle->fill($data)->save();
        $vehicle->stockLocation?->forceFill(['operating_branch_id' => $vehicle->operating_branch_id])->save();
        $this->audit->recordChange('fleet.vehicle_updated', $vehicle, $before, $request->user()->id);

        return back()->with('ok', 'حُفظت بيانات السيارة وخطة صيانتها.');
    }

    public function document(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:registration,insurance,inspection,operation_card,authorization,other'],
            'document_number' => ['nullable', 'string', 'max:96'], 'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'], 'provider' => ['nullable', 'string', 'max:190'],
            'coverage_type' => ['nullable', 'string', 'max:96'], 'insured_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'], 'file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $file = $request->file('file');
        $path = $file?->store('vehicle-documents/'.$vehicle->id, 'local');
        unset($data['file']);
        try {
            VehicleDocument::where('vehicle_id', $vehicle->id)->where('type', $data['type'])->where('status', 'active')->update(['status' => 'superseded']);
            $document = VehicleDocument::create($data + [
                'vehicle_id' => $vehicle->id, 'operating_branch_id' => $vehicle->operating_branch_id,
                'file_path' => $path ?: null, 'file_name' => $file?->getClientOriginalName(),
                'mime_type' => $file?->getMimeType(), 'file_size' => $file?->getSize(), 'status' => 'active', 'created_by' => $request->user()->id,
            ]);
            $this->audit->record('fleet.document_added', $document, null, $document->only(['vehicle_id', 'type', 'expires_on']), $request->user()->id);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('ok', 'حُفظت وثيقة السيارة وأُرشف الإصدار السابق.');
    }

    public function download(VehicleDocument $document): StreamedResponse
    {
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name ?? basename($document->file_path), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function maintenance(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:preventive,repair,accident,tires,oil,other'], 'priority' => ['required', 'in:low,normal,high,urgent'],
            'description' => ['required', 'string', 'max:3000'], 'vendor_name' => ['nullable', 'string', 'max:190'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'opened_on' => ['required', 'date'],
            'scheduled_for' => ['nullable', 'date', 'after_or_equal:opened_on'], 'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'causes_outage' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = VehicleMaintenanceOrder::create($data + [
            'order_no' => 'VMO-'.now()->format('ymd').'-'.strtoupper(Str::random(8)),
            'vehicle_id' => $vehicle->id, 'operating_branch_id' => $vehicle->operating_branch_id,
            'status' => 'open', 'estimated_cost' => $data['estimated_cost'] ?? 0,
            'causes_outage' => $request->boolean('causes_outage'), 'created_by' => $request->user()->id,
        ]);
        $this->audit->record('fleet.maintenance_opened', $order, null, $order->getAttributes(), $request->user()->id);

        return back()->with('ok', "فُتح أمر الصيانة {$order->order_no}.");
    }

    public function maintenanceTransition(Request $request, VehicleMaintenanceOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,in_progress,completed,cancelled'],
            'vendor_name' => ['nullable', 'string', 'max:190'], 'quote_reference' => ['nullable', 'string', 'max:190'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'completed_on' => ['nullable', 'date'], 'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'next_service_on' => ['nullable', 'date'], 'next_service_odometer_km' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['status'] === 'completed' && ! array_key_exists('actual_cost', $data)) {
            return back()->with('err', 'يجب إدخال التكلفة الفعلية عند إكمال أمر الصيانة.');
        }
        $before = $order->getAttributes();
        try {
            $reassigned = $this->fleet->transition($order, $data['status'], $data, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('err', $exception->getMessage());
        }
        $this->audit->recordChange('fleet.maintenance_transitioned', $order->refresh(), $before, $request->user()->id);

        return back()->with('ok', "حُدث أمر الصيانة، وأُعيد توزيع {$reassigned} زيارة متأثرة.");
    }

    public function inspection(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'odometer_km' => ['required', 'numeric', 'min:0'], 'tires' => ['nullable', 'boolean'],
            'brakes' => ['nullable', 'boolean'], 'lights' => ['nullable', 'boolean'], 'fluids' => ['nullable', 'boolean'],
            'body' => ['nullable', 'boolean'], 'cleanliness' => ['nullable', 'boolean'],
            'defects' => ['nullable', 'string', 'max:3000'], 'photo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $checklist = collect(['tires', 'brakes', 'lights', 'fluids', 'body', 'cleanliness'])->mapWithKeys(fn (string $item) => [$item => $request->boolean($item)])->all();
        $file = $request->file('photo');
        $path = $file?->store('vehicle-inspections/'.$vehicle->id, 'local');
        try {
            $result = $this->fleet->recordInspection($vehicle, $request->user(), (float) $data['odometer_km'], $checklist, $data['defects'] ?? null, [
                'photo_path' => $path ?: null, 'photo_name' => $file?->getClientOriginalName(),
                'photo_mime_type' => $file?->getMimeType(), 'photo_size' => $file?->getSize(),
            ]);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $this->audit->record('fleet.inspection_recorded', $result['inspection'], null, $result['inspection']->only(['vehicle_id', 'odometer_km', 'is_roadworthy']), $request->user()->id);

        return back()->with('ok', $result['inspection']->is_roadworthy ? 'حُفظ الفحص والسيارة صالحة للتشغيل.' : "فشل فحص السلامة، أُوقفت السيارة وأُعيد توزيع {$result['reassigned']} زيارة.");
    }

    public function inspectionPhoto(VehicleInspection $inspection): StreamedResponse
    {
        abort_unless($inspection->photo_path && Storage::disk('local')->exists($inspection->photo_path), 404);

        return Storage::disk('local')->response($inspection->photo_path, $inspection->photo_name, ['Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function expense(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:fuel,maintenance,fine,insurance,registration,inspection,other'],
            'amount' => ['required', 'numeric', 'gt:0'], 'incurred_on' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'], 'reference' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $expense = VehicleExpense::create($data + ['vehicle_id' => $vehicle->id, 'recorded_by' => $request->user()->id]);
        $this->audit->record('fleet.expense_recorded', $expense, null, $expense->getAttributes(), $request->user()->id);

        return back()->with('ok', 'سُجل مصروف السيارة.');
    }

    private function vehicleData(Request $request, ?Vehicle $vehicle): array
    {
        $plateRule = Rule::unique('vehicles', 'plate');
        $vinRule = Rule::unique('vehicles', 'vin');
        if ($vehicle) {
            $plateRule->ignore($vehicle->id);
            $vinRule->ignore($vehicle->id);
        }

        return $request->validate([
            'plate' => ['required', 'string', 'max:32', $plateRule],
            'internal_code' => ['nullable', 'string', 'max:32'], 'make' => ['nullable', 'string', 'max:96'],
            'model' => ['nullable', 'string', 'max:96'], 'year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'vin' => ['nullable', 'string', 'max:64', $vinRule],
            'assigned_user_id' => ['nullable', 'exists:users,id'], 'operating_branch_id' => ['nullable', 'exists:operating_branches,id'],
            'current_odometer_km' => ['nullable', 'numeric', 'min:0'], 'last_service_on' => ['nullable', 'date'],
            'next_service_on' => ['nullable', 'date'], 'next_service_odometer_km' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function resolvedBranch(Request $request, ?int $requested): ?int
    {
        return $request->user()->isOwner() ? $requested : $request->user()->operating_branch_id;
    }
}
