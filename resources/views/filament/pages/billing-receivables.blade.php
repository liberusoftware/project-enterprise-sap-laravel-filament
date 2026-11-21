<x-filament-panels::page>
    <p>Link a finalized Billing invoice to an active Accounting customer in this company.</p>

    <form wire:submit="record" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">Record receivable</x-filament::button>
    </form>

    <x-filament::section heading="Apply a Billing payment">
        <form wire:submit="applyAllocation" class="space-y-6">
            {{ $this->allocationForm }}
            <x-filament::button type="submit">Apply payment allocation</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section heading="Post an invoice to General Ledger">
        <form wire:submit="postInvoice" class="space-y-6">
            {{ $this->postingForm }}
            <x-filament::button type="submit">Post invoice</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section heading="Post a receipt to General Ledger">
        <form wire:submit="postReceipt" class="space-y-6">
            {{ $this->receiptPostingForm }}
            <x-filament::button type="submit">Post receipt</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
