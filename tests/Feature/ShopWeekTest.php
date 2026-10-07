<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Enums\MealSlot;
use App\Enums\UnitDimension;
use App\Models\Ingredient;
use App\Models\IngredientNutrition;
use App\Models\MealPlanEntry;
use App\Models\PriceObservation;
use App\Models\Promotion;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Nutrition\NutritionBook;
use App\Planning\CheapestShopPlan;
use App\Planning\LeafletShops;
use App\Planning\PlanGenerator;
use App\Planning\PlanTargets;
use App\Planning\ShopOffers;
use App\Planning\WeekSummary;
use App\Pricing\PriceBook;
use App\Support\Measurement\MeasureBook;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Jadę do Biedronki — ułóż tydzień pod jej gazetkę", and its sharper form:
 * "jadę do jednego z tych sklepów, wybierz ten, w którym tydzień wyjdzie
 * najtaniej".
 */
class ShopWeekTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(ShopSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_a_dish_built_on_the_shops_offer_wins_over_an_equal_one(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->dish('Indyk z warzywami', 'Indyk');
        $this->offer('biedronka', 'Kurczak', price: 300, regular: 1000);

        $this->fill(['2027-05-03'], 'biedronka');

        $this->assertSame('Kurczak z warzywami', MealPlanEntry::query()->firstOrFail()->recipe?->title);
    }

    public function test_an_offer_elsewhere_does_not_pull_the_week(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->dish('Indyk z warzywami', 'Indyk');
        $this->offer('lidl', 'Kurczak', price: 300, regular: 1000);
        $this->offer('biedronka', 'Indyk', price: 300, regular: 1000);

        $this->fill(['2027-05-03'], 'biedronka');

        $this->assertSame('Indyk z warzywami', MealPlanEntry::query()->firstOrFail()->recipe?->title);
    }

    public function test_the_week_is_priced_at_the_offer_and_says_what_it_saves(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        // 70% off: 200 g of chicken at 20 zł a kilo is 4,00 zł, on offer 1,20 zł.
        $this->offer('biedronka', 'Kurczak', price: 300, regular: 1000);

        $offers = $this->fill(['2027-05-03'], 'biedronka');

        $week = $this->app->make(WeekSummary::class)->of($this->user, ['2027-05-03'], $this->targets(), $offers);

        $this->assertSame(1, $week->price->onOffer);
        $this->assertSame(280, $week->price->savings?->grosze);
        // The chicken on offer plus 20 g of vegetables at their usual 0,40 zł.
        $this->assertSame(160, $week->price->buys->grosze);
    }

    public function test_an_offer_dearer_than_the_usual_price_is_not_taken(): void
    {
        /*
         * A leaflet discounts the premium pack too: 25,98 zł a kilo "on offer"
         * against 20 zł usually. Somebody at the shelf takes the ordinary one,
         * and a week priced at the offer made a shop with a bigger leaflet look
         * dearer than one with a smaller.
         */
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->offer('biedronka', 'Kurczak', price: 1299, regular: 1999, packGrams: 500);

        $offers = $this->fill(['2027-05-03'], 'biedronka');

        $week = $this->app->make(WeekSummary::class)->of($this->user, ['2027-05-03'], $this->targets(), $offers);

        $this->assertSame(0, $week->price->onOffer);
        $this->assertSame(440, $week->price->buys->grosze);
    }

    public function test_of_several_shops_the_cheaper_week_is_kept_and_the_others_leave_no_trace(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->dish('Indyk z warzywami', 'Indyk');
        $this->offer('lidl', 'Kurczak', price: 100, regular: 1000);
        $this->offer('biedronka', 'Indyk', price: 900, regular: 1000);

        $this->forgetBooks();

        $plan = $this->app->make(CheapestShopPlan::class)->fill(
            $this->user,
            ['2027-05-03' => [MealSlot::Lunch]],
            2,
            $this->targets(),
            [$this->shop('biedronka')->id, $this->shop('lidl')->id],
        );

        $this->assertSame('Lidl', $plan['offers']->shopName);
        $this->assertCount(2, $plan['compared']);
        $this->assertSame('Lidl', collect($plan['compared'])->firstWhere('chosen', true)['name'] ?? null);

        // One shop's week, written once — the other trial was rolled back.
        $this->assertSame(1, MealPlanEntry::query()->count());
        $this->assertSame('Kurczak z warzywami', MealPlanEntry::query()->firstOrFail()->recipe?->title);
    }

    public function test_the_screen_can_plan_around_shops(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->offer('biedronka', 'Kurczak', price: 300, regular: 1000);

        $this->actingAs($this->user)
            ->post(route('meal-plan.generate'), [
                'days' => [['date' => '2027-05-03', 'slots' => [MealSlot::Lunch->value]]],
                'shops' => [$this->shop('biedronka')->id],
            ])
            ->assertSessionHas('generated.shop.name', 'Biedronka')
            ->assertSessionHas('generated.shop.onOffer', 1);

        $this->assertSame(1, MealPlanEntry::query()->count());
    }

    public function test_only_shops_with_a_product_on_offer_are_offered(): void
    {
        $this->dish('Kurczak z warzywami', 'Kurczak');
        $this->offer('biedronka', 'Kurczak', price: 300, regular: 1000);

        // A leaflet entry matched to nothing — a lawnmower — is not a product.
        Promotion::query()->create([
            'shop_id' => $this->shop('lidl')->id,
            'source' => 'test',
            'external_id' => 'mower',
            'title' => 'Kosiarka',
            'needs_review' => true,
            'price_minor' => 49900,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);

        $choices = $this->app->make(LeafletShops::class)->choices();

        $this->assertSame([['id' => $this->shop('biedronka')->id, 'name' => 'Biedronka', 'products' => 1]], $choices);
    }

    /**
     * @param  list<string>  $dates
     */
    private function fill(array $dates, string $shop): ShopOffers
    {
        $this->forgetBooks();

        $offers = $this->app->make(LeafletShops::class)->offersAt($this->shop($shop));

        $this->app->make(PlanGenerator::class)->fill(
            $this->user,
            array_fill_keys($dates, [MealSlot::Lunch]),
            2,
            $this->targets(),
            $offers,
        );

        return $offers;
    }

    private function targets(): PlanTargets
    {
        return new PlanTargets(people: 2, kcalPerPerson: 500, shares: [MealSlot::Lunch->value => 1.0]);
    }

    private function forgetBooks(): void
    {
        $this->app->make(MeasureBook::class)->forget();
        $this->app->make(NutritionBook::class)->forget();
        $this->app->forgetInstance(PriceBook::class);
    }

    private function shop(string $slug): Shop
    {
        return Shop::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * Two equal dishes apart from their meat: 500 kcal and 30 g of protein a
     * portion, the meat at 20 zł a kilo, a portion of vegetables beside it.
     */
    private function dish(string $title, string $meat): void
    {
        $recipe = Recipe::query()->create([
            'slug' => str($title)->slug()->value(),
            'title' => $title,
            'servings' => 1,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/'.str($title)->slug()->value(),
        ]);

        foreach ([[$meat, 100, 500, 30], [$title.' warzywa', 10, 0, 0]] as $position => [$name, $grams, $kcal, $protein]) {
            $product = Ingredient::query()->firstOrCreate(['name' => $name], [
                'slug' => str($name)->slug()->value(),
                'category' => IngredientCategory::Other,
                'source' => IngredientSource::Import,
            ]);

            IngredientNutrition::query()->firstOrCreate(['ingredient_id' => $product->id], [
                'kcal_per_100g' => $kcal,
                'protein_g_per_100g' => $protein,
            ]);

            PriceObservation::query()->firstOrCreate(['external_id' => $product->slug], [
                'source' => 'test',
                'title' => $product->name,
                'ingredient_id' => $product->id,
                'price_minor' => 2000,
                'unit_price_minor' => 2000,
                'unit_price_per' => 'mass',
                'observed_on' => now()->toDateString(),
            ]);

            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'ingredient_id' => $product->id,
                'unit_id' => Unit::query()->where('code', 'g')->value('id'),
                'quantity' => $grams,
                'raw_text' => "{$grams} g {$name}",
                'position' => $position,
            ]);
        }

        DB::table('recipe_meal_slots')->insert(['recipe_id' => $recipe->id, 'slot' => MealSlot::Lunch->value]);
    }

    private function offer(string $shop, string $product, int $price, ?int $regular = null, ?int $packGrams = null): void
    {
        $grams = Unit::query()->where('code', 'g')->firstOrFail();

        Promotion::query()->create([
            'shop_id' => $this->shop($shop)->id,
            'source' => 'test',
            'external_id' => 'offer-'.(++$this->sequence),
            'title' => $product,
            'ingredient_id' => Ingredient::query()->where('name', $product)->firstOrFail()->id,
            'needs_review' => false,
            'price_minor' => $price,
            'regular_price_minor' => $regular,
            'pack_quantity' => $packGrams,
            'pack_unit_id' => $packGrams === null ? null : $grams->id,
            'unit_price_minor' => $packGrams === null ? null : (int) round($price * 1000 / $packGrams),
            'unit_price_per' => $packGrams === null ? null : UnitDimension::Mass,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }
}
