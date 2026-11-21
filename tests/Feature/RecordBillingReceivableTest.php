<?php

use App\Enterprise\ApplyBillingAllocation;
use App\Enterprise\CompanySetup;
use App\Enterprise\PostBillingInvoice;
use App\Enterprise\RecordBillingReceivable;
use App\Filament\Pages\BillingReceivables;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Actions\ApplyReceipt;
use Liberu\Accounting\AccountsReceivable\Actions\RecordReceipt;
use Liberu\Accounting\AccountsReceivable\Exceptions\InvalidReceivable;
use Liberu\Accounting\ChartOfAccounts\Actions\SaveAccount;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\FinancialMasterData\Actions\SaveParty;
use Liberu\Accounting\GeneralLedger\Enums\JournalStatus;
use Liberu\Accounting\GeneralLedger\Models\JournalEntry;
use Liberu\Accounting\Periods\Actions\CreatePeriod;
use Liberu\Accounting\Periods\Actions\TransitionPeriod;
use Liberu\Accounting\Periods\Enums\PeriodState;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Actions\CreateInvoice;
use Liberu\Billing\Invoicing\Actions\FinalizeInvoice;
use Liberu\Billing\Payments\Actions\AllocatePayment;
use Liberu\Billing\Payments\Actions\CapturePayment;
use Liberu\Billing\Payments\Actions\CreatePayment;
use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Models\PaymentAllocation;
use Liberu\Billing\Payments\Services\GatewayManager;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    app(CompanySetup::class)->provision($this->owner, $this->team, 'Example', 'GBP');
    $this->entityId = DB::table('enterprise_company_setups')->where('team_id', $this->team->id)->value('legal_entity_id');
    $this->customer = app(SaveParty::class)->handle([
        'legal_entity_id' => $this->entityId, 'type' => 'customer', 'name' => 'Example customer',
    ]);
    $this->invoice = app(CreateInvoice::class)->execute([
        'team_id' => $this->team->id, 'currency' => 'GBP', 'number' => 'INV-001', 'due_at' => '2027-01-01',
    ]);
    app(AddInvoiceLine::class)->execute($this->invoice, 'Service', 2, 1234, 20);
    $this->handoff = app(RecordBillingReceivable::class);
});

it('records the finalized billing total in accounting and links it once', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);

    $receivable = $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);
    $retry = $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);

    expect($retry->is($receivable))->toBeTrue()
        ->and($receivable->reference)->toBe('INV-001')
        ->and($receivable->original_amount)->toBe('29.62')
        ->and($receivable->currency)->toBe('GBP')
        ->and($receivable->metadata['recorded_by'])->toEqual($this->owner->id)
        ->and($receivable->metadata['team_id'])->toEqual($this->team->id)
        ->and($receivable->due_on->toDateString())->toBe('2027-01-01');

    $otherCustomer = app(SaveParty::class)->handle([
        'legal_entity_id' => $this->entityId, 'type' => 'customer', 'name' => 'Unrelated customer',
    ]);
    expect(fn () => $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $otherCustomer->id))
        ->toThrow(ValidationException::class, 'already linked');
    $this->assertDatabaseCount('accounting_ar_open_items', 1);
});

it('isolates invoices and customers by company and requires the team owner', function () {
    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create(['user_id' => $outsider->id]);
    app(CompanySetup::class)->provision($outsider, $otherTeam, 'Other company', 'GBP');
    $foreignEntity = DB::table('enterprise_company_setups')->where('team_id', $otherTeam->id)->value('legal_entity_id');
    $foreignCustomer = app(SaveParty::class)->handle([
        'legal_entity_id' => $foreignEntity, 'type' => 'customer', 'name' => 'Foreign customer',
    ]);

    expect(fn () => $this->handoff->handle($outsider, $this->team, $this->invoice->id, $this->customer->id))
        ->toThrow(HttpException::class)
        ->and(fn () => $this->handoff->handle($outsider, $otherTeam, $this->invoice->id, $foreignCustomer->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $foreignCustomer->id))
        ->toThrow(ModelNotFoundException::class);
    $this->assertDatabaseCount('accounting_ar_open_items', 0);
});

