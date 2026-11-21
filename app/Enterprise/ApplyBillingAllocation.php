<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Actions\ApplyReceipt;
use Liberu\Accounting\AccountsReceivable\Actions\RecordReceipt;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableOpenItem;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableReceipt;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Payments\Enums\PaymentStatus;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Models\PaymentAllocation;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\ValueObjects\Money;
use Liberu\Foundation\Organizations\Models\Team;

final readonly class ApplyBillingAllocation
{
    public function __construct(
        private RecordReceipt $recordReceipt,
        private ApplyReceipt $applyReceipt,
        private CurrencyRegistry $currencies,
    ) {}

    public function handle(User $actor, Team $team, int $allocationId): ReceivableReceipt
    {
        return DB::transaction(function () use ($actor, $team, $allocationId): ReceivableReceipt {
            $team = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($actor->ownsTeam($team), 403);

            $allocation = PaymentAllocation::query()->findOrFail($allocationId);
            $payment = Payment::query()->where('team_id', $team->getKey())
                ->whereKey($allocation->getAttribute('payment_id'))->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->where('team_id', $team->getKey())
                ->whereKey($allocation->getAttribute('invoice_id'))->lockForUpdate()->firstOrFail();

            $receiptId = DB::table('enterprise_billing_receipt_links')->where('allocation_id', $allocationId)->value('receipt_id');
            if ($receiptId !== null) {
                return ReceivableReceipt::query()->whereKey($receiptId)->firstOrFail();
            }

            $entityId = DB::table('enterprise_company_setups')->where('team_id', $team->getKey())->value('legal_entity_id');
            $item = ReceivableOpenItem::query()->where('source_type', Invoice::class)
                ->whereRelation('party', 'legal_entity_id', $entityId)
                ->where('source_id', (string) $invoice->getKey())->lockForUpdate()->firstOrFail();
            $currency = $this->currencies->get((string) $item->getAttribute('currency'));
            $minor = $allocation->getAttribute('amount_minor');
            $capturedAt = $payment->getAttribute('captured_at');

            if (! in_array($invoice->getAttribute('status'), [InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)
                || $payment->getAttribute('status') !== PaymentStatus::Captured || $capturedAt === null
                || $payment->getAttribute('refunded_minor') !== 0 || ! is_int($minor) || $minor <= 0
                || $minor > 99_999_999_999_999 || $currency->minorUnits > 2
                || $allocation->getAttribute('currency') !== $currency->code
                || $payment->getAttribute('currency') !== $currency->code
                || $invoice->getAttribute('currency') !== $currency->code) {
                throw ValidationException::withMessages(['allocation' => 'A captured, unrefunded allocation in the receivable currency is required.']);
            }

            $amount = (new Money($minor, $currency))->decimal();
            $receipt = $this->recordReceipt->handle([
                'party_id' => $item->getAttribute('party_id'),
                'currency' => $currency->code,
                'amount' => $amount,
                'received_on' => $capturedAt,
                'reference' => 'Billing allocation '.$allocationId,
                'metadata' => ['billing_payment_id' => $payment->getKey(), 'recorded_by' => $actor->getKey()],
            ]);

            $receipt = $this->applyReceipt->handle($receipt, $item, (float) $amount);
            DB::table('enterprise_billing_receipt_links')->insert([
                'allocation_id' => $allocationId,
                'receipt_id' => $receipt->getKey(),
            ]);

            return $receipt;
        }, attempts: 3);
    }
}
