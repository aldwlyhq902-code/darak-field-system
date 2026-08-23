<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\FleetManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FleetController extends Controller
{
    public function __construct(private readonly FleetManagementService $fleet, private readonly AuditLogger $audit) {}

    public function current(Request $request): JsonResponse
    {
        $vehicle = Vehicle::withoutGlobalScopes()->with(['documents' => fn ($query) => $query->where('status', 'active')->orderBy('expires_on'), 'inspections' => fn ($query) => $query->latest('inspected_at')->limit(5)])
            ->where('assigned_user_id', $request->user()->id)->where('is_active', true)->first();

        return response()->json(['data' => $vehicle]);
    }

    public function inspection(Request $request): JsonResponse
    {
        $vehicle = Vehicle::withoutGlobalScopes()->where('assigned_user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
        $data = $request->validate([
            'odometer_km' => ['required', 'numeric', 'min:0'],
            'tires' => ['required', 'boolean'], 'brakes' => ['required', 'boolean'],
            'lights' => ['required', 'boolean'], 'fluids' => ['required', 'boolean'],
            'body' => ['required', 'boolean'], 'cleanliness' => ['required', 'boolean'],
            'defects' => ['nullable', 'string', 'max:3000'],
            'photo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $file = $request->file('photo');
        $path = $file?->store('vehicle-inspections/'.$vehicle->id, 'local');
        try {
            $result = $this->fleet->recordInspection(
                $vehicle, $request->user(), (float) $data['odometer_km'],
                collect(['tires', 'brakes', 'lights', 'fluids', 'body', 'cleanliness'])->mapWithKeys(fn (string $key) => [$key => $request->boolean($key)])->all(),
                $data['defects'] ?? null,
                [
                    'photo_path' => $path ?: null, 'photo_name' => $file?->getClientOriginalName(),
                    'photo_mime_type' => $file?->getMimeType(), 'photo_size' => $file?->getSize(),
                ],
            );
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $inspection = $result['inspection'];
        $this->audit->record('fleet.inspection_recorded_mobile', $inspection, null, $inspection->only(['vehicle_id', 'odometer_km', 'is_roadworthy']), $request->user()->id);

        return response()->json([
            'data' => $inspection,
            'message' => $inspection->is_roadworthy ? 'حُفظ الفحص والسيارة صالحة.' : 'فشل فحص السلامة وأُوقفت السيارة.',
            'reassigned_visits' => $result['reassigned'],
        ], 201);
    }
}
