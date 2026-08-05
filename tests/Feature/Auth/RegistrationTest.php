<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-up is open, because the app is shared with friends now. It used to close
 * itself the moment the first account existed — one household, one account,
 * everyone else joins by scanning a code — and these tests are what that
 * reversal is pinned by.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_anybody_can_create_an_account(): void
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

    /** The catalogue is what a friend joins. The kitchen is theirs alone. */
    public function test_a_new_account_starts_with_its_own_empty_kitchen(): void
    {
        $neighbour = User::factory()->create();
        $courgette = Ingredient::query()->create([
            'name' => 'Cukinia',
            'slug' => 'cukinia',
            'category' => IngredientCategory::Vegetable,
            'source' => IngredientSource::Dictionary,
        ]);

        PantryItem::query()->create([
            'user_id' => $neighbour->id,
            'ingredient_id' => $courgette->id,
            'location' => StorageLocation::Fridge,
        ]);

        $this->post(route('register'), [
            'name' => 'Znajomy',
            'email' => 'znajomy@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'sekretne-haslo-123',
        ])->assertRedirect(route('home'));

        $joined = User::query()->where('email', 'znajomy@example.test')->firstOrFail();

        $this->assertSame(0, PantryItem::query()->where('user_id', $joined->id)->count());
        $this->assertSame(1, PantryItem::query()->where('user_id', $neighbour->id)->count());
    }

    /**
     * Closed rather than merely refused: a 403 confirms there is something at
     * this address worth having.
     */
    public function test_it_can_be_closed_deliberately(): void
    {
        config()->set('household.registration_open', false);

        $this->get(route('register'))->assertNotFound();

        $this->post(route('register'), [
            'name' => 'Ktoś obcy',
            'email' => 'obcy@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'sekretne-haslo-123',
        ])->assertNotFound();

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_the_login_page_offers_sign_up_only_while_it_is_open(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', true));

        config()->set('household.registration_open', false);

        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('canRegister', false));
    }

    public function test_an_address_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'zajety@example.test']);

        $this->post(route('register'), [
            'name' => 'Ktoś inny',
            'email' => 'zajety@example.test',
            'password' => 'sekretne-haslo-123',
            'password_confirmation' => 'sekretne-haslo-123',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->count());
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
