<x-filament-panels::page>
    <form wire:submit="search" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Search catalogue</x-filament::button>
    </form>

    @if ($results)
        <x-filament::section :heading="'Page '.$results['page'].' of '.$results['last']">
            <ul class="space-y-2">
                @forelse ($results['products'] as $product)
                    <li>{{ $product['name'] }} · #{{ $product['id'] }} · {{ $product['price'] ?? 'Price varies' }}</li>
                @empty
                    <li>No products on this page.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
