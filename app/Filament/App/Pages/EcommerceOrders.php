<?php

namespace App\Filament\App\Pages;

use App\Enterprise\LookupEcommerceOrder;
use App\Enterprise\RemoteErpConnection;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class EcommerceOrders extends Page
{
    protected static ?string $title = 'Ecommerce orders';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected string $view = 'filament.app.pages.ecommerce-orders';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{id: int, total: string, currency: string, payment: string, shipping: string}|null */
    #[Locked]
    public ?array $order = null;

    public static function canAccess(): bool
    {
        return RemoteErpConnection::allows('ecommerce', Filament::auth()->user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('order')->label('Ecommerce order ID')->required()->integer()->minValue(1),
        ]);
    }

    public function lookup(LookupEcommerceOrder $lookup): void
    {
        abort_unless(self::canAccess(), 403);
        $this->order = null;

        $state = $this->form->getState();

        try {
            $this->order = $lookup->handle((int) $state['order']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.order' => $exception->errors()['order']]);
        }
    }
}
