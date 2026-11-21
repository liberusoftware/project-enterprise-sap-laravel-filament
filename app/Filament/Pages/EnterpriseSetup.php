<?php

namespace App\Filament\Pages;

use App\Enterprise\CompanyRegionalSettings;
use App\Enterprise\CompanySetup;
use App\Models\User;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Attributes\Computed;

/**
 * @property-read Schema $form
 * @property-read bool $isComplete
 */
final class EnterpriseSetup extends Page
{
    protected static ?string $title = 'Enterprise SAP setup';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected string $view = 'filament.pages.enterprise-setup';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();

        return $owner instanceof User && $team instanceof Team && $owner->ownsTeam($team);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make('Legal entity')->schema([
                    TextInput::make('name')->label('Legal company name')->required()->maxLength(255),
                    ...$this->regionFields(),
                ]),
                Step::make('Billing and accounting')->schema([
                    Select::make('currency')->label('Base currency')->options(
                        collect(config('currency.currencies', []))->keys()->mapWithKeys(
                            fn (string $code): array => [$code => $code],
                        )->all(),
                    )->required(),
                ]),
            ])->submitAction(new HtmlString(Blade::render(
                '<x-filament::button type="submit">Complete setup</x-filament::button>'
            ))),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editRegion')
                ->label('Edit regional defaults')
                ->visible(fn (): bool => $this->isComplete)
                ->schema($this->regionFields())
                ->fillForm(function (CompanySetup $setup): array {
                    $team = Filament::getTenant();
                    abort_unless($team instanceof Team, 403);

                    return $setup->region($team);
                })
                ->action(function (array $data, CompanySetup $setup): void {
                    $owner = Filament::auth()->user();
                    $team = Filament::getTenant();
                    abort_unless($owner instanceof User && $team instanceof Team, 403);

                    $setup->updateRegion($owner, $team, [
                        'locale' => (string) $data['locale'],
                        'timezone' => (string) $data['timezone'],
                    ]);
                })
                ->successNotificationTitle('Regional defaults updated'),
        ];
    }

    /** @return list<Select> */
    private function regionFields(): array
    {
        $defaults = CompanyRegionalSettings::defaults();
        $timezones = DateTimeZone::listIdentifiers();

        return [
            Select::make('locale')->label('Default locale')->options(CompanyRegionalSettings::locales())
                ->default($defaults['locale'])->required(),
            Select::make('timezone')->label('Default time zone')->options(array_combine($timezones, $timezones))
                ->default($defaults['timezone'])->searchable()->required(),
        ];
    }

    #[Computed]
    public function isComplete(): bool
    {
        $team = Filament::getTenant();

        return $team instanceof Team && app(CompanySetup::class)->complete($team);
    }

    public function provision(CompanySetup $setup): void
    {
        $owner = Filament::auth()->user();
        $team = Filament::getTenant();

        abort_unless($owner instanceof User && $team instanceof Team && self::canAccess(), 403);

        $state = $this->form->getState();

        $setup->provision($owner, $team, trim((string) $state['name']), (string) $state['currency'], [
            'locale' => (string) $state['locale'],
            'timezone' => (string) $state['timezone'],
        ]);

        unset($this->isComplete);
    }
}