it('rejects drafts and foreign currency before creating a receivable', function () {
    expect(fn () => $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id))
        ->toThrow(ValidationException::class, 'finalized invoice');

    $this->invoice->update(['currency' => 'EUR']);
    app(FinalizeInvoice::class)->execute($this->invoice);

    expect(fn () => $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id))
        ->toThrow(ValidationException::class, 'company-currency amount');
    $this->assertDatabaseCount('accounting_ar_open_items', 0);
});

it('records a receivable from the tenant admin page and reports invalid input', function () {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);

    expect(Filament::getPanel('admin')->getPages())
        ->toContain(BillingReceivables::class);

    $page = Livewire::test(BillingReceivables::class)
        ->assertSee('Billing invoice ID')
        ->set('data.invoice', 999999)
        ->set('data.customer', $this->customer->id)
        ->call('record')
        ->assertHasErrors('data.invoice')
        ->set('data.invoice', $this->invoice->id)
        ->call('record')
        ->assertHasErrors('data.invoice');

    app(FinalizeInvoice::class)->execute($this->invoice);

    $page->call('record')->assertHasNoErrors();
    $this->assertDatabaseHas('accounting_ar_open_items', ['reference' => 'INV-001']);
});

it('retains oversized invoice numbers in source metadata with a stable accounting reference', function () {
    $number = str_repeat('X', 129);
    $this->invoice->update(['number' => $number]);
    app(FinalizeInvoice::class)->execute($this->invoice);

    $item = $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);

    expect($item->reference)->toBe('Billing invoice '.$this->invoice->id)
        ->and($item->metadata['billing_invoice_number'])->toBe($number);
});

function billingAllocation(Team $team, int $invoiceId, int $amount): PaymentAllocation
{
    app(GatewayManager::class)->register('test-gateway', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'captured-'.$payment->getKey()];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            throw new LogicException('Unexpected refund');
        }
    });
    $payment = app(CreatePayment::class)->execute([
        'team_id' => $team->id, 'currency' => 'GBP', 'amount_minor' => $amount, 'gateway' => 'test-gateway',
    ]);
    $captured = app(CapturePayment::class)->execute($payment);

    return app(AllocatePayment::class)->execute($captured, $amount, $invoiceId);
}

it('applies captured allocations once even when Billing marked the invoice paid before handoff', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);
    $first = billingAllocation($this->team, $this->invoice->id, 1000);
    $second = billingAllocation($this->team, $this->invoice->id, 1962);
    $item = $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);

    $action = app(ApplyBillingAllocation::class);
    $receipt = $action->handle($this->owner, $this->team, $first->id);
    expect($receipt->amount)->toBe('10.00')
        ->and($item->refresh()->applied_amount)->toBe('10.00')
        ->and($action->handle($this->owner, $this->team, $first->id)->is($receipt))->toBeTrue();

    $action->handle($this->owner, $this->team, $second->id);
    expect($item->refresh()->status->value)->toBe('settled');
    $this->assertDatabaseCount('accounting_ar_receipts', 2);
    $this->assertDatabaseCount('enterprise_billing_receipt_links', 2);

    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create(['user_id' => $outsider->id]);
    expect(fn () => $action->handle($outsider, $this->team, $first->id))->toThrow(HttpException::class)
        ->and(fn () => $action->handle($outsider, $otherTeam, $first->id))->toThrow(ModelNotFoundException::class);
});

it('applies allocations from the admin page and handles missing and refunded payments', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);
    $good = billingAllocation($this->team, $this->invoice->id, 1000);
    $refunded = billingAllocation($this->team, $this->invoice->id, 1000);
    $refunded->payment()->update(['refunded_minor' => 1]);
    $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);

    Livewire::test(BillingReceivables::class)->assertSee('Billing allocation ID')
        ->set('allocationData.allocation', 999999)->call('applyAllocation')->assertHasErrors('allocationData.allocation')
        ->set('allocationData.allocation', $refunded->id)->call('applyAllocation')->assertHasErrors('allocationData.allocation')
        ->set('allocationData.allocation', $good->id)->call('applyAllocation')->assertHasNoErrors();

    $this->assertDatabaseCount('accounting_ar_receipts', 1);
});

