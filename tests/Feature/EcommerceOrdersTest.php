<?php

use App\Enterprise\LookupEcommerceOrder;
use App\Filament\App\Pages\EcommerceCatalogue;
use App\Filament\App\Pages\EcommerceOrders;
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
    config(['services.ecommerce' => ['url' => 'https://store.example.test', 'token' => 'test-token', 'team_id' => $this->team->id, 'store_id' => 3]]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Http::preventStrayRequests();
});

it('shows the scoped Ecommerce order summary in the app panel', function () {
    Http::fake(['https://store.example.test/api/orders/42' => Http::response(['data' => [
        'id' => 42, 'total_amount' => '29.62', 'currency_code' => 'GBP', 'payment_status' => 'paid',
        'shipping_status' => null, 'customer_email' => 'private@example.test',
    ]])]);

    $this->get(EcommerceOrders::getUrl(panel: 'app'))->assertSuccessful();
    $page = Livewire::test(EcommerceOrders::class)->set('data.order', 42)->call('lookup')
        ->assertHasNoErrors()->assertSee('GBP 29.62')->assertSee('Pending')->assertDontSee('private@example.test');
    expect($page->snapshot)->not->toContain('test-token');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://store.example.test/api/orders/42'
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('rejects unauthorized or insecure connections before sending data', function () {
    config(['services.ecommerce.url' => 'http://store.example.test']);
    expect(fn () => app(LookupEcommerceOrder::class)->handle(42))->toThrow(HttpException::class);
    Livewire::test(EcommerceOrders::class)->assertForbidden();

    config(['services.ecommerce.url' => 'https://store.example.test']);
    config(['services.ecommerce.store_id' => null]);
    Livewire::test(EcommerceCatalogue::class)->assertForbidden();
    config(['services.ecommerce.store_id' => 3]);
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);
    $member->forceFill(['current_team_id' => $this->team->id])->save();
    $this->actingAs($member);
    Livewire::test(EcommerceOrders::class)->assertForbidden();
    Livewire::test(EcommerceCatalogue::class)->assertForbidden();

    Http::assertNothingSent();
});

it('reports lookup errors without displaying upstream content or retaining the previous result', function () {
    Http::fake(['https://store.example.test/api/orders/*' => Http::sequence()
        ->push(['data' => ['id' => 42, 'total_amount' => '1.00', 'currency_code' => 'GBP', 'payment_status' => 'paid', 'shipping_status' => 'shipped']])
        ->push(['message' => 'upstream internal details'], 404)
        ->pushFailedConnection()]);

    Livewire::test(EcommerceOrders::class)->set('data.order', 42)->call('lookup')->assertSee('Order #42')
        ->set('data.order', 43)->call('lookup')->assertHasErrors('data.order')
        ->assertDontSee('Order #42')->assertDontSee('upstream internal details')
        ->call('lookup')->assertHasErrors(['data.order' => 'Ecommerce is temporarily unavailable.']);
});

it('searches the store catalogue using its existing pagination and hides unneeded fields', function () {
    Http::fake(['https://store.example.test/api/products*' => Http::response([
        'data' => [
            ['id' => 7, 'store_id' => 3, 'name' => 'Copper lamp', 'price' => '49.00', 'supplier_cost' => '13.00'],
            ['id' => 8, 'store_id' => 3, 'name' => 'Custom lamp', 'price' => null],
        ],
        'current_page' => 2,
        'last_page' => 3,
    ])]);

    $this->get(EcommerceCatalogue::getUrl(panel: 'app'))->assertSuccessful();
    Livewire::test(EcommerceCatalogue::class)
        ->set('data.search', ' lamp ')->set('data.page', 2)->call('search')->assertHasNoErrors()
        ->assertSee('Page 2 of 3')->assertSee('Copper lamp')->assertSee('49.00')
        ->assertSee('Price varies')->assertDontSee('13.00');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET' && $query === ['search' => 'lamp', 'page' => '2', 'per_page' => '15']
            && $request->hasHeader('Authorization', 'Bearer test-token');
    });
});

it('clears catalogue results on invalid payloads and handles empty results and timeouts', function () {
    Http::fake(['https://store.example.test/api/products*' => Http::sequence()
        ->push(['data' => [], 'current_page' => 1, 'last_page' => 1])
        ->push(['data' => [['id' => 2, 'store_id' => 4, 'name' => 'Other storefront']], 'current_page' => 1, 'last_page' => 1])
        ->push(['data' => [], 'current_page' => 3, 'last_page' => 3])
        ->pushFailedConnection()]);

    Livewire::test(EcommerceCatalogue::class)->call('search')->assertSee('No products on this page.')
        ->call('search')->assertHasErrors(['data.search' => 'Ecommerce returned an invalid catalogue entry.'])
        ->assertDontSee('No products on this page.')
        ->call('search')->assertHasErrors(['data.search' => 'Ecommerce could not return this catalogue page.'])
        ->call('search')->assertHasErrors(['data.search' => 'Ecommerce is temporarily unavailable.']);
});
