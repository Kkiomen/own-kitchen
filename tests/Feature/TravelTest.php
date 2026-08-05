<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The travel screen reads another app of ours over HTTP, so the rules worth
 * pinning are the ones about that boundary: what happens to its numbers on the
 * way in, and what happens when it is not there at all.
 */
class TravelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_it_shows_the_board_the_deals_app_answers_with(): void
    {
        Http::fake([
            'deals.test/api/v1/dashboard*' => Http::response($this->board()),
            'deals.test/api/v1/meta*' => Http::response($this->meta()),
        ]);

        $this->actingAs($this->user)
            ->get('/podroz')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Travel/Index')
                ->where('available', true)
                ->has('deals', 1)
                ->where('deals.0.title', 'Wrocław → Oslo')
                ->where('deals.0.destination.city', 'Oslo-Torp')
                ->where('meta.preferredOrigin', 'WRO')
            );
    }

    public function test_prices_cross_as_integer_grosze(): void
    {
        Http::fake([
            'deals.test/api/v1/dashboard*' => Http::response($this->board()),
            'deals.test/api/v1/meta*' => Http::response($this->meta()),
        ]);

        $this->actingAs($this->user)
            ->get('/podroz')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 123.6 zł, and never a float on its way to the browser.
                ->where('deals.0.price', 12360)
                ->where('deals.0.typicalPrice', 24200)
                ->where('totals.round_trip.cheapest', 12360)
                // A rating is not money: converting it would show "0,60 zł" as
                // a quality bar.
                ->where('thresholds.score', 60)
                ->where('thresholds.round_trip', 60000)
            );
    }

    public function test_it_forwards_the_filters_and_renders_the_ones_that_took_effect(): void
    {
        Http::fake([
            'deals.test/api/v1/dashboard*' => Http::response([
                ...$this->board(),
                // The API drops what it cannot use and echoes back the rest.
                'sort' => 'price',
                'origin' => 'WRO',
                'type' => null,
            ]),
            'deals.test/api/v1/meta*' => Http::response($this->meta()),
        ]);

        $this->actingAs($this->user)
            ->get('/podroz?sort=price&origin=wro&weekends=1&type=nonsense')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'price')
                ->where('filters.origin', 'WRO')
                // Not what was asked for — what the far end honoured.
                ->where('filters.type', null)
            );

        // Read off the URL, not the body: these are GETs and everything is in
        // the query string.
        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, '/api/v1/dashboard')
                && str_contains($url, 'sort=price')
                // Lower case in the link, IATA on the wire.
                && str_contains($url, 'origin=WRO')
                && str_contains($url, 'weekends=1');
        });
    }

    public function test_half_a_holiday_is_not_sent(): void
    {
        Http::fake([
            'deals.test/api/v1/*' => Http::response($this->board()),
        ]);

        $this->actingAs($this->user)->get('/podroz?from=2026-09-12')->assertOk();

        Http::assertSent(
            fn ($request): bool => ! str_contains($request->url(), '/api/v1/dashboard')
                || ! str_contains($request->url(), 'from='),
        );
    }

    public function test_an_unreachable_deals_app_is_a_message_rather_than_an_error(): void
    {
        Http::fake(['deals.test/*' => Http::response('', 500)]);

        $this->actingAs($this->user)
            ->get('/podroz')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The screen has to tell "nie mogę się połączyć" apart from
                // "nic nie pasuje" — they are opposite problems.
                ->where('available', false)
                ->has('deals', 0)
            );
    }

    public function test_it_is_behind_the_household_login(): void
    {
        $this->get('/podroz')->assertRedirect('/logowanie');
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        return [
            'deals' => [[
                'id' => 'c13a2dd1',
                'source' => 'ryanair-return',
                'type' => 'round_trip',
                'title' => 'Wrocław → Oslo',
                'price' => 123.6,
                'currency' => 'PLN',
                'url' => 'https://example.test/deal',
                'origin' => ['code' => 'WRO', 'city' => 'Wrocław', 'country' => 'PL'],
                'destination' => ['code' => 'TRF', 'city' => 'Oslo-Torp', 'country' => 'NO'],
                'departs_at' => '2026-10-29T22:30:00+00:00',
                'returns_at' => '2026-11-01T06:15:00+00:00',
                'published_at' => null,
                'weekend' => true,
                'steal' => true,
                'typical_price' => 242,
                'discount' => 49,
                'days' => 3,
                'board' => null,
                'hotel_stars' => null,
                'trip_destination' => null,
                'hotel' => null,
                'departure_cities' => [],
                'dates' => [],
                'highlights' => [],
                'has_details' => true,
                'score' => 88,
                'price_per_day' => 41.2,
            ]],
            'sort' => 'score',
            'type' => null,
            'weekends' => false,
            'steals' => false,
            'origin' => null,
            'destination' => null,
            'from' => null,
            'to' => null,
            'undated_trips' => [],
            'airports' => [
                'origins' => [['code' => 'WRO', 'label' => 'Wrocław']],
                'destinations' => [['code' => 'TRF', 'label' => 'Oslo-Torp']],
            ],
            'totals' => [
                'round_trip' => ['count' => 37566, 'cheapest' => 123.6],
            ],
            'thresholds' => ['round_trip' => 600, 'score' => 60],
            'currency' => 'PLN',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'sorts' => ['newest', 'score', 'price'],
            'types' => ['flight', 'round_trip', 'trip'],
            'boards' => ['all_inclusive', 'breakfast'],
            'currency' => 'PLN',
            'preferred_origin' => 'WRO',
            'window_days' => 90,
        ];
    }
}