it('rolls back a Billing receipt when Accounting rejects the allocation', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);
    $allocation = billingAllocation($this->team, $this->invoice->id, 2962);
    $item = $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);
    $existing = app(RecordReceipt::class)->handle([
        'party_id' => $this->customer->id, 'amount' => '1.00', 'currency' => 'GBP',
    ]);
    app(ApplyReceipt::class)->handle($existing, $item, 1.0);

    expect(fn () => app(ApplyBillingAllocation::class)->handle($this->owner, $this->team, $allocation->id))
        ->toThrow(InvalidReceivable::class);

    expect($item->refresh()->applied_amount)->toBe('1.00');
    $this->assertDatabaseCount('accounting_ar_receipts', 1);
    $this->assertDatabaseCount('enterprise_billing_receipt_links', 0);
});

it('posts a reconciled invoice once through the admin panel and requires an open period and valid accounts', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);
    $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);
    $book = Book::query()->where('legal_entity_id', $this->entityId)->sole();
    $accounts = collect(['asset', 'revenue', 'liability'])->mapWithKeys(fn (string $type) => [
        $type => app(SaveAccount::class)->handle([
            'legal_entity_id' => $this->entityId, 'code' => $type === 'asset' ? 'accounts_receivable' : $type, 'name' => $type, 'type' => $type,
            'is_control_account' => $type === 'asset',
        ])->getKey(),
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);

    $page = Livewire::test(BillingReceivables::class)->assertSee('Post an invoice to General Ledger')
        ->set('postingData.invoice', 999999)
        ->set('postingData.receivable', $accounts['asset'])
        ->set('postingData.revenue', $accounts['revenue'])
        ->call('postInvoice')->assertHasErrors('postingData.invoice')
        ->set('postingData.invoice', $this->invoice->id)
        ->call('postInvoice')->assertHasErrors('postingData.invoice');

    $period = app(CreatePeriod::class)->handle([
        'book_id' => $book->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(),
    ]);

    $transition = app(TransitionPeriod::class);
    $transition->handle($period, PeriodState::SoftClosed);
    $page->call('postInvoice')->assertHasErrors(['postingData.invoice' => 'The invoice date must belong to one open posting period.']);
    $transition->handle($period, PeriodState::Open, (string) $this->owner->id, 'Approved test reopening');

    $page->set('postingData.receivable', $accounts['revenue'])->call('postInvoice')->assertHasErrors('postingData.invoice')
        ->set('postingData.receivable', $accounts['asset'])->call('postInvoice')->assertHasErrors('postingData.invoice')
        ->set('postingData.tax', $accounts['liability'])
        ->call('postInvoice')->assertHasNoErrors()
        ->call('postInvoice')->assertHasNoErrors();

    $journal = JournalEntry::query()->sole();
    expect($journal->status->value)->toBe('posted')
        ->and($journal->isBalanced())->toBeTrue()
        ->and($journal->getAttribute('posted_by'))->toEqual($this->owner->id);
    expect($journal->lines()->pluck('credit', 'account_id')->all())
        ->toMatchArray([$accounts['revenue'] => '24.68', $accounts['liability'] => '4.94']);

    $outsider = User::factory()->create();
    expect(fn () => app(PostBillingInvoice::class)->handle($outsider, $this->team, $this->invoice->id,
        $accounts['asset'], $accounts['revenue'], $accounts['liability']))->toThrow(HttpException::class);
});

