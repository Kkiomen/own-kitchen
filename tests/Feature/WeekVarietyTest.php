<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MealSlot;
use App\Enums\RecipeVerdict;
use App\Models\Category;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipePreference;
use App\Models\User;
use App\Planning\PlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What makes a generated week one somebody would look forward to, rather than
 * one that merely breaks no rule.
 *
 * Every rule here was found by generating real weeks and reading them: chicken
 * for lunch and chicken for supper, a fifty-minute breakfast on a Tuesday, an
 * Easter breakfast in October, Friday's pot served again as Saturday's obiad,
 * and nothing anybody could say about a dish they did not want to see again.
 */
class WeekVarietyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_a_rejected_dish_is_never_suggested(): void
    {
        $rejected = $this->dish('Owsianka z bananem', MealSlot::Breakfast);
        $other = $this->dish('Jajecznica na maśle', MealSlot::Breakfast);
        $this->verdict($rejected, RecipeVerdict::Dislike);

        $this->fill(['2026-10-12' => [MealSlot::Breakfast], '2026-10-13' => [MealSlot::Breakfast]]);

        $this->assertSame([$other->id], $this->planned());
    }

    /** "More of this" has to mean sooner than the month everything else waits. */
    public function test_a_liked_dish_may_come_back_after_a_fortnight(): void
    {
        $liked = $this->dish('Owsianka z bananem', MealSlot::Breakfast);
        $this->verdict($liked, RecipeVerdict::Like);
        $this->entry($liked, '2026-09-24', MealSlot::Breakfast);

        $this->fill(['2026-10-12' => [MealSlot::Breakfast]]);

        $this->assertSame($liked->id, $this->plannedOn('2026-10-12', MealSlot::Breakfast));
    }

    public function test_a_liked_dish_still_waits_two_weeks(): void
    {
        $liked = $this->dish('Owsianka z bananem', MealSlot::Breakfast);
        $other = $this->dish('Jajecznica na maśle', MealSlot::Breakfast);
        $this->verdict($liked, RecipeVerdict::Like);
        $this->entry($liked, '2026-10-05', MealSlot::Breakfast);

        $this->fill(['2026-10-12' => [MealSlot::Breakfast]]);

        $this->assertSame($other->id, $this->plannedOn('2026-10-12', MealSlot::Breakfast));
    }

    /**
     * Breast, thighs and wings are three different products to the dominant-
     * ingredient rule and one week of chicken to whoever is eating it.
     */
    public function test_supper_does_not_repeat_the_protein_lunch_was_built_on(): void
    {
        $this->dish('Kurczak w curry', MealSlot::Lunch, categories: ['kurczak']);
        $this->dish('Sałatka z kurczakiem', MealSlot::Dinner, categories: ['kurczak']);
        $fish = $this->dish('Sałatka z tuńczykiem', MealSlot::Dinner, categories: ['ryby']);

        $this->fill(['2026-10-12' => [MealSlot::Lunch, MealSlot::Dinner]]);

        $this->assertSame($fish->id, $this->plannedOn('2026-10-12', MealSlot::Dinner));
    }

    public function test_a_working_day_breakfast_is_quick(): void
    {
        $this->dish('Pieczona owsianka', MealSlot::Breakfast, minutes: 60);
        $quick = $this->dish('Jajecznica na maśle', MealSlot::Breakfast, minutes: 10);

        // A Tuesday.
        $this->fill(['2026-10-13' => [MealSlot::Breakfast]]);

        $this->assertSame($quick->id, $this->plannedOn('2026-10-13', MealSlot::Breakfast));
    }

    public function test_the_sunday_obiad_takes_its_time(): void
    {
        $this->dish('Szybki makaron', MealSlot::Lunch, minutes: 15);
        $roast = $this->dish('Pieczeń wieprzowa', MealSlot::Lunch, minutes: 120, categories: ['wieprzowina']);

        $this->fill(['2026-10-18' => [MealSlot::Lunch]]);

        $this->assertSame($roast->id, $this->plannedOn('2026-10-18', MealSlot::Lunch));
    }

    /** Liver is meat and quick to love, and nobody's Sunday dinner. */
    public function test_offal_is_not_a_sunday_centrepiece(): void
    {
        $this->dish('Wątróbka drobiowa z cebulą', MealSlot::Lunch, minutes: 60, categories: ['kurczak']);
        $roast = $this->dish('Kurczak z warzywami z piekarnika', MealSlot::Lunch, minutes: 60, categories: ['kurczak']);

        $this->fill(['2026-10-18' => [MealSlot::Lunch]]);

        $this->assertSame($roast->id, $this->plannedOn('2026-10-18', MealSlot::Lunch));
    }

    /** "Biała kiełbasa na wielkanocne śniadanie" is a strange Tuesday in October. */
    public function test_a_dish_for_an_occasion_waits_for_it(): void
    {
        $easter = $this->dish('Biała kiełbasa na wielkanocne śniadanie', MealSlot::Breakfast);

        $result = $this->fill(['2026-10-13' => [MealSlot::Breakfast]]);

        $this->assertSame(0, $result['added']);

        // Easter 2027 is 28 March; a week before it the dish is exactly right.
        $this->fill(['2027-03-22' => [MealSlot::Breakfast]]);

        $this->assertSame($easter->id, $this->plannedOn('2027-03-22', MealSlot::Breakfast));
    }

    public function test_a_product_out_of_season_sinks(): void
    {
        $this->dish('Owsianka z truskawkami', MealSlot::Breakfast);
        $winter = $this->dish('Owsianka z jabłkiem', MealSlot::Breakfast);

        $this->fill(['2026-12-08' => [MealSlot::Breakfast]]);

        $this->assertSame($winter->id, $this->plannedOn('2026-12-08', MealSlot::Breakfast));
    }

    /** One pot, two days — but only an obiad, and never into the weekend. */
    public function test_a_batch_is_an_obiad_on_working_days_only(): void
    {
        $batch = $this->dish('Gulasz do pudełka', MealSlot::Lunch, mealPrep: true);
        $this->dish('Pieczony kurczak', MealSlot::Lunch);
        $box = $this->dish('Kanapki do pudełka', MealSlot::Breakfast, mealPrep: true);
        $this->dish('Jajecznica na maśle', MealSlot::Breakfast);

        // Friday and Saturday.
        $this->fill([
            '2026-10-16' => [MealSlot::Breakfast, MealSlot::Lunch],
            '2026-10-17' => [MealSlot::Breakfast, MealSlot::Lunch],
        ]);

        $lunches = [$this->plannedOn('2026-10-16', MealSlot::Lunch), $this->plannedOn('2026-10-17', MealSlot::Lunch)];
        $breakfasts = [$this->plannedOn('2026-10-16', MealSlot::Breakfast), $this->plannedOn('2026-10-17', MealSlot::Breakfast)];

        $this->assertNotSame($lunches[0], $lunches[1]);
        $this->assertNotSame($breakfasts[0], $breakfasts[1]);
        $this->assertContains($batch->id, $lunches);
        $this->assertContains($box->id, $breakfasts);
    }

    /** Tofu rotates like a meat: three tofu days running was "wege" to the old rule. */
    public function test_tofu_is_not_served_on_consecutive_days(): void
    {
        $this->dish('Tofu w sosie sojowym', MealSlot::Lunch, categories: ['wege']);
        $this->dish('Kasza z tofu', MealSlot::Lunch, categories: ['wege']);
        $this->dish('Pieczona ciecierzyca z warzywami', MealSlot::Lunch, categories: ['wege']);

        $this->fill(['2026-10-12' => [MealSlot::Lunch], '2026-10-13' => [MealSlot::Lunch]]);

        $tofu = Recipe::query()->where('title', 'like', '%tofu%')->pluck('id')->map(intval(...))->all();

        $this->assertCount(1, array_intersect($tofu, $this->planned()));
    }

    /** A tofu spread at breakfast is tofu too, whichever meal it falls in. */
    public function test_tofu_at_breakfast_counts_against_tofu_for_obiad(): void
    {
        $this->dish('Pasta z tofu', MealSlot::Breakfast);
        $this->dish('Tofu w sosie sojowym', MealSlot::Lunch, categories: ['wege']);
        $chickpeas = $this->dish('Pieczona ciecierzyca z warzywami', MealSlot::Lunch, categories: ['wege']);

        // Monday's breakfast and Wednesday's obiad: two days apart, so only the
        // week's count can see the two are both tofu.
        $this->fill(['2026-10-12' => [MealSlot::Breakfast], '2026-10-14' => [MealSlot::Lunch]]);

        $this->assertSame($chickpeas->id, $this->plannedOn('2026-10-14', MealSlot::Lunch));
    }

    /** Thai soup on Monday and Thai chicken on Friday is the same dinner twice. */
    public function test_one_cuisine_does_not_take_the_week(): void
    {
        $this->dish('Zupa po tajsku', MealSlot::Lunch);
        $this->dish('Kurczak po tajsku', MealSlot::Lunch);
        $polish = $this->dish('Kotlety mielone', MealSlot::Lunch);

        // Saturday and Sunday, so neither day's obiad runs on into the next.
        $this->fill(['2026-10-17' => [MealSlot::Lunch], '2026-10-18' => [MealSlot::Lunch]]);

        $this->assertContains($polish->id, $this->planned());
    }

    /** A pot of soup on Monday is Tuesday's obiad too — that is how a working week goes. */
    public function test_a_pot_of_soup_feeds_two_working_days(): void
    {
        $soup = $this->dish('Zupa gulaszowa', MealSlot::Lunch, categories: ['zupy']);

        $this->fill(['2026-10-12' => [MealSlot::Lunch], '2026-10-13' => [MealSlot::Lunch]]);

        $this->assertSame($soup->id, $this->plannedOn('2026-10-12', MealSlot::Lunch));
        $this->assertSame($soup->id, $this->plannedOn('2026-10-13', MealSlot::Lunch));
    }

    /** Fish does not keep: a salmon pasta reheated loses everything. */
    public function test_fish_is_not_cooked_ahead(): void
    {
        $this->dish('Zupa rybna', MealSlot::Lunch, categories: ['zupy', 'ryby']);
        $this->dish('Pieczony kurczak', MealSlot::Lunch);

        $this->fill(['2026-10-12' => [MealSlot::Lunch], '2026-10-13' => [MealSlot::Lunch]]);

        $this->assertNotSame(
            $this->plannedOn('2026-10-12', MealSlot::Lunch),
            $this->plannedOn('2026-10-13', MealSlot::Lunch),
        );
    }

    /** A working-day supper is not a second cooking. */
    public function test_a_working_day_supper_is_light(): void
    {
        $this->dish('Zapiekanka makaronowa', MealSlot::Dinner);
        $sandwich = $this->dish('Kanapki z twarożkiem', MealSlot::Dinner);

        $this->fill(['2026-10-13' => [MealSlot::Dinner]]);

        $this->assertSame($sandwich->id, $this->plannedOn('2026-10-13', MealSlot::Dinner));
    }

    public function test_the_screen_records_a_verdict_and_takes_it_back(): void
    {
        $recipe = $this->dish('Owsianka z bananem', MealSlot::Breakfast);

        $this->actingAs($this->user)
            ->put(route('recipe-verdict.update', $recipe->slug), ['verdict' => 'like'])
            ->assertRedirect();

        $this->actingAs($this->user)
            ->put(route('recipe-verdict.update', $recipe->slug), ['verdict' => 'dislike'])
            ->assertRedirect();

        // Changing one's mind replaces the verdict rather than adding a second.
        $this->assertSame(
            [RecipeVerdict::Dislike],
            RecipePreference::query()->pluck('verdict')->all(),
        );

        $this->actingAs($this->user)
            ->delete(route('recipe-verdict.destroy', $recipe->slug))
            ->assertRedirect();

        $this->assertSame(0, RecipePreference::query()->count());
    }

    public function test_a_verdict_is_one_of_two_words(): void
    {
        $recipe = $this->dish('Owsianka z bananem', MealSlot::Breakfast);

        $this->actingAs($this->user)
            ->put(route('recipe-verdict.update', $recipe->slug), ['verdict' => 'meh'])
            ->assertSessionHasErrors('verdict');
    }

    /** Another account's taste is not this household's. */
    public function test_a_verdict_belongs_to_one_account(): void
    {
        $rejected = $this->dish('Owsianka z bananem', MealSlot::Breakfast);
        $this->verdict($rejected, RecipeVerdict::Dislike, User::factory()->create());

        $this->fill(['2026-10-12' => [MealSlot::Breakfast]]);

        $this->assertSame($rejected->id, $this->plannedOn('2026-10-12', MealSlot::Breakfast));
    }

    /**
     * @param  array<string, list<MealSlot>>  $wanted
     * @return array{added: int, skipped: int, empty: list<string>, overspent: int}
     */
    private function fill(array $wanted): array
    {
        return $this->app->make(PlanGenerator::class)->fill($this->user, $wanted);
    }

    /**
     * @return list<int>
     */
    private function planned(): array
    {
        return array_values(array_unique(array_map(
            intval(...),
            MealPlanEntry::query()->where('user_id', $this->user->id)->pluck('recipe_id')->all(),
        )));
    }

    private function plannedOn(string $date, MealSlot $slot): ?int
    {
        $id = MealPlanEntry::query()
            ->where('user_id', $this->user->id)
            ->onDates([$date])
            ->where('slot', $slot->value)
            ->value('recipe_id');

        return $id === null ? null : (int) $id;
    }

    private function entry(Recipe $recipe, string $date, MealSlot $slot): void
    {
        MealPlanEntry::query()->create([
            'user_id' => $this->user->id,
            'date' => $date,
            'slot' => $slot,
            'recipe_id' => $recipe->id,
            'servings' => 2,
        ]);
    }

    private function verdict(Recipe $recipe, RecipeVerdict $verdict, ?User $user = null): void
    {
        RecipePreference::query()->create([
            'user_id' => ($user ?? $this->user)->id,
            'recipe_id' => $recipe->id,
            'verdict' => $verdict,
        ]);
    }

    /**
     * @param  list<string>  $categories
     */
    private function dish(
        string $title,
        MealSlot $slot,
        ?int $minutes = null,
        array $categories = [],
        bool $mealPrep = false,
    ): Recipe {
        $recipe = Recipe::query()->create([
            'slug' => str($title)->slug()->value(),
            'title' => $title,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/'.str($title)->slug()->value(),
            'servings' => 2,
            'total_time_minutes' => $minutes,
            'is_meal_prep' => $mealPrep,
        ]);

        foreach ($categories as $slug) {
            $recipe->categories()->attach(Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'position' => 0],
            ));
        }

        DB::table('recipe_meal_slots')->insert(['recipe_id' => $recipe->id, 'slot' => $slot->value]);

        return $recipe;
    }
}
