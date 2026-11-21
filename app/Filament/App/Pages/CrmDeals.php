<?php

namespace App\Filament\App\Pages;

use App\Enterprise\LookupCrmDeal;
use App\Enterprise\RemoteErpConnection;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class CrmDeals extends Page
{
    protected static ?string $title = 'CRM deals';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected string $view = 'filament.app.pages.crm-deals';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{id: int, name: string, stage: string|null}|null */
    #[Locked]
    public ?array $deal = null;

    public static function canAccess(): bool
    {
        return (int) config('services.crm.remote_team_id') > 0 && RemoteErpConnection::allows('crm', Filament::auth()->user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('deal')->label('CRM deal ID')->required()->integer()->minValue(1),
        ]);
    }

    public function lookup(LookupCrmDeal $lookup): void
    {
        abort_unless(self::canAccess(), 403);
        $this->deal = null;
        $state = $this->form->getState();

        try {
            $this->deal = $lookup->handle((int) $state['deal']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.deal' => $exception->errors()['deal']]);
        }
    }
}
