<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\DeviceLink;
use App\Models\DeviceLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The QR code is a credential for the minutes it lives, so every way it could
 * outlive its purpose is pinned down here.
 */
class DeviceLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_show_a_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('device-link.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/LinkDevice')
                ->has('joinUrl')
                ->has('expiresAt'));

        $this->assertSame(1, DeviceLinkToken::query()->count());
    }

    public function test_a_stranger_cannot_ask_for_a_code(): void
    {
        $this->get(route('device-link.create'))->assertRedirect(route('login'));
        $this->assertSame(0, DeviceLinkToken::query()->count());
    }

    /**
     * The code is only ever stored hashed: a leaked database must not hand
     * someone a working key.
     */
    public function test_the_plain_code_is_never_stored(): void
    {
        $user = User::factory()->create();
        $issued = $this->app->make(DeviceLink::class)->issueFor($user);

        $this->assertSame(0, DeviceLinkToken::query()->where('token_hash', $issued['token'])->count());
        $this->assertSame(
            hash('sha256', $issued['token']),
            DeviceLinkToken::query()->firstOrFail()->token_hash,
        );
    }

    public function test_scanning_the_code_signs_the_second_phone_in(): void
    {
        $user = User::factory()->create();
        $issued = $this->app->make(DeviceLink::class)->issueFor($user);

        $this->get(route('device-link.redeem', ['token' => $issued['token']]))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_code_works_only_once(): void
    {
        $user = User::factory()->create();
        $issued = $this->app->make(DeviceLink::class)->issueFor($user);
        $url = route('device-link.redeem', ['token' => $issued['token']]);

        $this->get($url);
        Auth::logout();

        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_code_stops_working_when_it_expires(): void
    {
        $user = User::factory()->create();
        $issued = $this->app->make(DeviceLink::class)->issueFor($user);

        $this->travel(DeviceLink::LIFETIME_MINUTES + 1)->minutes();

        $this->get(route('device-link.redeem', ['token' => $issued['token']]))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The owner pressing "generate" again must make the code they walked away
     * from useless — otherwise every code ever shown stays live.
     */
    public function test_issuing_a_new_code_kills_the_previous_one(): void
    {
        $user = User::factory()->create();
        $link = $this->app->make(DeviceLink::class);

        $first = $link->issueFor($user);
        $link->issueFor($user);

        $this->get(route('device-link.redeem', ['token' => $first['token']]))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_invented_code_signs_nobody_in(): void
    {
        User::factory()->create();

        $this->get(route('device-link.redeem', ['token' => 'zupelnie-zmyslony-kod']))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * One account, two phones: redeeming must not disturb the phone that issued
     * the code.
     */
    public function test_both_devices_end_up_on_the_same_account(): void
    {
        $user = User::factory()->create();
        $issued = $this->app->make(DeviceLink::class)->issueFor($user);

        $this->get(route('device-link.redeem', ['token' => $issued['token']]));

        $this->assertAuthenticatedAs($user);
        $this->get(route('home'))->assertOk();
    }
}
