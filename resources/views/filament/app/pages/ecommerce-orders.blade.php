<x-filament-panels::page>
    <form wire:submit="lookup" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Look up order</x-filament::button>
    </form>

    @if ($order)
        <x-filament::section :heading="'Order #'.$order['id']">
            <dl class="grid gap-2 sm:grid-cols-3">
                <div><dt>Total</dt><dd>{{ $order['currency'] }} {{ $order['total'] }}</dd></div>
                <div><dt>Payment</dt><dd>{{ $order['payment'] }}</dd></div>
                <div><dt>Shipping</dt><dd>{{ $order['shipping'] }}</dd></div>
            </dl>
        </x-filament::section>
    @endif
</x-filament-panels::page>
