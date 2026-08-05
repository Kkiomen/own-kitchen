<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recipes_are_behind_the_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_a_recipe_page_is_behind_the_login_too(): void
    {
        $this->get('/przepis/cokolwiek')->assertRedirect(route('login'));
    }

    public function test_signing_in_lets_the_recipes_through(): void
    {
        $user = User::factory()->create(['password' => 'sekretne-haslo-123']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'sekretne-haslo-123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_signs_nobody_in(): void
    {
        $user = User::factory()->create(['password' => 'sekretne-haslo-123']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'nie-to-haslo',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * The same message for a wrong password and an unknown address, so the form
     * cannot be used to find out who has an account here.
     */
    public function test_an_unknown_address_gives_nothing_away(): void
    {
        $user = User::factory()->create(['password' => 'sekretne-haslo-123']);

        $message = 'Nie pasuje do żadnego konta.';

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'nie-to-haslo',
        ])->assertSessionHasErrors(['email' => $message]);

        $this->post(route('login'), [
            'email' => 'nikt@example.test',
            'password' => 'nie-to-haslo',
        ])->assertSessionHasErrors(['email' => $message]);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
