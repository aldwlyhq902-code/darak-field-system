<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdditionalWorkApproval;
use App\Models\Visit;
use App\Services\AdditionalWorkService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdditionalWorkApprovalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly AdditionalWorkService $additionalWork) {}

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $visit->loadMissing('workOrder');
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'description' => ['required', 'string', 'max:1500'], 'amount' => ['required', 'numeric', 'min:0']]);
        $this->additionalWork->create($visit, $data['title'], $data['description'], [], $request->user()->id, 'panel', (float) $data['amount']);

        return back()->with('ok', 'أُرسل طلب اعتماد العمل الإضافي للعميل.');
    }

    public function show(Request $request, AdditionalWorkApproval $approval): View
    {
        abort_unless($approval->client_id === $request->user('client')->client_id, 404);
        $approval->loadMissing('visit');
        abort_unless($request->user('client')->canAccessSite($approval->visit->site_id), 403);

        return view('client.additional-work', ['approval' => $approval->load(['visit.site', 'visit.workOrder'])]);
    }

    public function respond(Request $request, AdditionalWorkApproval $approval): RedirectResponse
    {
        abort_unless($approval->client_id === $request->user('client')->client_id, 404);
        abort_unless($request->user('client')->canPortal('additional-work.approve'), 403);
        $approval->loadMissing('visit');
        abort_unless($request->user('client')->canAccessSite($approval->visit->site_id), 403);
        abort_unless($approval->status === 'pending', 422);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'response_note' => ['nullable', 'string', 'max:1000']]);
        $approval->forceFill(['status' => $data['decision'], 'response_note' => $data['response_note'] ?? null, 'responded_at' => now(), 'responded_by_portal_user_id' => $request->user('client')->id])->save();
        $this->audit->record('additional_work.'.$data['decision'], $approval, null, ['client_portal_user_id' => $request->user('client')->id], null);

        return redirect()->route('client.home')->with('ok', $data['decision'] === 'approved' ? 'تم اعتماد العمل الإضافي.' : 'تم رفض العمل الإضافي.');
    }
}
