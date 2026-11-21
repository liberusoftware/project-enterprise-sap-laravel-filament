<?php

namespace App\Filament\Pages;

use App\Enterprise\LookupCmsSite;
use App\Enterprise\RemoteErpConnection;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

final class CmsSite extends Page
{
    protected static ?string $title = 'CMS site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected string $view = 'filament.pages.cms-site';

    /** @var array{name: string, status: string, locale: string, channels: list<string>}|null */
    #[Locked]
    public ?array $site = null;

    public static function canAccess(): bool
    {
        return LookupCmsSite::configured() && RemoteErpConnection::allowsTeam('cms', Filament::auth()->user(), Filament::getTenant());
    }

    public function refreshSite(LookupCmsSite $lookup): void
    {
        abort_unless(self::canAccess(), 403);
        $this->site = null;
        $this->site = $lookup->handle();
    }
}
