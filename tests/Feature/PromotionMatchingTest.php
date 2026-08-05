<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Offers\Resolving\PromotionIngredientResolver;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a leaflet entry is allowed to become.
 *
 * Every case here is a real entry from the first live run against gazetki.pl,
 * including the three it got wrong.
 */
class PromotionMatchingTest extends TestCase
{
    use RefreshDatabase;

    private PromotionIngredientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->resolver = $this->app->make(PromotionIngredientResolver::class);
    }

    public function test_a_brand_around_a_product_still_finds_the_product(): void
    {
        $this->assertSame('Cukier', $this->match('Cukier biały'));
        $this->assertSame('Jajko', $this->match('Jaja z chowu ściółkowego Moja Kurka'));
        $this->assertSame('Łosoś', $this->match('Świeży filet z łososia atlantyckiego Marinero'));
    }

    /**
     * A sugar-free drink offered as the cheapest sugar in town. Found on the
     * first live run, on two separate entries.
     */
    public function test_a_negated_product_is_not_that_product(): void
    {
        $this->assertNull($this->match('Napój gazowany Coca-Cola Zero Cukru'));
        $this->assertNull($this->match('Napój energetyczny w puszce zero cukru Dzik'));
    }

    /**
     * The negation removes the word it negates, not the entry: this is still
     * yoghurt, and someone shopping for yoghurt should be told about it.
     */
    public function test_a_negation_leaves_the_rest_of_the_product_alone(): void
    {
        $this->assertSame('Jogurt naturalny', $this->match('Jogurt naturalny bez cukru'));
    }

    /**
     * The freebie won over the drink: an ice-cube tray matched to ice.
     */
    public function test_a_giveaway_does_not_become_the_product(): void
    {
        $this->assertNull($this->match('Aperitivo Aperol + foremka do lodu'));
    }

    public function test_dog_food_is_not_the_meat_it_names(): void
    {
        $this->assertNull($this->match('Karma dla psa wołowina Dolina Noteci'));
        $this->assertNull($this->match('Karma dla kota Gourmet Gold'));
    }

    public function test_cosmetics_are_not_the_food_they_smell_of(): void
    {
        $this->assertNull($this->match('Żel pod prysznic mleko i miód'));
        $this->assertNull($this->match('Krem do twarzy APLB'));
    }

    /**
     * A leaflet is mostly not food, and nothing here may create a product. An
     * invented row would own an alias for good and recipe import would then
     * resolve real ingredient lines onto it.
     */
    public function test_it_matches_nothing_rather_than_inventing_a_product(): void
    {
        $before = Ingredient::query()->count();

        $this->assertNull($this->match('Kosiarka akumulatorowa NAC'));
        $this->assertNull($this->match('Kołozeszyt A5/80 Oxford B-Light w kratkę'));

        $this->assertSame($before, Ingredient::query()->count());
    }

    /**
     * Two curated products, one of which is a longer spelling of the other. The
     * resolver tries longer word groups first, so the specific one has to win.
     */
    public function test_the_more_specific_product_wins(): void
    {
        $this->assertSame('Fasolka szparagowa', $this->match('Fasolka szparagowa'));
        $this->assertSame('Camembert', $this->match('Ser pleśniowy Camembert Delikate'));
    }

    private function match(string $title): ?string
    {
        return $this->resolver->resolve($title)?->name;
    }
}
