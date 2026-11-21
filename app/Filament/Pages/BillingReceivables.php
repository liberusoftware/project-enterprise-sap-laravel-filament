<?php

namespace App\Filament\Pages;

use App\Enterprise\ApplyBillingAllocation;
use App\Enterprise\PostBillingInvoice;
use App\Enterprise\PostBillingReceipt;
use App\Enterprise\RecordBillingReceivable;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsReceivable\Exceptions\InvalidReceivable;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * @property-read Schema $form
 * @property-read Schema $allocationForm
 * @property-read Schema $postingForm
 * @property-read Schema $receiptPostingForm
 */
final class BillingReceivables extends Page
{
    protected static ?string $title = 'Billing receivables';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected string $view = 'filament.pages.billing-receivables';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $allocationData = [];

    /** @var array<string, mixed> */
    public array $postingData = [];

    /** @var array<string, mixed> */
    public array $receiptPostingData = [];

    public static function canAccess(): bool
    {
        return EnterpriseSetup::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('invoice')->label('Billing invoice ID')->required()->integer()->minValue(1),
            TextInput::make('customer')->label('Accounting customer ID')->required()->integer()->minValue(1),
        ]);
    }

    public function allocationForm(Schema $schema): Schema
    {
        return $schema->statePath('allocationData')->components([
            TextInput::make('allocation')->label('Billing allocation ID')->required()->integer()->minValue(1),
        ]);
    }

    public function postingForm(Schema $schema): Schema
    {
        return $schema->statePath('postingData')->components([
            TextInput::make('invoice')->label('Billing invoice ID')->required()->integer()->minValue(1),
            TextInput::make('receivable')->label('Receivable account ID')->required()->integer()->minValue(1),
            TextInput::make('revenue')->label('Revenue account ID')->required()->integer()->minValue(1),
            TextInput::make('tax')->label('Tax payable account ID')->integer()->minValue(1),
        ]);
    }

    public function postInvoice(PostBillingInvoice $posting): void
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();
        abort_unless($owner instanceof User && $team instanceof Team && self::canAccess(), 403);

        $state = $this->postingForm->getState();

        try {
            $journal = $posting->handle($owner, $team, (int) $state['invoice'], (int) $state['receivable'],
                (int) $state['revenue'], filled($state['tax']) ? (int) $state['tax'] : null);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['postingData.invoice' => 'The invoice, receivable, or book is unavailable in this workspace.']);
        } catch (ValidationException|InvalidJournal $exception) {
            throw ValidationException::withMessages(['postingData.invoice' => $exception->getMessage()]);
        }

        Notification::make()->title('Invoice posted')->body('Journal '.$journal->entry_number)->success()->send();
    }

    public function receiptPostingForm(Schema $schema): Schema
    {
        return $schema->statePath('receiptPostingData')->components([
            TextInput::make('allocation')->label('Billing allocation ID')->required()->integer()->minValue(1),
            TextInput::make('cash')->label('Cash or clearing account ID')->required()->integer()->minValue(1),
        ]);
    }

    public function postReceipt(PostBillingReceipt $posting): void
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();
        abort_unless($owner instanceof User && $team instanceof Team && self::canAccess(), 403);

        $state = $this->receiptPostingForm->getState();

        try {
            $journal = $posting->handle($owner, $team, (int) $state['allocation'], (int) $state['cash']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['receiptPostingData.allocation' => 'The posted invoice journal or cash account is unavailable in this workspace.']);
        } catch (ValidationException|InvalidJournal $exception) {
            throw ValidationException::withMessages(['receiptPostingData.allocation' => $exception->getMessage()]);
        }

        Notification::make()->title('Receipt posted')->body('Journal '.$journal->entry_number)->success()->send();
    }

    public function applyAllocation(ApplyBillingAllocation $handoff): void
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();
        abort_unless($owner instanceof User && $team instanceof Team && self::canAccess(), 403);

        $state = $this->allocationForm->getState();

        try {
            $receipt = $handoff->handle($owner, $team, (int) $state['allocation']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'allocationData.allocation' => 'The allocation or its receivable is unavailable in this workspace.',
            ]);
        } catch (ValidationException|InvalidReceivable $exception) {
            throw ValidationException::withMessages(['allocationData.allocation' => $exception->getMessage()]);
        }

        Notification::make()->title('Payment applied')
            ->body('Accounting reference '.$receipt->getAttribute('reference'))->success()->send();
    }

    public function record(RecordBillingReceivable $handoff): void
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();
        abort_unless($owner instanceof User && $team instanceof Team && self::canAccess(), 403);

        $state = $this->form->getState();

        try {
            $item = $handoff->handle($owner, $team, (int) $state['invoice'], (int) $state['customer']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'data.invoice' => 'The company, invoice, or customer is unavailable in this workspace.',
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])
                ->all());
        }

        Notification::make()->title('Receivable recorded')
            ->body('Accounting reference '.$item->getAttribute('reference'))->success()->send();
    }
}
