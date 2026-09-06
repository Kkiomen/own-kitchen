<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Importing\Resolving\IngredientResolver;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientResolverTest extends TestCase
{
    use RefreshDatabase;

    private IngredientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->resolver = $this->app->make(IngredientResolver::class);
    }

    public function test_it_finds_a_product_by_its_exact_name(): void
    {
        $this->assertSame('Cebula', $this->resolver->resolve('cebula')?->ingredient->name);
    }

    public function test_it_finds_a_product_through_a_declined_alias(): void
    {
        $this->assertSame('Makaron spaghetti', $this->resolver->resolve('makaronu spaghetti')?->ingredient->name);
    }

    /**
     * Polish declines the head noun and leaves the qualifier alone. Reducing every
     * word at once turned "marynaty teriyaki" into "marynata teriyaka", which
     * matches nothing, so each word has to be tried on its own as well.
     */
    public function test_it_declines_only_the_word_that_is_actually_declined(): void
    {
        $resolved = $this->resolver->resolve('marynaty teriyaki');

        $this->assertSame('Sos teriyaki', $resolved?->ingredient->name);
        $this->assertTrue($resolved?->isConfident);
    }

    public function test_a_qualifier_keeps_products_apart(): void
    {
        $this->assertSame('Mąka ziemniaczana', $this->resolver->resolve('mąki ziemniaczanej')?->ingredient->name);
        $this->assertSame('Mąka pszenna', $this->resolver->resolve('mąki pszennej')?->ingredient->name);
    }

    /**
     * A generic head noun swallows the variety that follows it unless the variety
     * claims the two-word spelling for itself.
     *
     * "ser" and "sera" are aliases of Ser żółty, and rightly so: a line reading
     * "100 g sera" means the yellow block. But that made "sera mozzarella" reduce
     * to "sera" and every named cheese in the catalogue came back as a block of
     * gouda — ~500 lines of it, found when a fridge photograph read "biały ser"
     * off a tub of curd and the kitchen gained the wrong product. The fix is the
     * dictionary rather than the resolver, so this is what has to stay true.
     */
    public function test_a_named_cheese_does_not_collapse_into_plain_cheese(): void
    {
        $this->assertSame('Mozzarella', $this->resolver->resolve('sera mozzarella')?->ingredient->name);
        $this->assertSame('Feta', $this->resolver->resolve('sera typu feta')?->ingredient->name);
        $this->assertSame('Parmezan', $this->resolver->resolve('sera parmezan')?->ingredient->name);
        $this->assertSame('Gorgonzola', $this->resolver->resolve('sera gorgonzola')?->ingredient->name);
        $this->assertSame('Ricotta', $this->resolver->resolve('sera ricotta')?->ingredient->name);
        $this->assertSame('Halloumi', $this->resolver->resolve('sera halloumi')?->ingredient->name);
        $this->assertSame('Ser kozi', $this->resolver->resolve('koziego sera')?->ingredient->name);

        // White curd is not a kind of yellow cheese, and either word order means it.
        $this->assertSame('Twaróg', $this->resolver->resolve('biały ser')?->ingredient->name);
        $this->assertSame('Twaróg', $this->resolver->resolve('sera twarogowego')?->ingredient->name);

        // Gouda, cheddar and gruyère genuinely are yellow cheese, and the bare
        // word still has to reach it — this is not a rule about every qualifier.
        $this->assertSame('Ser żółty', $this->resolver->resolve('sera cheddar')?->ingredient->name);
        $this->assertSame('Ser żółty', $this->resolver->resolve('tartego sera')?->ingredient->name);
    }

    public function test_an_unknown_product_is_created_but_marked_as_a_guess(): void
    {
        $resolved = $this->resolver->resolve('wynalazek kulinarny bez nazwy');

        $this->assertFalse($resolved?->isConfident);
        $this->assertSame(IngredientSource::Import, $resolved?->ingredient->source);
    }

    public function test_the_same_unknown_wording_does_not_create_a_second_product(): void
    {
        $first = $this->resolver->resolve('wynalazek kulinarny bez nazwy');
        $second = $this->resolver->resolve('wynalazek kulinarny bez nazwy');

        $this->assertSame($first?->ingredient->id, $second?->ingredient->id);
    }

    /**
     * A match reached by guesswork must not be written back as an alias: it would
     * become authority that no later import ever re-checks.
     */
    public function test_a_guessed_match_is_not_recorded_as_an_alias(): void
    {
        // Not in the dictionary; it can only be reached by trying sub-phrases.
        $resolved = $this->resolver->resolve('mąki pszennej pełnoziarnistej');

        $this->assertSame('Mąka pszenna', $resolved?->ingredient->name);
        $this->assertTrue($resolved?->isConfident);
        $this->assertFalse(
            $resolved->ingredient->aliases()->where('alias', 'maki pszennej pelnoziarnistej')->exists(),
            'A guessed spelling must not become authority for later imports.'
        );
    }

    public function test_it_gives_up_on_an_empty_phrase(): void
    {
        $this->assertNull($this->resolver->resolve('   '));
    }

    /**
     * The alias table is unique in the database, and a resolver whose cache has
     * drifted from it used to abort the whole recipe on the insert. One real import
     * was lost this way. The spelling's existing owner wins; no second product and
     * no exception.
     */
    public function test_an_alias_the_cache_does_not_know_about_is_reused_rather_than_crashing(): void
    {
        $owner = Ingredient::query()->create([
            'slug' => 'serek-smietankowy-import',
            'name' => 'serka śmietankowego',
            'category' => IngredientCategory::Other,
            'source' => IngredientSource::Import,
            'is_staple' => false,
        ]);

        // Written straight to the database, exactly as a run that has already
        // loaded its cache would never see it.
        $this->resolver->resolve('zupełnie nowy wynalazek');
        IngredientAlias::query()->create(['ingredient_id' => $owner->id, 'alias' => 'jakis calkiem nowy wymysl']);

        $resolved = $this->resolver->resolve('jakiś całkiem nowy wymysł');

        $this->assertSame($owner->id, $resolved?->ingredient->id);
        $this->assertSame(1, IngredientAlias::query()->where('alias', 'jakis calkiem nowy wymysl')->count());
    }
}
