<x-filament-panels::page>
    <x-filament::button wire:click="refreshSite">Load CMS site</x-filament::button>
    @error('site') <p role="alert">{{ $message }}</p> @enderror

    @if ($site)
        <x-filament::section :heading="$site['name']">
            <dl>
                <dt>Status</dt><dd>{{ $site['status'] }}</dd>
                <dt>Default locale</dt><dd>{{ $site['locale'] }}</dd>
            </dl>
            <h3>Channels</h3>
            <ul>
                @forelse ($site['channels'] as $channel)
                    <li>{{ $channel }}</li>
                @empty
                    <li>No channels.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
