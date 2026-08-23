<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Quotation;
use Illuminate\Support\Str;

class ContractRenewalService
{
    public function generateOffers(int $daysAhead = 45): int
    {
        $count = 0;
        Contract::with('sites')->where('status', 'active')->where('auto_renewal_offer', true)
            ->whereNotNull('ends_on')->whereBetween('ends_on', [today(), today()->addDays($daysAhead)])
            ->chunkById(100, function ($contracts) use (&$count) {
                foreach ($contracts as $contract) {
                    if (Quotation::where('renews_contract_id', $contract->id)->whereNull('superseded_at')->exists()) {
                        continue;
                    }
                    $series = (string) Str::uuid();
                    $quote = Quotation::create([
                        'series_uuid' => $series, 'version' => 1,
                        'quote_no' => 'QR-'.now()->format('ym').'-'.$contract->id.'-V1',
                        'client_id' => $contract->client_id, 'title' => 'تجديد العقد '.$contract->contract_no,
                        'package_code' => $contract->package_code, 'price_amount' => $contract->price_amount,
                        'vat_rate' => $contract->vat_rate, 'billing_cycle' => $contract->billing_cycle,
                        'duration_months' => 12, 'starts_on' => $contract->ends_on->copy()->addDay(),
                        'valid_until' => $contract->ends_on, 'service_window_start' => $contract->service_window_start,
                        'service_window_end' => $contract->service_window_end, 'sla_minutes' => $contract->sla_minutes,
                        'terms' => $contract->exclusions, 'status' => Quotation::STATUS_SENT, 'sent_at' => now(),
                        'renews_contract_id' => $contract->id, 'auto_generated' => true,
                    ]);
                    $quote->sites()->sync($contract->sites->pluck('id'));
                    $count++;
                }
            });

        return $count;
    }
}
