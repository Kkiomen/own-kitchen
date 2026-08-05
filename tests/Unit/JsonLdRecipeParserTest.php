<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Drafts\RecipeDraft;
use App\Importing\Exceptions\RecipeNotParsable;
use App\Importing\Sources\SchemaOrg\JsonLdRecipeParser;
use PHPUnit\Framework\TestCase;

/**
 * The recipe-page half of the airfryerprzepisy.pl import. Runs against pages saved
 * from the live site, so the day the site stops publishing JSON-LD this fails here
 * rather than silently importing nothing.
 */
class JsonLdRecipeParserTest extends TestCase
{
    private JsonLdRecipeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new JsonLdRecipeParser;
    }

    public function test_it_reads_the_headline_fields(): void
    {
        $draft = $this->parse('gulasz-wieprzowy-z-airfryera');

        $this->assertSame('Gulasz wieprzowy z AirFryera', $draft->title);
        $this->assertSame(4, $draft->servings);
        $this->assertSame('4 porcji', $draft->servingsLabel);
        $this->assertSame(38, $draft->totalTimeMinutes);
        $this->assertNotNull($draft->imageUrl);
    }

    public function test_it_reads_every_ingredient_line_in_order(): void
    {
        $draft = $this->parse('gulasz-wieprzowy-z-airfryera');

        $this->assertCount(18, $draft->ingredientLines);
        $this->assertSame(
            '700 g łopatki wieprzowej lub szynki wieprzowej na gulasz',
            $draft->ingredientLines[0]->rawText,
        );
        $this->assertSame('2 ząbki czosnku', $draft->ingredientLines[4]->rawText);
    }

    public function test_it_reads_the_steps(): void
    {
        $draft = $this->parse('gulasz-wieprzowy-z-airfryera');

        $this->assertCount(15, $draft->steps);
        $this->assertStringStartsWith('Pokrój mięso w kostkę', $draft->steps[0]->rawText);
    }

    public function test_it_reads_the_category_as_a_tag(): void
    {
        $draft = $this->parse('gulasz-wieprzowy-z-airfryera');

        $this->assertContains('Obiady', $draft->tags);
    }

    public function test_it_keeps_the_temperatures_a_guided_step_needs(): void
    {
        $draft = $this->parse('rabarbar-z-truskawkami-z-airfryera-pod-kruszonka');

        $text = implode(' ', array_map(static fn ($step) => $step->rawText, $draft->steps));

        $this->assertStringContainsString('160°C', $text);
    }

    public function test_a_page_without_a_recipe_is_rejected_rather_than_imported_empty(): void
    {
        $this->expectException(RecipeNotParsable::class);

        $this->parser->parseRecipe('<html><body><h1>O nas</h1></body></html>', 'https://example.test/o-nas', 'o-nas');
    }

    /**
     * The plugins differ in how they wrap the same data; a bare Recipe object, a
     * single yield value and a comma-separated keyword list must all still work.
     */
    public function test_it_reads_a_bare_recipe_object_with_the_looser_field_shapes(): void
    {
        $json = json_encode([
            '@type' => 'Recipe',
            'name' => 'Frytki z batata',
            'recipeYield' => '2 porcje',
            'keywords' => 'airfryer, fit',
            'image' => ['@type' => 'ImageObject', 'url' => 'https://example.test/frytki.jpg'],
            'prepTime' => 'PT10M',
            'cookTime' => 'PT1H5M',
            'recipeIngredient' => ['2 bataty', '1 łyżka oleju'],
            'recipeInstructions' => [
                ['@type' => 'HowToSection', 'name' => 'Frytki', 'itemListElement' => [
                    ['@type' => 'HowToStep', 'text' => 'Pokrój bataty w słupki.'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $draft = $this->parser->parseRecipe(
            '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>',
            'https://example.test/frytki',
            'frytki-z-batata',
        );

        $this->assertSame('Frytki z batata', $draft->title);
        $this->assertSame(2, $draft->servings);
        $this->assertSame(75, $draft->totalTimeMinutes, 'prepTime + cookTime stands in for a missing totalTime.');
        $this->assertSame(['airfryer', 'fit'], $draft->tags);
        $this->assertSame('https://example.test/frytki.jpg', $draft->imageUrl);
        $this->assertSame('Frytki', $draft->steps[0]->section);
    }

    /**
     * schema.org has no field for the parts of a multi-part recipe, so the plugin
     * puts them in the ingredient list as a zero-amount line ending in a colon.
     * Stored as written they become products called "Sos:"; read as sections they
     * become the thing that tells a step which of two salts it means.
     */
    public function test_a_zero_amount_line_ending_in_a_colon_is_a_section_not_an_ingredient(): void
    {
        $draft = $this->parseIngredients([
            '150 g mięsa z piersi kurczaka',
            '0.25 g soli',
            '0 g Sos czosnkowo-jogurtowy:',
            '60 g jogurtu naturalnego',
            '0.25 g soli',
        ]);

        $this->assertCount(4, $draft->ingredientLines);
        $this->assertSame('150 g mięsa z piersi kurczaka', $draft->ingredientLines[0]->rawText);
        $this->assertNull($draft->ingredientLines[1]->section);
        $this->assertSame('60 g jogurtu naturalnego', $draft->ingredientLines[2]->rawText);
        $this->assertSame('Sos czosnkowo-jogurtowy', $draft->ingredientLines[2]->section);
        $this->assertSame('Sos czosnkowo-jogurtowy', $draft->ingredientLines[3]->section);
    }

    /**
     * Dropping a line that carries a real amount would lose a product the recipe
     * uses, which the pipeline is built never to do.
     */
    public function test_a_line_with_a_real_amount_is_kept_even_when_it_ends_in_a_colon(): void
    {
        $draft = $this->parseIngredients(['2 jajka:', '1 łyżka masła']);

        $this->assertSame('2 jajka:', $draft->ingredientLines[0]->rawText);
    }

    /**
     * Plugins write the picture once as an ImageObject and point at it by "@id"
     * rather than repeating the URL. Read literally that is a bare reference, and
     * the whole catalogue imports without pictures.
     */
    public function test_it_follows_an_image_referenced_by_id_elsewhere_in_the_graph(): void
    {
        $json = json_encode(['@graph' => [
            [
                '@type' => 'ImageObject',
                '@id' => 'https://example.test/lunchbox/#primaryimage',
                'url' => 'https://example.test/lunchbox.jpg',
            ],
            [
                '@type' => 'Recipe',
                'name' => 'Lunchbox',
                'image' => ['@id' => 'https://example.test/lunchbox/#primaryimage'],
                'recipeIngredient' => ['2 jajka'],
                'recipeInstructions' => ['Ugotować.'],
            ],
        ]], JSON_THROW_ON_ERROR);

        $draft = $this->parser->parseRecipe(
            '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>',
            'https://example.test/lunchbox',
            'lunchbox',
        );

        $this->assertSame('https://example.test/lunchbox.jpg', $draft->imageUrl);
    }

    /**
     * beszamel.se.pl leaves raw newlines inside its JSON string values, which is
     * invalid JSON and used to lose the recipe entirely — five of one 250-recipe
     * batch. The site published a perfectly good recipe; only its encoding is bad.
     */
    public function test_it_still_reads_a_recipe_whose_json_has_raw_newlines_in_it(): void
    {
        $json = <<<'JSON'
            {
                "@type": "Recipe",
                "name": "Pstrąg pieczony z migdałami",
                "description": "Pierwsza linia
            druga linia",
                "recipeIngredient": ["2 pstrągi", "50 g płatków migdałowych"],
                "recipeInstructions": [{"@type": "HowToStep", "text": "Rybę oczyść i osusz."}]
            }
            JSON;

        $draft = $this->parser->parseRecipe(
            '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>',
            'https://example.test/pstrag',
            'pstrag',
        );

        $this->assertSame('Pstrąg pieczony z migdałami', $draft->title);
        $this->assertSame('Pierwsza linia druga linia', $draft->description);
        $this->assertCount(2, $draft->ingredientLines);
    }

    /**
     * @param  list<string>  $lines
     */
    private function parseIngredients(array $lines): RecipeDraft
    {
        $json = json_encode([
            '@type' => 'Recipe',
            'name' => 'Lunchbox',
            'recipeIngredient' => $lines,
            'recipeInstructions' => ['Wymieszać.'],
        ], JSON_THROW_ON_ERROR);

        return $this->parser->parseRecipe(
            '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>',
            'https://example.test/lunchbox',
            'lunchbox',
        );
    }

    private function parse(string $name): RecipeDraft
    {
        return $this->parser->parseRecipe(
            (string) file_get_contents(__DIR__."/../Fixtures/airfryerprzepisy/{$name}.html"),
            "https://airfryerprzepisy.pl/{$name}/",
            $name,
        );
    }
}
