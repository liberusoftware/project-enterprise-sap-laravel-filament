<?php

use App\Enterprise\CompanyRegionalSettings;
use App\Enterprise\CompanySetup;
use App\Filament\App\Pages\CompanyWorkspace;
use App\Filament\Pages\EnterpriseSetup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Billing\Core\Models\BillingAccount;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('provisions a company through the admin wizard and reuses the result on retry', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $otherTeam = Team::factory()->create(['user_id' => $owner->id]);
    $panel = Filament::getPanel('admin');
    $owner->forceFill(['current_team_id' => $team->id])->save();
    setPermissionsTeamId($team->id);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]);
    $owner->assignRole('super_admin');

    $setupQueries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$setupQueries): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'enterprise_company_setups')) {
            $setupQueries++;
        }
    });

    $this->actingAs($owner);
    $this->get(EnterpriseSetup::getUrl(panel: 'admin', tenant: $team))
        ->assertSuccessful()
        ->assertSee('Enterprise SAP setup')
        ->assertSee('Legal company name');

    expect($setupQueries)->toBe(1);

    Filament::setCurrentPanel($panel);
    Filament::setTenant($team);

    expect($panel->getPages())->toContain(EnterpriseSetup::class)
        ->and(Filament::getPanel('app')->getPages())->not->toContain(EnterpriseSetup::class);

    $wizard = Livewire::test(EnterpriseSetup::class)
        ->assertSee('Legal company name')
        ->assertActionHidden('editRegion')
        ->call('provision')
        ->assertHasErrors(['data.name' => 'required', 'data.currency' => 'required'])
        ->set('data.name', 'Acme UK')
        ->set('data.currency', 'GBP')
        ->set('data.locale', 'unsupported')
        ->set('data.timezone', 'GMT+1')
        ->call('provision')
        ->assertHasErrors(['data.locale', 'data.timezone'])
        ->set('data.locale', 'fr')
        ->set('data.timezone', 'Europe/London')
        ->call('provision')
        ->assertHasNoErrors()
        ->assertSee('Company setup is complete.');

    $setup = app(CompanySetup::class);
    $setup->provision($owner, $team, 'Retry with different inputs', 'USD');

    $entity = LegalEntity::query()->sole();
    $account = BillingAccount::query()->sole();

    expect($entity->name)->toBe('Acme UK')
        ->and($entity->currency_code)->toBe('GBP')
        ->and($entity->books()->sole()->code)->toBe('PRIMARY')
        ->and($account->team_id)->toEqual($team->id)
        ->and($account->currency)->toBe('GBP')
        ->and($setup->region($team))->toBe(['locale' => 'fr', 'timezone' => 'Europe/London'])
        ->and($setup->complete($otherTeam))->toBeFalse()
        ->and($setup->region($otherTeam))->toBe(CompanyRegionalSettings::defaults());

    $this->assertDatabaseHas('enterprise_company_setups', [
        'team_id' => $team->id,
        'legal_entity_id' => $entity->id,
        'billing_account_id' => $account->id,
    ]);

    $updatedRegion = ['locale' => 'de', 'timezone' => 'Europe/Berlin'];
    $wizard->callAction('editRegion', data: $updatedRegion)->assertHasNoActionErrors();
    $setup->updateRegion($owner, $team, $updatedRegion);

    expect($setup->region($team))->toBe($updatedRegion)
        ->and($setup->region($otherTeam))->toBe(CompanyRegionalSettings::defaults());

    $auditEvents = DB::table('activity_log')->where('event', 'enterprise.company_region.updated')
        ->where('tenant_ref', (string) $team->id)->where('causer_id', $owner->id)
        ->orderBy('id')->pluck('attribute_changes');

    expect($auditEvents)->toHaveCount(2)
        ->and(json_decode($auditEvents->last(), true))->toBe([
            'before' => ['locale' => 'fr', 'timezone' => 'Europe/London'],
            'after' => $updatedRegion,
        ]);

    expect(fn () => $setup->updateRegion($owner, $otherTeam, $updatedRegion))
        ->toThrow(HttpException::class);
});

it('denies company setup outside the owning team', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->users()->attach($member, ['role' => 'editor']);

    $this->actingAs($member);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    expect(EnterpriseSetup::canAccess())->toBeFalse();

    Livewire::test(EnterpriseSetup::class)->assertForbidden();

    expect(fn () => app(CompanySetup::class)->provision($member, $team, 'Forbidden', 'GBP'))
        ->toThrow(HttpException::class);
    expect(fn () => app(CompanySetup::class)->updateRegion($member, $team, ['locale' => 'en', 'timezone' => 'UTC']))
        ->toThrow(HttpException::class);

    $this->assertDatabaseCount('enterprise_company_setups', 0);
});

it('rolls back the legal entity and billing account when provisioning fails', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'enterprise_company_setups')) {
            throw new RuntimeException('Company setup failed before commit');
        }
    });

    $setup = app(CompanySetup::class);

    expect(fn () => $setup->provision($owner, $team, 'Acme UK', 'GBP', ['locale' => 'fr', 'timezone' => 'Europe/London']))
        ->toThrow(RuntimeException::class, 'Company setup failed before commit');

    expect($setup->region($team))->toBe(CompanyRegionalSettings::defaults());
    $this->assertDatabaseMissing('activity_log', ['event' => 'enterprise.company_region.updated']);

    $this->assertDatabaseCount('enterprise_company_setups', 0);
    $this->assertDatabaseCount('accounting_legal_entities', 0);
    $this->assertDatabaseCount('accounting_books', 0);
    $this->assertDatabaseCount('billing_accounts', 0);
});

it('shows company setup status on the app panel for a team member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->users()->attach($member, ['role' => 'editor']);
    $member->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($member);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);

    expect($panel->getPages())->toContain(CompanyWorkspace::class);

    $this->get(CompanyWorkspace::getUrl(panel: 'app'))
        ->assertSuccessful()
        ->assertSee('Company workspace')
        ->assertSee($team->name)
        ->assertSee('Ask your company administrator');

    $setup = app(CompanySetup::class);
    $setup->provision($owner, $team, 'Acme UK', 'GBP');

    // A company provisioned before regional defaults has a setup row and no stored region.
    DB::table('scoped_settings')->where('scope_type', 'team')->where('scope_id', $team->id)
        ->where('key', (new CompanyRegionalSettings())->key())->delete();
    config(['localization.site_locale' => 'fr', 'localization.site_timezone' => 'Europe/Paris']);
    $fallback = ['locale' => 'fr', 'timezone' => 'Europe/Paris'];

    expect($setup->region($team))->toBe($fallback);
    $setup->updateRegion($owner, $team, $fallback);
    config(['localization.site_locale' => 'de', 'localization.site_timezone' => 'Europe/Berlin']);

    Livewire::test(CompanyWorkspace::class)
        ->assertSee('Billing and accounting setup is complete.')
        ->assertSee('Default locale')
        ->assertSee('Europe/Paris');

    $team->users()->detach($member);
    $this->actingAs($member->fresh());

    Livewire::test(CompanyWorkspace::class)->assertForbidden();
});
