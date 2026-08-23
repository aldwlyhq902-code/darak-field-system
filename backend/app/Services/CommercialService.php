<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Support\BusinessReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommercialService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function convertQuotation(Quotation $quotation, int $actorId): Contract
    {
        return DB::transaction(function () use ($quotation, $actorId): Contract {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->converted_contract_id !== null) {
                return $quotation->convertedContract;
            }

            if (! in_array($quotation->status, [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED], true)) {
                throw new RuntimeException('يجب إرسال العرض أو اعتماده قبل تحويله إلى عقد.');
            }

            if ($quotation->valid_until->isPast() && $quotation->status !== Quotation::STATUS_ACCEPTED) {
                throw new RuntimeException('انتهت صلاحية العرض ولم يعتمد العميل هذا الإصدار.');
            }

            $contract = Contract::create([
                'client_id' => $quotation->client_id,
                'contract_no' => BusinessReference::make('DK', false),
                'package_code' => $quotation->package_code,
                'price_amount' => $quotation->price_amount,
                'vat_rate' => $quotation->vat_rate,
                'billing_cycle' => $quotation->billing_cycle,
                'starts_on' => $quotation->starts_on,
                'ends_on' => $quotation->starts_on->copy()->addMonths($quotation->duration_months)->subDay(),
                'service_window_start' => $quotation->service_window_start,
                'service_window_end' => $quotation->service_window_end,
                'sla_minutes' => $quotation->sla_minutes,
                'exclusions' => $quotation->terms,
                'status' => 'active',
                'source_quotation_id' => $quotation->id,
            ]);
            $contract->sites()->sync($quotation->sites()->pluck('sites.id'));
            $this->generateInstallments($contract);

            $quotation->forceFill([
                'converted_contract_id' => $contract->id,
                'status' => Quotation::STATUS_CONVERTED,
            ])->save();

            $this->audit->record('quotation.converted', $quotation, null, [
                'contract_id' => $contract->id, 'actor_id' => $actorId,
            ]);

            return $contract;
        });
    }

    public function generateInstallments(Contract $contract): void
    {
        if ($contract->installments()->exists()) {
            return;
        }

        $quotation = $contract->source_quotation_id ? Quotation::find($contract->source_quotation_id) : null;
        $number = 1;
        if ($quotation && (float) $quotation->down_payment_amount > 0) {
            $this->createInstallment($contract, $number++, $contract->starts_on, (float) $quotation->down_payment_amount);
        }

        if ($quotation && $quotation->billing_cycle === 'custom' && ($quotation->custom_installments ?? []) !== []) {
            foreach ($quotation->custom_installments as $row) {
                $this->createInstallment($contract, $number++, CarbonImmutable::parse($row['due_on']), (float) $row['amount']);
            }

            return;
        }

        if ($contract->billing_cycle === 'upfront') {
            $this->createInstallment($contract, $number, $contract->starts_on, (float) $contract->price_amount);

            return;
        }

        $months = max(1, $contract->starts_on->diffInMonths($contract->ends_on?->copy()->addDay() ?? $contract->starts_on->copy()->addYear()));
        $stepMonths = $contract->billing_cycle === 'quarterly' ? 3 : 1;
        $count = (int) ceil($months / $stepMonths);

        for ($cycle = 0; $cycle < $count; $cycle++) {
            $amount = round((float) $contract->price_amount * $stepMonths, 2);
            $this->createInstallment($contract, $number++, $contract->starts_on->copy()->addMonths($cycle * $stepMonths), $amount);
        }
    }

    private function createInstallment(Contract $contract, int $number, \DateTimeInterface $dueOn, float $amount): void
    {
        $amount = round($amount, 2);
        $vat = round($amount * (float) $contract->vat_rate, 2);
        ContractInstallment::create([
            'contract_id' => $contract->id, 'installment_no' => $number,
            'due_on' => $dueOn, 'amount' => $amount, 'vat_amount' => $vat,
            'total_amount' => $amount + $vat, 'status' => 'pending',
        ]);
    }

    public function recordPayment(ContractInstallment $installment, array $data, int $actorId): Payment
    {
        return DB::transaction(function () use ($installment, $data, $actorId): Payment {
            $installment = ContractInstallment::query()->lockForUpdate()->findOrFail($installment->id);
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0 || $amount > $installment->remaining()) {
                throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي على الدفعة.');
            }

            $payment = Payment::create([
                'receipt_no' => BusinessReference::make('RCPT'),
                'contract_installment_id' => $installment->id,
                'client_id' => $installment->contract->client_id,
                'amount' => $amount,
                'paid_on' => $data['paid_on'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actorId,
            ]);

            $paid = round((float) $installment->paid_amount + $amount, 2);
            $complete = $paid >= (float) $installment->total_amount - 0.005;
            $installment->forceFill([
                'paid_amount' => $paid,
                'status' => $complete ? 'paid' : 'partial',
                'paid_at' => $complete ? CarbonImmutable::now() : null,
            ])->save();

            $this->audit->record('payment.recorded', $payment, null, [
                'installment_id' => $installment->id, 'amount' => $amount,
            ]);

            return $payment;
        });
    }
}
