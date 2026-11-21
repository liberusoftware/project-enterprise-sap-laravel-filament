<?php

namespace App\Filament\App\Pages;

use App\Enterprise\RemoteErpConnection;
use App\Enterprise\SearchEcommerceCatalogue;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class EcommerceCatalogue extends Page
{
    protected static ?string $title = 'Ecommerce catalogue';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected string $view = 'filament.app.pages.ecommerce-catalogue';

    /** @var array<string, mixed> */
    public array $data = ['page' => 1];

    /** @var array{products: list<array{id: int, name: string, price: string|null}>, page: int, last: int}|null */
    #[Locked]
    public ?array $results = null;

    public static function canAccess(): bool
    {
        return (int) config('services.ecommerce.store_id') > 0 && RemoteErpConnection::allows('ecommerce', Filament::auth()->user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('search')->label('Product name or description')->maxLength(100),
            TextInput::make('page')->label('Page')->required()->integer()->minValue(1)->maxValue(1000),
        ]);
    }

    public function search(SearchEcommerceCatalogue $search): void
    {
        abort_unless(self::canAccess(), 403);
        $this->results = null;
        $state = $this->form->getState();

        try {
            $this->results = $search->handle(trim((string) ($state['search'] ?? '')), (int) $state['page']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.search' => $exception->errors()['search']]);
        }
    }
}
