<?php

use App\Enterprise\LookupCmsSite;
use App\Filament\Pages\CmsSite;
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
    config(['services.cms' => [
        'url' => 'https://cms.example.test', 'token' => 'cms-test-token',
        'team_id' => $this->team->id, 'site_id' => 12, 'site_key' => 'handbook',
    ]]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);
    Http::preventStrayRequests();
});

it('displays the configured CMS site and channels without upstream settings', function () {
    Http::fake(['https://cms.example.test/api/v1/sites/handbook' => Http::response(['data' => [
        'type' => 'cms-site', 'id' => '12', 'key' => 'handbook', 'name' => 'Company handbook',
        'status' => 'active', 'default_locale' => 'en-GB',
        'settings' => ['internal' => 'hidden-setting'],
        'channels' => [['type' => 'cms-channel', 'site_id' => '12', 'name' => 'Staff portal']],
    ]])]);

    expect(Filament::getPanel('admin')->getPages())->toContain(CmsSite::class);
    $page = Livewire::test(CmsSite::class)->call('refreshSite')->assertHasNoErrors()
        ->assertSee('Company handbook')->assertSee('en-GB')->assertSee('Staff portal')->assertDontSee('hidden-setting');
    expect($page->snapshot)->not->toContain('cms-test-token');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://cms.example.test/api/v1/sites/handbook'
        && $request->hasHeader('Authorization', 'Bearer cms-test-token'));
});

it('rejects an invalid site key and denies another company owner before any request', function () {
    config(['services.cms.site_key' => '../untrusted']);
    expect(fn () => app(LookupCmsSite::class)->handle())->toThrow(HttpException::class);
    Livewire::test(CmsSite::class)->assertForbidden();

    config(['services.cms.site_key' => 'handbook']);
    $other = User::factory()->create();
    $otherTeam = Team::factory()->create(['user_id' => $other->id]);
    $this->actingAs($other);
    Filament::setTenant($otherTeam);
    Livewire::test(CmsSite::class)->assertForbidden();
    Http::assertNothingSent();
});

it('clears previous site data when the scope or the response changes', function () {
    $site = ['type' => 'cms-site', 'id' => '12', 'key' => 'handbook', 'name' => 'Company handbook',
        'status' => 'active', 'default_locale' => 'en-GB', 'channels' => []];
    Http::fake(['https://cms.example.test/api/v1/sites/handbook' => Http::sequence()
        ->push(['data' => $site])
        ->push(['data' => [...$site, 'id' => '13', 'name' => 'Other site']])
        ->push(['data' => [...$site, 'channels' => [['type' => 'cms-channel', 'site_id' => '13', 'name' => 'Other channel']]]])
        ->pushFailedConnection()]);

    Livewire::test(CmsSite::class)->call('refreshSite')->assertSee('No channels.')
        ->call('refreshSite')->assertHasErrors('site')->assertDontSee('Company handbook')->assertDontSee('Other site')
        ->call('refreshSite')->assertHasErrors('site')->assertDontSee('Other channel')
        ->call('refreshSite')->assertHasErrors(['site' => 'CMS is temporarily unavailable.']);
});
