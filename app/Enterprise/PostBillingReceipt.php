<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableReceipt;
use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\GeneralLedger\Actions\CreateJournal;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\Periods\Models\AccountingPeriod;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Payments\Enums\PaymentStatus;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\ValueObjects\Money;
use Liberu\Foundation\Organizations\Models\Team;

final readonly class PostBillingReceipt
{
    public function __construct(
        private CreateJournal $createJournal,
        private PostJournal $postJournal,
        private CurrencyRegistry $currencies,
    ) {}

    public function handle(User $actor, Team $team, int $allocationId, int $cashAccountId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $team, $allocationId, $cashAccountId): JournalEntry {
            $team = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($actor->ownsTeam($team), 403);

            $source = DB::table('enterprise_billing_receipt_links as links')
                ->join('billing_payment_allocations as allocations', 'allocations.id', '=', 'links.allocation_id')
                ->join('billing_invoices as invoices', 'invoices.id', '=', 'allocations.invoice_id')
                ->where('invoices.team_id', $team->getKey())
                ->where('links.allocation_id', $allocationId)
                ->first(['links.receipt_id', 'allocations.invoice_id', 'allocations.payment_id', 'allocations.amount_minor', 'allocations.currency']);
            if ($source === null) {
                throw ValidationException::withMessages(['allocation' => 'Apply the allocation to a receivable in this company first.']);
            }

            $payment = Payment::query()->where('team_id', $team->getKey())->whereKey($source->payment_id)->lockForUpdate()->firstOrFail();
            $receipt = ReceivableReceipt::query()->whereKey($source->receipt_id)->lockForUpdate()->firstOrFail();
            $entity = DB::table('enterprise_company_setups')->where('team_id', $team->getKey())->value('legal_entity_id');
            $invoiceJournal = JournalEntry::query()->whereHas('book', fn ($query) => $query
                ->where('legal_entity_id', $entity)->where('code', 'PRIMARY')->where('is_active', true)
                ->whereRelation('legalEntity', 'is_active', true))
                ->where('source_type', Invoice::class)->where('source_id', (string) $source->invoice_id)
                ->lockForUpdate()->firstOrFail();

            $existing = JournalEntry::query()->where('book_id', $invoiceJournal->book_id)
                ->where('source_type', ReceivableReceipt::class)->where('source_id', (string) $receipt->getKey())
                ->lockForUpdate()->first();
            if ($existing instanceof JournalEntry && $existing->status === JournalStatus::Posted) {
                return $existing;
            }

            $amount = (string) $receipt->getAttribute('amount');
            $currency = $this->currencies->get((string) $receipt->getAttribute('currency'));
            $applications = $receipt->applications()->limit(2)->get();
            $debits = $invoiceJournal->lines()->where('debit', '>', 0)->limit(2)->get();
            $metadata = $invoiceJournal->getAttribute('metadata');
            if ($existing instanceof JournalEntry || $invoiceJournal->status !== JournalStatus::Posted
                || $payment->getAttribute('status') !== PaymentStatus::Captured || $payment->getAttribute('refunded_minor') !== 0
                || $applications->count() !== 1 || $debits->count() !== 1
                || (int) ($metadata['receivable_id'] ?? 0) !== (int) $applications[0]->getAttribute('open_item_id')
                || ($metadata['currency'] ?? null) !== $currency->code
                || $source->currency !== $currency->code || $currency->minorUnits > 2
                || bccomp($amount, (new Money((int) $source->amount_minor, $currency))->decimal(), 2) !== 0
                || bccomp($amount, (string) $applications[0]->getAttribute('amount'), 2) !== 0) {
                throw ValidationException::withMessages(['allocation' => 'The receipt must reconcile to an unrefunded payment and the posted invoice journal.']);
            }

            $date = $receipt->getAttribute('received_on')->toDateString();
            $periods = AccountingPeriod::query()->where('book_id', $invoiceJournal->book_id)
                ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->lockForUpdate()->limit(2)->get();
            if ($periods->count() !== 1 || ! $periods[0]->isPostingAllowed($date)) {
                throw ValidationException::withMessages(['allocation' => 'The receipt date must belong to one open posting period.']);
            }

            $control = $debits[0]->getAttribute('account_id');
            $cash = Account::query()->where('legal_entity_id', $entity)->where('type', AccountType::Asset)
                ->where('is_active', true)->where('allow_manual_entry', true)->where('is_control_account', false)
                ->whereKeyNot($control)->whereKey($cashAccountId)->lockForUpdate()->firstOrFail();

            $entry = $this->createJournal->handle([
                'book_id' => $invoiceJournal->book_id,
                'entry_date' => $date,
                'source_type' => ReceivableReceipt::class,
                'source_id' => (string) $receipt->getKey(),
                'description' => 'Billing allocation '.$allocationId,
                'metadata' => ['invoice_journal_id' => $invoiceJournal->getKey(), 'billing_allocation_id' => $allocationId],
            ], [
                ['account_id' => $cash->getKey(), 'debit' => $amount],
                ['account_id' => $control, 'credit' => $amount],
            ]);

            return $this->postJournal->handle($entry, (string) $actor->getKey());
        }, attempts: 3);
    }
}
