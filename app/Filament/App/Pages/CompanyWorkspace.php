<?php

namespace App\Filament\App\Pages;

use App\Enterprise\CompanySetup;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Liberu\Foundation\Organizations\Models\Team;

final class CompanyWorkspace extends Page
{
    protected static ?string $title = 'Company workspace';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected string $view = 'filament.app.pages.company-workspace';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $team = $user instanceof User ? $user->currentTeam : null;

        return $team instanceof Team && $user->canAccessTenant($team);
    }

    /** @return array{team: Team, region: array{locale: string, timezone: string}|null} */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $team = $user instanceof User ? $user->currentTeam : null;
        abort_unless($team instanceof Team && $user->canAccessTenant($team), 403);

        $setup = app(CompanySetup::class);

        return [
            'team' => $team,
            'region' => $setup->complete($team) ? $setup->region($team) : null,
        ];
    }
}
