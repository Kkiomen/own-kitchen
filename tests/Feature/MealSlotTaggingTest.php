<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogue\TagMealSlots;
use App\Enums\MealSlot;
use App\Models\Category;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Planning\PlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MealSlotTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_title_names_the_meal_it_suits(): void
    {
        $this->recipe('Owsianka z bananem');

        $this->assertSame(['breakfast'], $this->slotsOf('Owsianka z bananem'));
    }

    public function test_a_quick_pick_category_is_the_strongest_signal(): void
    {
        $recipe = $this->recipe('Coś zupełnie osobliwego');
        $recipe->categories()->attach($this->category('zupy'));

        $this->assertContains('lunch', $this->slotsOf($recipe->title));
    }

    /**
     * The title rule that would otherwise catch it: "zupe" is inside
     * "zupełnie". Whole words, always — the lesson `categories.php` learned.
     */
    public function test_a_title_needle_matches_whole_words_only(): void
    {
        $this->recipe('Coś zupełnie osobliwego');

        $this->assertSame([], $this->slotsOf('Coś zupełnie osobliwego'));
    }

    public function test_a_source_tag_naming_the_meal_counts(): void
    {
        $recipe = $this->recipe('Danie bez rozpoznawalnej nazwy');
        $recipe->tags()->attach(Tag::query()->create(['name' => 'Kolacje', 'slug' => 'kolacje']));

        $this->assertContains('dinner', $this->slotsOf($recipe->title));
    }

    /** A dish may be two meals; forcing one home makes the other impossible. */
    public function test_a_recipe_can_suit_several_meals(): void
    {
        $this->recipe('Omlet ze szpinakiem');

        $this->assertSame(
            ['breakfast', 'dinner'],
            $this->slotsOf('Omlet ze szpinakiem'),
        );
    }

    /** A cheesecake is not supper, whatever words its title shares with one. */
    public function test_sweet_baking_is_disqualified_from_the_savoury_meals(): void
    {
        $recipe = $this->recipe('Sernik z tostami');
        $recipe->categories()->attach($this->category('desery'));

        $slots = $this->slotsOf($recipe->title);

        $this->assertContains('snack', $slots);
        $this->assertNotContains('dinner', $slots);
        $this->assertNotContains('breakfast', $slots);
    }

    /** `is_meal_prep` is the source saying "carried" — that is the lunch box. */
    public function test_a_meal_prep_dish_is_a_second_breakfast(): void
    {
        $this->recipe('Danie do pudełka', mealPrep: true);

        $this->assertContains('second_breakfast', $this->slotsOf('Danie do pudełka'));
    }

    /** Editing the rules and re-running is the workflow, so it must not pile up. */
    public function test_re_running_replaces_rather_than_accumulates(): void
    {
        $this->recipe('Owsianka z bananem');

        $this->tag();
        $this->tag();

        $this->assertSame(1, DB::table('recipe_meal_slots')->count());
    }

    public function test_the_generator_fills_only_empty_meals(): void
    {
        $user = User::factory()->create();
        $planned = $this->recipe('Owsianka z bananem');
        $suggestion = $this->recipe('Jajecznica na maśle');
        $this->tag();

        MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $planned->id,
            'servings' => 2,
        ]);

        $result = $this->generator()->fill($user, [
            '2026-08-10' => [MealSlot::Breakfast],
            '2026-08-11' => [MealSlot::Breakfast],
        ]);

        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['skipped']);

        // Monday's own choice survived; Tuesday got the only other candidate.
        $this->assertSame(
            $planned->id,
            MealPlanEntry::query()->where('date', '2026-08-10 00:00:00')->value('recipe_id'),
        );
        $this->assertSame(
            $suggestion->id,
            MealPlanEntry::query()->where('date', '2026-08-11 00:00:00')->value('recipe_id'),
        );
    }

    /** One pot, two days — how this household actually eats. */
    public function test_a_meal_prep_dish_is_planned_on_two_days_running(): void
    {
        $user = User::factory()->create();
        $batch = $this->recipe('Gulasz do pudełka', mealPrep: true);
        $batch->categories()->attach($this->category('zupy'));
        $this->tag();

        $this->generator()->fill($user, [
            '2026-08-10' => [MealSlot::Lunch],
            '2026-08-11' => [MealSlot::Lunch],
        ]);

        $this->assertSame(
            [$batch->id, $batch->id],
            MealPlanEntry::query()->orderBy('date')->pluck('recipe_id')->all(),
        );
    }

    /**
     * A generator that only looked at its own seven days would offer the same
     * gulasz every Monday, and a catalogue this size never has to.
     */
    public function test_a_dish_eaten_last_week_is_not_suggested_again(): void
    {
        $user = User::factory()->create();
        $lastWeek = $this->recipe('Owsianka z bananem');
        $fresh = $this->recipe('Jajecznica na maśle');
        $this->tag();

        MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-08-03',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $lastWeek->id,
            'servings' => 2,
        ]);

        $this->generator()->fill($user, ['2026-08-10' => [MealSlot::Breakfast]]);

        $this->assertSame(
            $fresh->id,
            MealPlanEntry::query()->where('date', '2026-08-10 00:00:00')->value('recipe_id'),
        );
    }

    /** A month is the window; beyond it a dish is fair game again. */
    public function test_a_dish_eaten_two_months_ago_may_come_back(): void
    {
        $user = User::factory()->create();
        $long_ago = $this->recipe('Owsianka z bananem');
        $this->tag();

        MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $long_ago->id,
            'servings' => 2,
        ]);

        $result = $this->generator()->fill($user, ['2026-08-10' => [MealSlot::Breakfast]]);

        $this->assertSame(1, $result['added']);
    }

    public function test_a_meal_nothing_is_tagged_for_is_reported_not_faked(): void
    {
        $user = User::factory()->create();
        $this->recipe('Owsianka z bananem');
        $this->tag();

        $result = $this->generator()->fill($user, ['2026-08-10' => [MealSlot::Snack]]);

        $this->assertSame(0, $result['added']);
        $this->assertSame([MealSlot::Snack->label()], $result['empty']);
    }

    /** A week is not uniform: Saturday wants a podwieczorek, Tuesday does not. */
    public function test_each_day_gets_only_the_meals_it_asked_for(): void
    {
        $user = User::factory()->create();
        // Two breakfasts, because nothing is ever suggested twice in one week.
        $this->recipe('Owsianka z bananem');
        $this->recipe('Jajecznica na maśle');
        $this->recipe('Sernik na zimno')->categories()->attach($this->category('desery'));
        $this->tag();

        $this->generator()->fill($user, [
            '2026-08-10' => [MealSlot::Breakfast],
            '2026-08-11' => [MealSlot::Breakfast, MealSlot::Snack],
        ]);

        $this->assertSame(
            ['2026-08-10 breakfast', '2026-08-11 breakfast', '2026-08-11 snack'],
            MealPlanEntry::query()
                ->orderBy('date')
                ->orderBy('slot')
                ->get()
                ->map(fn (MealPlanEntry $entry): string => $entry->date->toDateString().' '.$entry->slot->value)
                ->all(),
        );
    }

    public function test_the_screen_asks_for_meals_day_by_day(): void
    {
        $user = User::factory()->create();
        $this->recipe('Owsianka z bananem');
        $this->tag();

        $this->actingAs($user)
            ->post(route('meal-plan.generate'), [
                'days' => [
                    ['date' => '2026-08-10', 'slots' => ['breakfast']],
                ],
                'servings' => 4,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, MealPlanEntry::query()->firstOrFail()->servings);
    }

    public function test_a_day_with_no_meals_chosen_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('meal-plan.generate'), [
                'days' => [['date' => '2026-08-10', 'slots' => []]],
            ])
            ->assertSessionHasErrors('days.0.slots');
    }

    /** A bowl of grated carrot is not supper. */
    public function test_a_side_dish_is_never_offered_as_a_meal(): void
    {
        $this->recipe('Surówka z młodej kapusty');

        $this->assertSame([], $this->slotsOf('Surówka z młodej kapusty'));
    }

    /** But a main course that comes *with* one is still a main course. */
    public function test_a_meal_served_with_a_side_keeps_its_meals(): void
    {
        $recipe = $this->recipe('Kotlety rybne z łososia z surówką z kapusty');
        $recipe->categories()->attach($this->category('ryby'));

        $this->assertContains('lunch', $this->slotsOf($recipe->title));
    }

    /**
     * The blind spot corroboration alone has: this one lands in Wieprzowina
     * because its *title* names pork, though the dish is shredded cabbage.
     */
    public function test_a_dish_that_says_what_it_accompanies_is_a_side(): void
    {
        $recipe = $this->recipe('Surówka do karkówki, chrupiąca i lekko ostra');
        $recipe->categories()->attach($this->category('wieprzowina'));

        $this->assertSame([], $this->slotsOf($recipe->title));
    }

    /** `jogurt*` reached "jogurtowo-czosnkowym", and a chicken dinner became breakfast. */
    public function test_a_yoghurt_sauce_does_not_make_a_breakfast(): void
    {
        $recipe = $this->recipe('Kurczak w sosie jogurtowo-czosnkowym z pieczonymi warzywami');
        $recipe->categories()->attach($this->category('kurczak'));

        $this->assertNotContains('breakfast', $this->slotsOf($recipe->title));
    }

    public function test_a_soup_is_never_breakfast(): void
    {
        $recipe = $this->recipe('Rosół z naleśnikowymi roladkami');
        $recipe->categories()->attach($this->category('zupy'));
        $recipe->categories()->attach($this->category('sniadania'));

        $slots = $this->slotsOf($recipe->title);

        $this->assertContains('lunch', $slots);
        $this->assertNotContains('breakfast', $slots);
    }

    /** beszamel headlines list ingredients; an ingredient is not a meal. */
    public function test_naming_an_ingredient_does_not_name_a_meal(): void
    {
        $this->recipe('Biorę ziemniaki i jajka. Tymi kotlecikami zajada się cała rodzina');

        $this->assertNotContains('breakfast', $this->slotsOf('Biorę ziemniaki i jajka. Tymi kotlecikami zajada się cała rodzina'));
    }

    /** The author said what meal it is, and nothing here knows better. */
    public function test_a_title_naming_a_meal_gets_only_that_meal(): void
    {
        $recipe = $this->recipe('Tostadas z kurczakiem to obiad idealny');
        $recipe->categories()->attach($this->category('sniadania'));

        $this->assertSame(['lunch'], $this->slotsOf($recipe->title));
    }

    public function test_a_title_naming_two_meals_gets_both(): void
    {
        $this->recipe('Biała kiełbasa na śniadanie albo na obiad');

        $this->assertSame(['breakfast', 'lunch'], $this->slotsOf('Biała kiełbasa na śniadanie albo na obiad'));
    }

    /** In a meat category a tag alone is not enough: the title must name a breakfast. */
    public function test_a_main_course_needs_a_breakfast_dish_to_be_breakfast(): void
    {
        $roast = $this->recipe('Schab pieczony w air fryerze');
        $roast->categories()->attach($this->category('wieprzowina'));
        $roast->categories()->attach($this->category('sniadania'));

        $bagel = $this->recipe('Bajgiel z boczkiem i jajkiem sadzonym');
        $bagel->categories()->attach($this->category('wieprzowina'));

        $this->assertNotContains('breakfast', $this->slotsOf($roast->title));
        $this->assertContains('breakfast', $this->slotsOf($bagel->title));
    }

    /** "Chałka z Air Fryera" was a breakfast: it is what breakfast is made from. */
    public function test_bread_you_bake_is_no_meal_at_all(): void
    {
        $recipe = $this->recipe('Chałka z Air Fryera');
        $recipe->categories()->attach($this->category('pieczywo'));
        $recipe->categories()->attach($this->category('sniadania'));

        $this->assertSame([], $this->slotsOf($recipe->title));
    }

    public function test_porridge_is_not_supper(): void
    {
        $recipe = $this->recipe('Owsianka z chia i granatem');
        $recipe->categories()->attach($this->category('sniadania'));

        $this->assertSame(['breakfast'], $this->slotsOf($recipe->title));
    }

    /** A salmon tortilla reached obiad through the fish in it. */
    public function test_a_cold_wrap_is_not_the_obiad(): void
    {
        $recipe = $this->recipe('Tortilla z wędzonym łososiem i szpinakiem');
        $recipe->categories()->attach($this->category('ryby'));

        $this->assertNotContains('lunch', $this->slotsOf($recipe->title));
    }

    public function test_preserves_are_not_a_meal(): void
    {
        $recipe = $this->recipe('Sałatka z cukinii na zimę');
        $recipe->categories()->attach($this->category('salatki'));

        $this->assertSame([], $this->slotsOf($recipe->title));
    }

    /** A stock is what the soup is made from, and "żurek bez zakwasu" is still żurek. */
    public function test_a_stock_is_not_a_meal_but_a_soup_naming_one_is(): void
    {
        $stock = $this->recipe('Jak zrobić wywar mięsny na barszcz czerwony?');
        $stock->categories()->attach($this->category('zupy'));
        $soup = $this->recipe('Żurek bez zakwasu smakuje obłędnie');
        $soup->categories()->attach($this->category('zupy'));

        $this->assertSame([], $this->slotsOf($stock->title));
        $this->assertContains('lunch', $this->slotsOf($soup->title));
    }

    public function test_a_meal_can_be_swapped_for_another_of_the_same_kind(): void
    {
        $user = User::factory()->create();
        $first = $this->recipe('Owsianka z bananem');
        $this->recipe('Jajecznica na maśle');
        $this->tag();

        $entry = MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $first->id,
            'servings' => 4,
        ]);

        $swapped = $this->generator()->swap($user, $entry);

        $this->assertNotNull($swapped);
        $this->assertNotSame($first->id, $entry->refresh()->recipe_id);
        // Swapping the dish says nothing about how many people are eating.
        $this->assertSame(4, $entry->servings);
    }

    /** With nothing else to offer, the meal is left exactly as it was. */
    public function test_a_swap_with_nothing_to_offer_changes_nothing(): void
    {
        $user = User::factory()->create();
        $only = $this->recipe('Owsianka z bananem');
        $this->tag();

        $entry = MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $only->id,
            'servings' => 2,
        ]);

        $this->assertNull($this->generator()->swap($user, $entry));
        $this->assertSame($only->id, $entry->refresh()->recipe_id);
    }

    public function test_a_note_cannot_be_swapped(): void
    {
        $user = User::factory()->create();

        $entry = MealPlanEntry::query()->create([
            'user_id' => $user->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Dinner,
            'note' => 'Kanapki',
        ]);

        $this->actingAs($user)
            ->post(route('meal-plan.swap', $entry))
            ->assertSessionHasErrors('recipe_id');
    }

    private function generator(): PlanGenerator
    {
        return $this->app->make(PlanGenerator::class);
    }

    private function tag(): void
    {
        $this->app->make(TagMealSlots::class)->run();
    }

    /**
     * @return list<string>
     */
    private function slotsOf(string $title): array
    {
        $this->tag();

        return DB::table('recipe_meal_slots')
            ->join('recipes', 'recipes.id', '=', 'recipe_meal_slots.recipe_id')
            ->where('recipes.title', $title)
            ->orderBy('slot')
            ->pluck('slot')
            ->all();
    }

    private function category(string $slug): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'position' => 0],
        );
    }

    private function recipe(string $title, bool $mealPrep = false): Recipe
    {
        return Recipe::query()->create([
            'slug' => str($title)->slug()->value(),
            'title' => $title,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/'.str($title)->slug()->value(),
            'servings' => 2,
            'is_meal_prep' => $mealPrep,
        ]);
    }
}
