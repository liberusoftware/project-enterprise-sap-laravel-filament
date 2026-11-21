<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Actions\CreateOpenItem;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableOpenItem;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Enums\RecordStatus;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\ValueObjects\Money;
use Liberu\Foundation\Organizations\Models\Team;

final readonly class RecordBillingReceivable
{
    public function __construct(
        private CreateOpenItem $createOpenItem,
        private CurrencyRegistry $currencies,
    ) {}

    public function handle(User $actor, Team $team, int $invoiceId, int $partyId): ReceivableOpenItem
    {
        return DB::transaction(function () use ($actor, $team, $invoiceId, $partyId): ReceivableOpenItem {
            $team = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($actor->ownsTeam($team), 403);

            $invoice = Invoice::query()->where('team_id', $team->getKey())->lockForUpdate()->findOrFail($invoiceId);
            $entityId = DB::table('enterprise_company_setups')->where('team_id', $team->getKey())->value('legal_entity_id');
            $entity = LegalEntity::query()->where('is_active', true)->whereKey($entityId)->firstOrFail();
            $party = Party::query()->where('legal_entity_id', $entity->getKey())
                ->where('type', PartyType::Customer)->lockForUpdate()->findOrFail($partyId);

            $existing = ReceivableOpenItem::query()->where('source_type', Invoice::class)
                ->where('source_id', (string) $invoiceId)->lockForUpdate()->first();

            if ($existing instanceof ReceivableOpenItem) {
                if ((int) $existing->getAttribute('party_id') !== $partyId) {
                    throw ValidationException::withMessages(['customer' => 'This invoice is already linked to a different customer.']);
                }

                return $existing;
            }

            if (! in_array($invoice->getAttribute('status'), [InvoiceStatus::Finalized, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true) || $invoice->getAttribute('finalized_at') === null
                || $party->status !== RecordStatus::Active) {
                throw ValidationException::withMessages(['invoice' => 'A finalized invoice and an active accounting customer are required.']);
            }

            $currency = $this->currencies->get((string) $entity->getAttribute('currency_code'));

            $totalMinor = $invoice->getAttribute('total_minor');

            // Accounting stores two decimals and currently calculates receivable balances using floats.
            if ($invoice->getAttribute('currency') !== $currency->code || $currency->minorUnits > 2
                || ! is_int($totalMinor) || $totalMinor <= 0 || $totalMinor > 99_999_999_999_999) {
                throw ValidationException::withMessages(['invoice' => 'The invoice must have a positive, supported company-currency amount.']);
            }

            $number = trim((string) $invoice->getAttribute('number'));

            return $this->createOpenItem->handle([
                'party_id' => $partyId,
                'source_type' => Invoice::class,
                'source_id' => (string) $invoiceId,
                'reference' => $number !== '' && mb_strlen($number) <= 128 ? $number : 'Billing invoice '.$invoiceId,
                'issued_on' => $invoice->getAttribute('finalized_at'),
                'due_on' => $invoice->getAttribute('due_at'),
                'original_amount' => (new Money($totalMinor, $currency))->decimal(),
                'currency' => $currency->code,
                'metadata' => [
                    'billing_invoice_number' => $invoice->getAttribute('number'),
                    'recorded_by' => $actor->getKey(),
                    'team_id' => $team->getKey(),
                ],
            ]);
        }, attempts: 3);
    }
}
