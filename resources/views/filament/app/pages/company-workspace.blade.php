<x-filament-panels::page>
    <x-filament::section :heading="$team->name">
        @if ($region)
            <p>Billing and accounting setup is complete.</p>
            <dl>
                <dt>Default locale</dt>
                <dd>{{ $region['locale'] }}</dd>
                <dt>Default time zone</dt>
                <dd>{{ $region['timezone'] }}</dd>
            </dl>
        @else
            <p>Ask your company administrator to complete Enterprise SAP setup.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
