<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\VisitFeedback;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitFeedbackController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $visit->loadMissing('workOrder');
        abort_unless($visit->workOrder?->client_id === $request->user('client')->client_id, 404);
        abort_unless($visit->isClosed(), 422);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'resolution_confirmed' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1500'],
        ]);
        $isComplaint = (int) $data['rating'] <= 2 || ! (bool) $data['resolution_confirmed'];
        $feedback = DB::transaction(function () use ($visit, $data, $request, $isComplaint) {
            $lockedVisit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            abort_if($lockedVisit->feedback()->exists(), 422, 'سبق إرسال تقييم لهذه الزيارة.');

            return $lockedVisit->feedback()->create($data + [
                'client_portal_user_id' => $request->user('client')->id,
                'is_complaint' => $isComplaint, 'status' => 'new',
            ]);
        });
        $this->audit->record('visit_feedback.created', $feedback, null, ['rating' => $feedback->rating, 'is_complaint' => $isComplaint, 'client_portal_user_id' => $request->user('client')->id], null);
        if ($isComplaint) {
            $this->notifications->feedbackAlert($feedback);
        }

        return back()->with('ok', 'شكرًا لك. تم حفظ تقييم الزيارة'.($isComplaint ? ' وفتح متابعة للمشرف.' : '.'));
    }

    public function review(Request $request, VisitFeedback $feedback): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:reviewed,resolved'], 'supervisor_note' => ['required', 'string', 'max:1500']]);
        $feedback->forceFill($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        $this->audit->record('visit_feedback.'.$data['status'], $feedback, null, $data, $request->user()->id);

        return back()->with('ok', 'تم تحديث متابعة تقييم العميل.');
    }
}