it('rejects a changed invoice total before posting and can post untaxed invoices', function () {
    $invoice = app(CreateInvoice::class)->execute(['team_id' => $this->team->id, 'currency' => 'GBP']);
    app(AddInvoiceLine::class)->execute($invoice, 'Service', 1, 1500);
    $invoice = app(FinalizeInvoice::class)->execute($invoice);
    $this->handoff->handle($this->owner, $this->team, $invoice->id, $this->customer->id);
    $book = Book::query()->where('legal_entity_id', $this->entityId)->sole();
    app(CreatePeriod::class)->handle([
        'book_id' => $book->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(),
    ]);
    $asset = app(SaveAccount::class)->handle(['legal_entity_id' => $this->entityId, 'code' => 'accounts_receivable', 'name' => 'Receivables', 'type' => 'asset', 'is_control_account' => true]);
    $revenue = app(SaveAccount::class)->handle(['legal_entity_id' => $this->entityId, 'code' => 'REV', 'name' => 'Revenue', 'type' => 'revenue']);
    $action = fn () => app(PostBillingInvoice::class)->handle($this->owner, $this->team, $invoice->id, $asset->id, $revenue->id, null);
    $invoice->update(['total_minor' => 1501]);

    expect($action)->toThrow(ValidationException::class, 'reconcile');
    $this->assertDatabaseCount('accounting_journal_entries', 0);
    $invoice->update(['total_minor' => 1500]);

    $draft = JournalEntry::query()->create([
        'book_id' => $book->id, 'entry_date' => now()->toDateString(), 'status' => JournalStatus::Draft,
        'source_type' => $invoice::class, 'source_id' => (string) $invoice->id,
    ]);
    expect($action)->toThrow(ValidationException::class, 'Review the existing source journal');
    $draft->delete();

    expect($action()->lines()->count())->toBe(2);
});

it('posts an applied receipt against the original invoice control account once', function () {
    app(FinalizeInvoice::class)->execute($this->invoice);
    $allocation = billingAllocation($this->team, $this->invoice->id, 2962);
    $this->handoff->handle($this->owner, $this->team, $this->invoice->id, $this->customer->id);

    $accounts = collect(['accounts_receivable' => 'asset', 'cash' => 'asset', 'revenue' => 'revenue', 'tax' => 'liability'])
        ->map(fn (string $type, string $code) => app(SaveAccount::class)->handle([
            'legal_entity_id' => $this->entityId, 'code' => $code, 'name' => $code, 'type' => $type,
            'is_control_account' => $code === 'accounts_receivable',
        ])->getKey());
    $book = Book::query()->where('legal_entity_id', $this->entityId)->sole();
    $period = app(CreatePeriod::class)->handle([
        'book_id' => $book->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(),
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);
    $page = Livewire::test(BillingReceivables::class)->assertSee('Post a receipt to General Ledger')
        ->set('receiptPostingData.allocation', $allocation->id)
        ->set('receiptPostingData.cash', $accounts['cash'])
        ->call('postReceipt')->assertHasErrors('receiptPostingData.allocation');
    app(ApplyBillingAllocation::class)->handle($this->owner, $this->team, $allocation->id);
    $page->call('postReceipt')->assertHasErrors('receiptPostingData.allocation');

    $invoiceJournal = app(PostBillingInvoice::class)->handle($this->owner, $this->team, $this->invoice->id,
        $accounts['accounts_receivable'], $accounts['revenue'], $accounts['tax']);
    $transition = app(TransitionPeriod::class);
    $transition->handle($period, PeriodState::SoftClosed);
    $page->call('postReceipt')->assertHasErrors(['receiptPostingData.allocation' => 'The receipt date must belong to one open posting period.']);
    $transition->handle($period, PeriodState::Open, (string) $this->owner->id, 'Approved reopening');
    $allocation->payment()->update(['refunded_minor' => 1]);
    $page->call('postReceipt')->assertHasErrors('receiptPostingData.allocation');
    $allocation->payment()->update(['refunded_minor' => 0]);
    $allocation->update(['amount_minor' => 2961]);
    $page->call('postReceipt')->assertHasErrors('receiptPostingData.allocation');
    $allocation->update(['amount_minor' => 2962]);

    $page->call('postReceipt')->assertHasNoErrors()->call('postReceipt')->assertHasNoErrors();

    $receiptJournal = JournalEntry::query()->whereKeyNot($invoiceJournal->getKey())->sole();
    expect($receiptJournal->isBalanced())->toBeTrue()
        ->and($receiptJournal->getAttribute('metadata')['invoice_journal_id'])->toEqual($invoiceJournal->getKey())
        ->and($receiptJournal->lines()->where('account_id', $accounts['cash'])->sole()->debit)->toBe('29.62')
        ->and($receiptJournal->lines()->where('account_id', $accounts['accounts_receivable'])->sole()->credit)->toBe('29.62');
});
