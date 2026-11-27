<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ShippingQuoteTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Customer', 'Admin', 'Staff'] as $roleName) {
            Role::query()->create(['role_name' => $roleName]);
        }

        config([
            'services.shipping.external_enabled' => true,
            'services.shipping.provider' => 'nominatim',
            'services.shipping.routing_provider' => 'osrm',
            'services.shipping.farm_latitude' => 10.77197,
            'services.shipping.farm_longitude' => 106.70135,
            'services.shipping.rate_per_km' => 5000,
            'services.shipping.geocode_cache_ttl' => 3600,
            'services.shipping.connect_timeout' => 1,
            'services.shipping.timeout' => 2,
            'services.nominatim.base_url' => 'https://nominatim.test',
            'services.nominatim.user_agent' => 'OrganicFarmEcommerceTest/1.0',
            'services.osrm.base_url' => 'https://osrm.test',
        ]);

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_request_requires_a_delivery_address(): void
    {
        Http::fake();

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_customer_can_calculate_a_shipping_fee_using_geocoding_and_osrm(): void
    {
        $this->fakeSuccessfulProviders(5234);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 5.234)
            ->assertJsonPath('shipping_rate', 5000)
            ->assertJsonPath('shipping_fee', 26170)
            ->assertJsonStructure(['quote_token']);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/route/v1/driving/106.70135,10.77197;106.75,10.8')
        );
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'nominatim.test/search')
            && $request->data()['q'] === '100 Demo Street, Example District'
            && $request->hasHeader('User-Agent', 'OrganicFarmEcommerceTest/1.0')
        );
    }

    public function test_shipping_fee_rounds_to_the_nearest_dong_using_unrounded_meters(): void
    {
        $this->fakeSuccessfulProviders(5234.5);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 5.235)
            ->assertJsonPath('shipping_fee', 26173);
    }

    public function test_geocoder_not_finding_an_address_returns_validation_error(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => 'Synthetic Address With No Match',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_osrm_no_route_returns_validation_error(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'NoRoute',
                'routes' => [],
            ], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_geocoding_timeout_returns_gateway_timeout(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::failedConnection('simulated timeout'),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(504);

        Http::assertSentCount(2);
    }

    public function test_geocoding_retries_a_transient_connection_failure(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::sequence()
                ->pushFailedConnection('simulated timeout')
                ->push([
                    ['lat' => '10.8', 'lon' => '106.75'],
                ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 5234]],
            ], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 5.234)
            ->assertJsonPath('shipping_fee', 26170);

        Http::assertSentCount(3);
    }

    public function test_geocoding_retries_a_transient_server_error(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::sequence()
                ->push(['error' => 'temporarily unavailable'], 503)
                ->push([
                    ['lat' => '10.8', 'lon' => '106.75'],
                ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 5234]],
            ], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 5.234)
            ->assertJsonPath('shipping_fee', 26170);

        Http::assertSentCount(3);
    }

    public function test_osrm_timeout_returns_gateway_timeout(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::failedConnection('simulated timeout'),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(504);
    }

    public function test_malformed_provider_responses_return_bad_gateway(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(502);

        Cache::flush();
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 'not-a-distance']],
            ], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(502);

        Cache::flush();
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => ['malformed route'],
            ], 200),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(502);
    }

    public function test_geocoding_cache_avoids_repeating_nominatim_requests(): void
    {
        $this->fakeSuccessfulProviders(5234);
        $token = $this->tokenFor('Customer');

        $this->withToken($token)
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '  100 Demo Street,   Example District  ',
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertOk();

        Http::assertSentCount(3);
    }

    public function test_unauthenticated_requests_are_rejected_without_external_calls(): void
    {
        Http::fake();

        $this->postJson('/api/v1/shipping/calculate', [
            'delivery_address' => '100 Demo Street, Example District',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_staff_cannot_calculate_customer_shipping(): void
    {
        Http::fake();

        $this->withToken($this->tokenFor('Staff'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_external_services_are_disabled_by_default_without_calling_them(): void
    {
        Http::fake();
        config(['services.shipping.external_enabled' => false]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_mock_providers_return_fixed_quote_without_external_calls_when_external_services_are_disabled(): void
    {
        config([
            'services.shipping.external_enabled' => false,
            'services.shipping.provider' => 'mock',
            'services.shipping.routing_provider' => 'mock',
        ]);

        $role = Role::query()->where('role_name', 'Customer')->firstOrFail();
        $customer = User::factory()->create(['role_id' => $role->id]);
        $response = $this->withToken($customer->createToken('mock-quote-test')->plainTextToken)
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Mock Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 5.234)
            ->assertJsonPath('shipping_rate', 5000)
            ->assertJsonPath('shipping_fee', 26170)
            ->assertJsonStructure(['quote_token']);

        $verifiedQuote = app(ShippingQuoteTokenService::class)->verify(
            $response->json('quote_token'),
            '100 Mock Street, Example District',
            (int) $customer->id,
        );
        $this->assertSame(26170, $verifiedQuote['shipping_fee']);
        Http::assertNothingSent();
    }

    public function test_mock_providers_return_a_distinct_fixed_quote_for_the_second_fixture(): void
    {
        config([
            'services.shipping.external_enabled' => false,
            'services.shipping.provider' => 'mock',
            'services.shipping.routing_provider' => 'mock',
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '200 Mock Street, Example District',
            ])
            ->assertOk()
            ->assertJsonPath('distance_km', 8.5)
            ->assertJsonPath('shipping_rate', 5000)
            ->assertJsonPath('shipping_fee', 42500);

        Http::assertNothingSent();
    }

    public function test_mock_geocoder_does_not_fallback_for_unknown_addresses(): void
    {
        config([
            'services.shipping.provider' => 'mock',
            'services.shipping.routing_provider' => 'mock',
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => 'Unknown Mock Address',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);

        Http::assertNothingSent();
    }

    public function test_invalid_osrm_configuration_fails_before_geocoding(): void
    {
        Http::fake();
        config(['services.osrm.base_url' => 'http://osrm.test']);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Cấu hình URL OSRM chưa đầy đủ hoặc không hợp lệ.');

        Http::assertNothingSent();
    }

    public function test_mock_router_returns_no_route_for_unlisted_fixture_coordinates(): void
    {
        config([
            'services.shipping.provider' => 'mock',
            'services.shipping.routing_provider' => 'mock',
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '999 Mock Street, No Route',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_address']);

        Http::assertNothingSent();
    }

    public function test_mock_providers_are_rejected_outside_local_and_testing(): void
    {
        config([
            'services.shipping.provider' => 'mock',
            'services.shipping.routing_provider' => 'mock',
        ]);
        $this->app->instance('env', 'production');

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Mock Street, Example District',
            ])
            ->assertStatus(503);

        $this->app->instance('env', 'testing');
        Http::assertNothingSent();
    }

    public function test_missing_origin_configuration_fails_before_geocoding(): void
    {
        Http::fake();
        config(['services.shipping.farm_latitude' => null]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Cấu hình tọa độ điểm xuất phát bị thiếu hoặc không hợp lệ.');

        Http::assertNothingSent();
    }

    public function test_nominatim_rate_limit_is_not_retried(): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([], 429),
        ]);

        $this->withToken($this->tokenFor('Customer'))
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(503);

        Http::assertSentCount(1);
    }

    private function fakeSuccessfulProviders(float $distanceMeters): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => $distanceMeters]],
            ], 200),
        ]);
    }

    private function tokenFor(string $roleName): string
    {
        $role = Role::query()->where('role_name', $roleName)->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);

        return $user->createToken('shipping-test')->plainTextToken;
    }
}
