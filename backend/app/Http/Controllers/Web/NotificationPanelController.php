<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationMessage;
use App\Models\OperatingBranch;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationPanelController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): View
    {
        return view('panel.notifications', [
            'queued' => $this->visibleQuery($request, NotificationMessage::with(['user', 'visit.site.client'])->where('status', 'queued'))->orderBy('id')->limit(100)->get(),
            'dead' => $this->visibleQuery($request, NotificationMessage::with(['user', 'visit'])->where('status', 'dead'))->latest('id')->limit(100)->get(),
            'sent' => $this->visibleQuery($request, NotificationMessage::with(['user', 'visit'])->where('status', 'sent'))->latest('sent_at')->limit(25)->get(),
        ]);
    }

    /** Runs the queued in-app messages. Manual WhatsApp ones stay for a human. */
    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $delivered = 0;
        $waiting = 0;

        foreach ($this->notifications->due() as $message) {
            try {
                $result = $this->notifications->deliver($message);
                $result === 'sent' ? $delivered++ : $waiting++;
            } catch (\Throwable $e) {
                $this->notifications->recordFailure($message, $e->getMessage());
            }
        }

        return back()->with('ok', "أُرسلت {$delivered} رسالة · {$waiting} بانتظار إرسال يدوي.");
    }

    public function markSent(Request $request, NotificationMessage $message): RedirectResponse
    {
        $this->authorizeMessage($request, $message);
        $this->notifications->markSentByHuman($message);

        return back()->with('ok', 'وُسمت الرسالة كمُرسلة.');
    }

    public function retry(Request $request, NotificationMessage $message): RedirectResponse
    {
        $this->authorizeMessage($request, $message);
        $message->forceFill(['status' => 'queued', 'available_at' => now(), 'last_error' => null])->save();

        return back()->with('ok', 'أُعيدت الرسالة إلى الطابور.');
    }

    private function visibleQuery(Request $request, Builder $query): Builder
    {
        $actor = $request->user();
        if ($actor->isPlatformAdmin()) {
            return $query;
        }
        abort_if($actor->operating_company_id === null, 403);
        $branchIds = OperatingBranch::query()
            ->where('operating_company_id', $actor->operating_company_id)
            ->when($actor->operating_branch_id, fn ($branches, $branchId) => $branches->whereKey($branchId))
            ->pluck('id');

        return $query->where(function (Builder $visible) use ($actor, $branchIds): void {
            $visible->whereHas('user', fn ($user) => $user
                ->where('operating_company_id', $actor->operating_company_id)
                ->when($actor->operating_branch_id, fn ($users, $branchId) => $users->where('operating_branch_id', $branchId)))
                ->orWhereHas('visit')
                ->orWhereIn('context->operating_branch_id', $branchIds);
        });
    }

    private function authorizeMessage(Request $request, NotificationMessage $message): void
    {
        abort_unless($this->visibleQuery($request, NotificationMessage::query())->whereKey($message->id)->exists(), 404);
    }
}
