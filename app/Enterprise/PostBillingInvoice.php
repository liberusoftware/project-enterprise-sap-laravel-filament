<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableAccount;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableOpenItem;
use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\GeneralLedger\Actions\CreateJournal;
use Liberu\Accounting\GeneralLedger\Actions\PostJournal;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\Periods\Models\AccountingPeriod;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Foundation\Currency\Services\CurrencyRegistry;
use Liberu\Foundation\Currency\ValueObjects\Money;
use Liberu\Foundation\Organizations\Models\Team;

final readonly class PostBillingInvoice
{
    public function __construct(
        private CreateJournal $createJournal,
        private PostJournal $postJournal,
        private CurrencyRegistry $currencies,
    ) {}

    public function handle(User $actor, Team $team, int $invoiceId, int $receivableAccount, int $revenueAccount, ?int $taxAccount): JournalEntry
    {
        return DB::transaction(function () use ($actor, $team, $invoiceId, $receivableAccount, $revenueAccount, $taxAccount): JournalEntry {
            $team = Team::query()->lockForUpdate()->findOrFail($team->getKey());
            abort_unless($actor->ownsTeam($team), 403);

            $invoice = Invoice::query()->where('team_id', $team->getKey())->lockForUpdate()->findOrFail($invoiceId);
            $entityId = DB::table('enterprise_company_setups')->where('team_id', $team->getKey())->value('legal_entity_id');
            $book = Book::query()->where('legal_entity_id', $entityId)->where('code', 'PRIMARY')
                ->where('is_active', true)->whereRelation('legalEntity', 'is_active', true)->lockForUpdate()->firstOrFail();

            $entry = JournalEntry::query()->where('book_id', $book->getKey())
                ->where('source_type', Invoice::class)->where('source_id', (string) $invoiceId)->lockForUpdate()->first();
            if ($entry instanceof JournalEntry) {
                if ($entry->status !== JournalStatus::Posted) {
                    throw ValidationException::withMessages(['invoice' => 'Review the existing source journal before posting this invoice.']);
                }

                return $entry;
            }

            $item = ReceivableOpenItem::query()->where('source_type', Invoice::class)
                ->whereRelation('party', 'legal_entity_id', $entityId)
                ->where('source_id', (string) $invoiceId)->lockForUpdate()->firstOrFail();
            $date = $item->getAttribute('issued_on')->toDateString();
            $periods = AccountingPeriod::query()->where('book_id', $book->getKey())
                ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
                ->lockForUpdate()->limit(2)->get();
            if ($periods->count() !== 1 || ! $periods[0]->isPostingAllowed($date)) {
                throw ValidationException::withMessages(['invoice' => 'The invoice date must belong to one open posting period.']);
            }

            $currency = $this->currencies->get((string) $item->getAttribute('currency'));
            $subtotal = $invoice->getAttribute('subtotal_minor');
            $tax = $invoice->getAttribute('tax_minor');
            $total = $invoice->getAttribute('total_minor');
            if (! in_array($invoice->getAttribute('status'), [InvoiceStatus::Finalized, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)
                || $currency->minorUnits > 2 || $invoice->getAttribute('currency') !== $currency->code
                || ! is_int($subtotal) || ! is_int($tax) || ! is_int($total)
                || $subtotal <= 0 || $tax < 0 || $total > 99_999_999_999_999 || $subtotal + $tax !== $total
                || bccomp((new Money($total, $currency))->decimal(), (string) $item->getAttribute('original_amount'), 2) !== 0) {
                throw ValidationException::withMessages(['invoice' => 'The finalized invoice must reconcile to its Accounting receivable.']);
            }

            $controlCode = ReceivableAccount::query()->where('party_id', $item->getAttribute('party_id'))
                ->firstOrFail()->getAttribute('control_account_code');
            $controlAccount = Account::query()->where('legal_entity_id', $entityId)->where('code', $controlCode)
                ->where('is_control_account', true)->where('type', AccountType::Asset)
                ->where('is_active', true)->where('allow_manual_entry', true)
                ->whereKey($receivableAccount)->lockForUpdate()->first();
            if (! $controlAccount instanceof Account) {
                throw ValidationException::withMessages(['invoice' => 'Choose the customer’s configured receivables control account.']);
            }

            $lines = [
                ['account_id' => $controlAccount->getKey(), 'debit' => (new Money($total, $currency))->decimal()],
                ['account_id' => $this->account($entityId, $revenueAccount, AccountType::Revenue), 'credit' => (new Money($subtotal, $currency))->decimal()],
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $this->account($entityId, $taxAccount, AccountType::Liability), 'credit' => (new Money($tax, $currency))->decimal()];
            }

            $journal = $this->createJournal->handle([
                'book_id' => $book->getKey(),
                'entry_date' => $date,
                'source_type' => Invoice::class,
                'source_id' => (string) $invoiceId,
                'description' => 'Billing invoice '.$invoiceId,
                'metadata' => ['receivable_id' => $item->getKey(), 'currency' => $currency->code],
            ], $lines);

            return $this->postJournal->handle($journal, (string) $actor->getKey());
        }, attempts: 3);
    }

    private function account(mixed $entityId, ?int $accountId, AccountType $type): int
    {
        $account = Account::query()->where('legal_entity_id', $entityId)->where('type', $type)
            ->where('is_active', true)->where('allow_manual_entry', true)->whereKey($accountId)->lockForUpdate()->first();
        if (! $account instanceof Account) {
            throw ValidationException::withMessages(['invoice' => 'Choose active company accounts of the required types.']);
        }

        return (int) $account->getKey();
    }
}
