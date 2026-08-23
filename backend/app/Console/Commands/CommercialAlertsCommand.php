<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CommercialAlertsCommand extends Command
{
    protected $signature = 'darak:commercial-alerts';

    protected $description = 'Queue installment and contract-expiry alerts.';

    public function handle(NotificationService $notifications): int
    {
        $installments = ContractInstallment::with('contract.client.portalUsers')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereDate('due_on', '<=', now()->addDays(7))->get();
        foreach ($installments as $installment) {
            if ($installment->due_on->isPast() && $installment->status !== 'overdue') {
                $installment->forceFill(['status' => 'overdue'])->save();
            }
            $notifications->installmentDue($installment);
        }

        $contracts = Contract::with('client')->where('status', 'active')
            ->whereNotNull('ends_on')->whereBetween('ends_on', [now()->startOfDay(), now()->addDays(60)])->get();
        foreach ($contracts as $contract) {
            $days = now()->startOfDay()->diffInDays($contract->ends_on, false);
            if (in_array($days, [60, 30, 14, 7, 3, 1, 0], true)) {
                $notifications->contractExpiring($contract);
            }
        }

        $this->info("دفعات {$installments->count()} · عقود {$contracts->count()}.");

        return self::SUCCESS;
    }
}
