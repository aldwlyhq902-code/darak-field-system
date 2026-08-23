<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationMessage;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class NotificationPanelController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): View
    {
        return view('panel.notifications', [
            'queued' => $this->visibleTo($request, NotificationMessage::with(['user', 'visit.site.client'])->where('status', 'queued')->orderBy('id')->get()),
            'dead' => $this->visibleTo($request, NotificationMessage::with(['user', 'visit'])->where('status', 'dead')->latest('id')->get()),
            'sent' => $this->visibleTo($request, NotificationMessage::with(['user', 'visit'])->where('status', 'sent')->latest('sent_at')->limit(100)->get())->take(25),
        ]);
    }

    /** Runs the queued in-app messages. Manual WhatsApp ones stay for a human. */
    public function run(): RedirectResponse
    {
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

    /** @param Collection<int, NotificationMessage> $messages */
    private function visibleTo(Request $request, Collection $messages): Collection
    {
        if ($request->user()->isOwner() || $request->user()->operating_branch_id === null) {
            return $messages;
        }
        $branchId = $request->user()->operating_branch_id;

        return $messages->filter(function (NotificationMessage $message) use ($branchId): bool {
            $contextBranch = $message->context['operating_branch_id'] ?? null;

            return $contextBranch === null || (int) $contextBranch === (int) $branchId;
        })->values();
    }

    private function authorizeMessage(Request $request, NotificationMessage $message): void
    {
        $contextBranch = $message->context['operating_branch_id'] ?? null;
        abort_unless(
            $request->user()->isOwner() || $request->user()->operating_branch_id === null
            || $contextBranch === null || (int) $contextBranch === (int) $request->user()->operating_branch_id,
            403,
        );
    }
}
