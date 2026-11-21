<x-filament-panels::page>
    <form wire:submit="lookup" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Look up deal</x-filament::button>
    </form>

    @if ($deal)
        <x-filament::section :heading="$deal['name']">
            <dl>
                <dt>Deal ID</dt><dd>{{ $deal['id'] }}</dd>
                <dt>Stage</dt><dd>{{ $deal['stage'] ?? 'Unassigned' }}</dd>
            </dl>
        </x-filament::section>
    @endif
</x-filament-panels::page>
