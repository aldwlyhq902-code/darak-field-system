<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\NotificationMessage;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationPanelController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(): View
    {
        return view('panel.notifications', [
            'queued' => NotificationMessage::with(['user', 'visit.site.client'])
                ->where('status', 'queued')->orderBy('id')->get(),
            'dead' => NotificationMessage::with(['user', 'visit'])
                ->where('status', 'dead')->latest('id')->get(),
            'sent' => NotificationMessage::with(['user', 'visit'])
                ->where('status', 'sent')->latest('sent_at')->limit(25)->get(),
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

    public function markSent(NotificationMessage $message): RedirectResponse
    {
        $this->notifications->markSentByHuman($message);

        return back()->with('ok', 'وُسمت الرسالة كمُرسلة.');
    }

    public function retry(NotificationMessage $message): RedirectResponse
    {
        $message->forceFill(['status' => 'queued', 'available_at' => now(), 'last_error' => null])->save();

        return back()->with('ok', 'أُعيدت الرسالة إلى الطابور.');
    }
}
