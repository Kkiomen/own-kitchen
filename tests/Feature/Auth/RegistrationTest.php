<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One household, one account. Sign-up exists to create that account and then
 * gets out of the way — the second person joins by scanning a code.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_person_can_create_the_account(): void
    {
        $this->get(route('register'))->assertOk();

        $this->post(route('register'), [
            'name' => 'Mąż',
            'email' => 'maz@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'sekretne-haslo-123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Closed rather than merely refused: a 403 would confirm to a stranger that
     * this address has an account behind it.
     */
    public function test_sign_up_disappears_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->get(route('register'))->assertNotFound();

        $this->post(route('register'), [
            'name' => 'Ktoś obcy',
            'email' => 'obcy@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'sekretne-haslo-123',
        ])->assertNotFound();

        $this->assertSame(1, User::query()->count());
        $this->assertGuest();
    }

    public function test_it_can_be_reopened_deliberately(): void
    {
        User::factory()->create();
        config()->set('household.registration_open', true);

        $this->get(route('register'))->assertOk();
    }

    public function test_the_login_page_only_offers_sign_up_while_it_is_open(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', true));

        User::factory()->create();

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->post(route('register'), [
            'name' => 'Mąż',
            'email' => 'maz@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'cos-innego',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->count());
    }
}
