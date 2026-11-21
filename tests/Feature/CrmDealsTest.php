<?php

use App\Enterprise\LookupCrmDeal;
use App\Filament\App\Pages\CrmDeals;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Organizations\Models\Team;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->forceFill(['current_team_id' => $this->team->id])->save();
    config(['services.crm' => [
        'url' => 'https://crm.example.test', 'token' => 'crm-test-token',
        'team_id' => $this->team->id, 'remote_team_id' => 9,
    ]]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Http::preventStrayRequests();
});

it('shows only the configured CRM team deal summary using the real API response shape', function () {
    Http::fake(['https://crm.example.test/api/v1/deals/*' => Http::sequence()
        ->push(['id' => 42, 'team_id' => 9, 'name' => 'Office fit-out', 'stage' => null, 'internal_note' => 'hidden-note'])
        ->push(['id' => 42, 'team_id' => 10, 'name' => 'Other company'])
        ->pushFailedConnection()]);

    $this->get(CrmDeals::getUrl(panel: 'app'))->assertSuccessful();
    $page = Livewire::test(CrmDeals::class)->set('data.deal', 42)->call('lookup')->assertHasNoErrors()
        ->assertSee('Office fit-out')->assertSee('Unassigned')->assertDontSee('hidden-note');
    expect($page->snapshot)->not->toContain('crm-test-token');

    $page->call('lookup')->assertHasErrors('data.deal')->assertDontSee('Office fit-out')->assertDontSee('Other company')
        ->call('lookup')->assertHasErrors(['data.deal' => 'CRM is temporarily unavailable.']);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://crm.example.test/api/v1/deals/42'
        && $request->hasHeader('Authorization', 'Bearer crm-test-token'));
});

it('requires the upstream team configuration and ownership of the mapped Enterprise company', function () {
    config(['services.crm.remote_team_id' => null]);
    expect(fn () => app(LookupCrmDeal::class)->handle(42))->toThrow(HttpException::class);
    Livewire::test(CrmDeals::class)->assertForbidden();

    config(['services.crm.remote_team_id' => 9]);
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create(['user_id' => $other->id]);
    $other->forceFill(['current_team_id' => $otherTeam->id])->save();
    $this->actingAs($other);
    Livewire::test(CrmDeals::class)->assertForbidden();
    Http::assertNothingSent();
});
