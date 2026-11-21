<x-filament-panels::page>
    @if ($this->isComplete)
        <p>Company setup is complete. Your legal entity, primary accounting book, and billing account are ready.</p>
    @else
        <form wire:submit="provision">
            {{ $this->form }}
        </form>
    @endif
</x-filament-panels::page>
