<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Unit;
use App\Models\User;
use App\Vision\Contracts\FridgeReader;
use App\Vision\Drafts\SpottedItem;
use App\Vision\FridgePhoto;
use App\Vision\VisionUnavailable;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The rules a photograph of a shelf has to keep.
 *
 * The reader is faked throughout: what a live model says about a given picture
 * changes between runs, and none of the rules below are about its eyesight.
 * They are about what this app is willing to do with the answer.
 */
class FridgePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        config()->set('vision.key', 'test-key');

        $this->user = User::factory()->create();
    }

    public function test_a_photo_never_invents_a_product(): void
    {
        // The worst thing this feature could do. A shelf is full of things the
        // catalogue has no name for, and each one created as a product would
        // permanently own its spelling as an alias — after which recipe import
        // resolves real ingredient lines onto it. See the "Sos:" incident.
        $this->see([
            new SpottedItem('marchewka'),
            new SpottedItem('pojemnik na resztki z wczoraj'),
            new SpottedItem('coś w foliowej torebce'),
        ]);

        $before = Ingredient::query()->count();

        $spotted = $this->read();

        $this->assertSame($before, Ingredient::query()->count());
        $this->assertCount(3, $spotted);
        $this->assertNotNull($spotted[0]['ingredientId']);
        $this->assertNull($spotted[1]['ingredientId']);
        $this->assertNull($spotted[2]['ingredientId']);
    }

    public function test_an_unmatched_line_keeps_what_the_model_wrote(): void
    {
        // Nothing is dropped: the raw text is the evidence somebody points at
        // the right product with, exactly like a recipe line's raw_text.
        $this->see([new SpottedItem('coś w foliowej torebce')]);

        $this->assertSame('coś w foliowej torebce', $this->read()[0]['spotted']);
    }

    public function test_the_same_product_twice_becomes_one_row(): void
    {
        // Two tubs on one shelf. Two rows would race each other on save, and
        // the pantry keeps one row per product per place.
        $this->see([
            new SpottedItem('marchewka', 2.0, 'piece'),
            new SpottedItem('marchewka', 3.0, 'piece'),
        ]);

        $spotted = $this->read();

        $this->assertCount(1, $spotted);
        $this->assertSame(5.0, $spotted[0]['quantity']);
    }

    public function test_two_amounts_that_cannot_be_added_become_no_amount(): void
    {
        // "Some, amount unknown" is the honest answer; a number nobody stated
        // is not. Null is infectious here for the same reason it is everywhere.
        $this->see([
            new SpottedItem('marchewka', 2.0, 'piece'),
            new SpottedItem('marchewka', 300.0, 'g'),
        ]);

        $spotted = $this->read();

        $this->assertCount(1, $spotted);
        $this->assertNull($spotted[0]['quantity']);
    }

    public function test_an_amount_with_no_unit_is_not_written_down(): void
    {
        // The pantry refuses one, so a bare number is discarded rather than
        // paired with a measure nobody stated.
        $this->see([new SpottedItem('marchewka', 3.0)]);

        $this->assertNotSame(3.0, $this->read()[0]['quantity']);
    }

    public function test_a_line_with_no_measure_is_proposed_as_one_of_it(): void
    {
        /*
         * A photograph is a picture of *things*, so "1 szt." is the one amount
         * it can suggest without inventing anything — and the row arrives
         * usable rather than as two empty fields to fill in by hand. The
         * product's own default measure would offer "1 g marchewki" instead,
         * which is a number nobody would mean.
         */
        $this->see([new SpottedItem('marchewka')]);

        $spotted = $this->read();

        $this->assertSame(1.0, $spotted[0]['quantity']);
        $this->assertSame(Unit::query()->where('code', 'piece')->value('id'), $spotted[0]['unitId']);
    }

    public function test_a_measure_the_model_did_state_is_kept(): void
    {
        // The suggestion above must never overwrite an answer. A label read off
        // a tub is worth more than anything this app can guess.
        $this->see([new SpottedItem('marchewka', 300.0, 'g')]);

        $spotted = $this->read();

        $this->assertSame(300.0, $spotted[0]['quantity']);
        $this->assertSame(Unit::query()->where('code', 'g')->value('id'), $spotted[0]['unitId']);
    }

    public function test_an_unmatched_line_is_not_given_an_amount(): void
    {
        // There is no product to hold it, and the row cannot be saved at all
        // until somebody points it at one. An amount there would be furniture.
        $this->see([new SpottedItem('coś w foliowej torebce')]);

        $spotted = $this->read();

        $this->assertNull($spotted[0]['quantity']);
        $this->assertNull($spotted[0]['unitId']);
    }

    public function test_a_photo_that_cannot_be_read_is_an_error_on_the_form(): void
    {
        $this->app->bind(FridgeReader::class, fn (): FridgeReader => new class implements FridgeReader
        {
            public function read(string $image, string $mimeType, array $unitCodes): array
            {
                throw new VisionUnavailable('down');
            }
        });

        $this->actingAs($this->user)
            ->post(route('pantry.photo.read'), ['photo' => $this->photo()])
            ->assertSessionHasErrors('photo');
    }

    public function test_nothing_is_saved_until_it_is_confirmed(): void
    {
        $this->see([new SpottedItem('marchewka', 2.0, 'piece')]);

        $this->read();

        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_confirming_puts_the_chosen_rows_on_the_shelf(): void
    {
        $carrot = $this->ingredient('Marchew');

        $this->actingAs($this->user)
            ->post(route('pantry.photo.confirm'), [
                'items' => [[
                    'ingredient_id' => $carrot->id,
                    'location' => StorageLocation::Fridge->value,
                    'quantity' => 2,
                    'unit_id' => Unit::query()->where('code', 'piece')->value('id'),
                ]],
            ])
            ->assertRedirect();

        $item = PantryItem::query()->sole();

        $this->assertSame($this->user->id, $item->user_id);
        $this->assertSame($carrot->id, $item->ingredient_id);
        $this->assertSame(2.0, $item->quantity);
    }

    public function test_confirming_the_same_product_twice_keeps_one_row(): void
    {
        $carrot = $this->ingredient('Marchew');
        $piece = Unit::query()->where('code', 'piece')->value('id');

        foreach ([2, 5] as $quantity) {
            $this->actingAs($this->user)->post(route('pantry.photo.confirm'), [
                'items' => [[
                    'ingredient_id' => $carrot->id,
                    'location' => StorageLocation::Fridge->value,
                    'quantity' => $quantity,
                    'unit_id' => $piece,
                ]],
            ]);
        }

        // A photograph states what is on the shelf rather than adding to it —
        // the same meaning the kitchen's own form has.
        $this->assertSame(1, PantryItem::query()->count());
        $this->assertSame(5.0, PantryItem::query()->sole()->quantity);
    }

    public function test_the_camera_is_not_there_without_a_key(): void
    {
        // 404 rather than 403, like registration and another account's shelves:
        // a 403 confirms there is something here.
        config()->set('vision.key', '');

        $this->actingAs($this->user)
            ->post(route('pantry.photo.read'), ['photo' => $this->photo()])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get(route('pantry.index'))
            ->assertInertia(fn ($page) => $page->where('photoEnabled', false));
    }

    public function test_a_stranger_cannot_read_a_shelf(): void
    {
        $this->post(route('pantry.photo.read'), ['photo' => $this->photo()])
            ->assertRedirect(route('login'));
    }

    /**
     * @param  list<SpottedItem>  $items
     */
    private function see(array $items): void
    {
        $this->app->bind(FridgeReader::class, fn (): FridgeReader => new class($items) implements FridgeReader
        {
            /**
             * @param  list<SpottedItem>  $items
             */
            public function __construct(private readonly array $items) {}

            public function read(string $image, string $mimeType, array $unitCodes): array
            {
                return $this->items;
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        return $this->app->make(FridgePhoto::class)->read('bytes', 'image/jpeg');
    }

    /**
     * Declared rather than drawn: this environment's GD has no `imagejpeg`, and
     * none of these tests care what is in the picture.
     */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->create('polka.jpg', 64, 'image/jpeg');
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->sole();
    }
}
