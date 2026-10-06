<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Enums\MealSlot;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientNutrition;
use App\Models\MealPlanEntry;
use App\Models\PriceObservation;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use App\Models\User;
use App\Nutrition\NutritionBook;
use App\Nutrition\RecipeNutrition;
use App\Planning\PlanGenerator;
use App\Planning\PlanTargets;
use App\Planning\RecipeFacts;
use App\Planning\WeekSummary;
use App\Pricing\PriceBook;
use App\Support\Measurement\MeasureBook;
use App\Support\Money\Money;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Planning a week to a calorie target and a budget.
 *
 * The rules pinned here are the ones that were got wrong on real data before
 * they were written down. Two of them cost a rewrite: a week planned on
 * calories and price alone comes back as pancakes and potato cakes, and a
 * shortlist ranked by the fridge before anything else is looked at can only
 * offer what the fridge already suggests.
 */
class TargetedPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_a_day_that_does_not_add_up_is_refused_rather_than_normalised(): void
    {
        // Scaling 30/45/20 quietly to a whole would hand back a week hitting a
        // target nobody set — the same invention the calorie count exists to
        // avoid, moved into the form.
        $this->expectException(InvalidArgumentException::class);

        new PlanTargets(people: 2, kcalPerPerson: 2500, shares: [
            MealSlot::Breakfast->value => 0.3,
            MealSlot::Lunch->value => 0.45,
            MealSlot::Dinner->value => 0.2,
        ]);
    }

    public function test_it_plans_enough_helpings_to_reach_the_calories(): void
    {
        /*
         * The finding this whole design turns on: the catalogue's median portion
         * is under 500 kcal, so one portion each cannot feed anybody 2 500
         * calories a day. The planner has to choose the number of helpings, not
         * just the dish.
         */
        $this->dish('Lekka zupa', MealSlot::Lunch, kcalPerPortion: 400, protein: 20);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $entry = MealPlanEntry::query()->firstOrFail();

        // 1 200 kcal each at 400 a portion is three helpings, for two people.
        $this->assertSame(3 * 2, $entry->servings);
    }

    public function test_nothing_is_planned_as_more_than_three_helpings_each(): void
    {
        /*
         * A dish small enough to need four helpings is a dish whose "portion"
         * was never a portion. It drops out rather than being served four times,
         * which is what keeps a snack from being planned as a dinner.
         */
        $this->dish('Ciasteczko', MealSlot::Lunch, kcalPerPortion: 200, protein: 20);

        $result = $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $this->assertSame(0, $result['added']);
    }

    public function test_breakfast_is_never_more_than_two_helpings_each(): void
    {
        // Three helpings of a 400 kcal porridge would hit a 1 200 kcal morning
        // exactly — and "6 porcji owsianki" for two is not a breakfast.
        $this->dish('Owsianka', MealSlot::Breakfast, kcalPerPortion: 400, protein: 20);

        $result = $this->fill(['2027-05-03'], [MealSlot::Breakfast], kcalPerPerson: 1200);

        $this->assertSame(0, $result['added']);
    }

    /**
     * "Dorsz z porami" as three helpings each was the calories of an obiad and
     * nobody's idea of one. A cutlet comes with potatoes and a surówka, and
     * the cutlet goes back to the helpings a person actually eats.
     */
    public function test_a_main_course_is_served_with_potatoes_and_a_surowka(): void
    {
        $this->sides();
        $this->dish('Kotlet z kurczaka', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        // 1 200 kcal: 300 of potatoes, 100 of surówka and two cutlets each.
        $this->assertSame([
            ['title' => 'Kotlet z kurczaka', 'servings' => 2 * 2],
            ['title' => 'Ziemniaki z koperkiem', 'servings' => 2],
            ['title' => 'Surówka z marchewki', 'servings' => 2],
        ], $this->plateOn('2027-05-03'));
    }

    /** "Stir fry z tempehem" beside kluski śląskie was a generated obiad. */
    public function test_an_asian_main_is_served_with_rice(): void
    {
        $this->sides();
        $this->dish('Ryż z ziołami', MealSlot::Snack, kcalPerPortion: 300, protein: 6, made: IngredientCategory::Grain);
        $this->dish('Kurczak po tajsku', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $plate = array_column($this->plateOn('2027-05-03'), 'title');

        $this->assertContains('Ryż z ziołami', $plate);
        $this->assertNotContains('Ziemniaki z koperkiem', $plate);
    }

    /**
     * Zupa, then drugie danie. Every review round asked for soup two or three
     * times a week, and a soup filling enough to be the whole obiad is rare.
     */
    public function test_a_soup_comes_first_and_the_main_makes_room_for_it(): void
    {
        $this->sides();
        $this->dish('Zupa pomidorowa', MealSlot::Snack, kcalPerPortion: 300, protein: 10);
        $this->dish('Kotlet z kurczaka', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1100);

        // A Monday: three recipes at most, so the surówka waits for the weekend.
        // 300 of soup, 300 of potatoes and one cutlet each.
        $this->assertSame([
            ['title' => 'Zupa pomidorowa', 'servings' => 2],
            ['title' => 'Kotlet z kurczaka', 'servings' => 2],
            ['title' => 'Ziemniaki z koperkiem', 'servings' => 2],
        ], $this->plateOn('2027-05-03'));
    }

    /** Soup, then udka with nothing beside them, was the plate both reviewers named first. */
    public function test_when_soup_and_potatoes_do_not_both_fit_the_soup_goes(): void
    {
        $this->sides();
        $this->dish('Zupa pomidorowa', MealSlot::Snack, kcalPerPortion: 300, protein: 10);
        $this->dish('Duszone udka', MealSlot::Lunch, kcalPerPortion: 900, protein: 50, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1100);

        $plate = array_column($this->plateOn('2027-05-03'), 'title');

        $this->assertContains('Ziemniaki z koperkiem', $plate);
        $this->assertNotContains('Zupa pomidorowa', $plate);
    }

    /** Krupnik before Kung Pao is two kitchens on one table. */
    public function test_a_soup_comes_only_before_a_main_of_its_own_kitchen(): void
    {
        $this->sides();
        $this->dish('Ryż z ziołami', MealSlot::Snack, kcalPerPortion: 300, protein: 6, made: IngredientCategory::Grain);
        $this->dish('Zupa pomidorowa', MealSlot::Snack, kcalPerPortion: 300, protein: 10);
        $this->dish('Kurczak po tajsku', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1100);

        $this->assertNotContains('Zupa pomidorowa', array_column($this->plateOn('2027-05-03'), 'title'));
    }

    /**
     * The shuffle beside the potatoes used to hand back a main course sized to
     * the potatoes' calories. A side swaps for a side of its own kind.
     */
    public function test_swapping_a_side_offers_another_side_of_its_kind(): void
    {
        $this->sides();
        $kasza = $this->dish('Kasza gryczana', MealSlot::Snack, kcalPerPortion: 300, protein: 8, made: IngredientCategory::Grain);
        $this->dish('Kotlet z kurczaka', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);
        $this->dish('Gulasz wołowy', MealSlot::Lunch, kcalPerPortion: 300, protein: 25, categories: ['wolowina']);

        // A Saturday, so the plate is main, starch and surówka.
        $this->fill(['2027-05-08'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $starch = MealPlanEntry::query()
            ->whereIn('recipe_id', Recipe::query()->whereIn('title', ['Ziemniaki z koperkiem', 'Kasza gryczana'])->pluck('id'))
            ->firstOrFail();
        $before = $starch->recipe_id;
        $servings = $starch->servings;

        $generator = $this->app->make(PlanGenerator::class);
        $offered = array_map(
            static fn ($one): string => $one->title,
            $generator->alternativesFor($this->user, $starch),
        );

        $this->assertSame([$before === $kasza->id ? 'Ziemniaki z koperkiem' : 'Kasza gryczana'], $offered);

        $generator->swap($this->user, $starch);
        $starch->refresh();

        $this->assertNotSame($before, $starch->recipe_id);
        $this->assertSame($servings, $starch->servings);
    }

    /** A soup that is mostly potatoes brings the starch itself. */
    public function test_a_starchy_soup_takes_the_place_of_the_potatoes(): void
    {
        $this->sides();
        $this->dish('Zupa ziemniaczana', MealSlot::Snack, kcalPerPortion: 300, protein: 6, made: IngredientCategory::Grain);
        $this->dish('Kotlet z kurczaka', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1100);

        $this->assertNotContains('Ziemniaki z koperkiem', array_column($this->plateOn('2027-05-03'), 'title'));
    }

    /** Bread and cheese for supper was the dietitian's first complaint four rounds running. */
    public function test_a_supper_with_a_vegetable_beats_one_without(): void
    {
        $this->dish('Kanapki z serem', MealSlot::Dinner, kcalPerPortion: 400, protein: 25, made: IngredientCategory::Dairy);
        $salad = $this->dish('Sałatka z pomidorami', MealSlot::Dinner, kcalPerPortion: 400, protein: 25, made: IngredientCategory::Vegetable);

        // A Saturday, so neither is a cooked supper on a working day.
        $this->fill(['2027-05-08'], [MealSlot::Dinner], kcalPerPerson: 800);

        $this->assertSame($salad->id, MealPlanEntry::query()->value('recipe_id'));
    }

    public function test_a_soup_is_a_whole_obiad_by_itself(): void
    {
        $this->sides();
        $this->dish('Zupa z kurczakiem', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $this->assertSame(['Zupa z kurczakiem'], array_column($this->plateOn('2027-05-03'), 'title'));
    }

    /** "…z ziemniakami" already has its potatoes, whatever share of it they are. */
    public function test_a_dish_naming_its_own_potatoes_gets_no_more(): void
    {
        $this->sides();
        $this->dish('Kurczak z ziemniakami', MealSlot::Lunch, kcalPerPortion: 400, protein: 30, categories: ['kurczak']);

        $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 1200);

        $this->assertSame(['Kurczak z ziemniakami'], array_column($this->plateOn('2027-05-03'), 'title'));
    }

    public function test_a_dish_that_cannot_land_near_the_target_is_not_planned(): void
    {
        // 1 100 kcal a portion against a 300 kcal supper: one helping is nearly
        // four times too much and there is no fraction of a helping to serve.
        $this->dish('Ogromna zapiekanka', MealSlot::Dinner, kcalPerPortion: 1100, protein: 60);

        $result = $this->fill(['2027-05-03'], [MealSlot::Dinner], kcalPerPerson: 300);

        $this->assertSame(0, $result['added']);
        $this->assertSame([MealSlot::Dinner->label()], $result['empty']);
    }

    public function test_it_prefers_a_balanced_dish_over_cheap_calories(): void
    {
        /*
         * The failure that produced the protein floor. Both dishes hit the
         * target exactly and the pancakes are cheaper, so on calories and price
         * alone the pancakes win every time — and the first live week came back
         * as pancakes three mornings running and potato cakes four evenings.
         */
        $this->dish('Placki ziemniaczane', MealSlot::Dinner, kcalPerPortion: 600, protein: 6);
        $this->dish('Zapiekanka z mięsem', MealSlot::Dinner, kcalPerPortion: 600, protein: 40);

        $this->fill(['2027-05-03'], [MealSlot::Dinner], kcalPerPerson: 600);

        $this->assertSame(
            'Zapiekanka z mięsem',
            MealPlanEntry::query()->firstOrFail()->recipe?->title,
        );
    }

    public function test_a_low_protein_dish_is_still_planned_when_it_is_all_there_is(): void
    {
        // The honest half of the floor: some meals genuinely have little to
        // offer, and an empty Thursday is a worse answer than a light one.
        $this->dish('Naleśniki', MealSlot::Breakfast, kcalPerPortion: 500, protein: 4);

        $result = $this->fill(['2027-05-03'], [MealSlot::Breakfast], kcalPerPerson: 500);

        $this->assertSame(1, $result['added']);
    }

    public function test_the_budget_picks_the_cheaper_of_two_equal_dishes(): void
    {
        $this->dish('Drogie danie', MealSlot::Lunch, kcalPerPortion: 500, protein: 30, pricePerKilo: 8000);
        $this->dish('Tanie danie', MealSlot::Lunch, kcalPerPortion: 500, protein: 30, pricePerKilo: 400);

        $this->fill(
            ['2027-05-03'],
            [MealSlot::Lunch],
            kcalPerPerson: 500,
            budget: new Money(1000),
        );

        $this->assertSame(
            'Tanie danie',
            MealPlanEntry::query()->firstOrFail()->recipe?->title,
        );
    }

    public function test_a_week_is_still_planned_when_nothing_is_affordable(): void
    {
        /*
         * Refusing to plan is not a budgeting strategy. The meal is filled from
         * the cheapest thing on offer and reported as overspent, so the screen
         * can say the target was tight instead of leaving a hole in the week.
         */
        $this->dish('Drogie danie', MealSlot::Lunch, kcalPerPortion: 500, protein: 30, pricePerKilo: 9000);

        $result = $this->fill(
            ['2027-05-03'],
            [MealSlot::Lunch],
            kcalPerPerson: 500,
            budget: new Money(100),
        );

        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['overspent']);
    }

    public function test_the_week_reports_both_totals_and_what_it_could_not_price(): void
    {
        $this->dish('Danie z ceną', MealSlot::Lunch, kcalPerPortion: 500, protein: 30, pricePerKilo: 2000);
        $this->dish('Danie bez ceny', MealSlot::Dinner, kcalPerPortion: 500, protein: 30);

        $this->fill(['2027-05-03'], [MealSlot::Lunch, MealSlot::Dinner], kcalPerPerson: 1000);

        $week = $this->app->make(WeekSummary::class)->of(
            $this->user,
            ['2027-05-03'],
            $this->targets(1000),
        );

        $this->assertEqualsWithDelta(1000.0, $week->averageKcalPerPerson() ?? 0.0, 1.0);
        $this->assertGreaterThan(0, $week->price->eats->grosze);

        /*
         * Two dishes, two real ingredients each, and only one of the four has a
         * price. The unpriced are counted rather than assumed free: a total that
         * swallowed them would be too small, which is the direction that costs
         * money and the one nobody notices.
         */
        $this->assertSame(4, $week->price->products);
        $this->assertSame(2, $week->price->unpricedProducts);
        $this->assertEqualsWithDelta(0.5, $week->price->confidence(), 0.001);
    }

    public function test_a_one_ingredient_recipe_is_not_a_meal(): void
    {
        /*
         * "Jak ugotować kaszę bulgur w Air Fryer" was planned as a Monday dinner
         * by the finished generator, in a browser, twice in one week. It is
         * groats and water: an instruction, not a meal. 236 recipes tagged as
         * meals in the live catalogue have two or fewer real ingredients.
         */
        $recipe = $this->dish('Sama kasza', MealSlot::Lunch, kcalPerPortion: 500, protein: 30);

        $recipe->ingredients()->orderByDesc('position')->first()?->delete();

        $result = $this->fill(['2027-05-03'], [MealSlot::Lunch], kcalPerPerson: 500);

        $this->assertSame(0, $result['added']);
    }

    public function test_one_meal_is_not_built_from_the_same_thing_two_days_running(): void
    {
        /*
         * The failure a browser found and no unit test had: five *different*
         * pancake recipes across seven breakfasts. Every one a distinct row, no
         * repeat by the "nothing within a month" rule, and pancakes every
         * morning to anybody sitting at the table. What repeats is what a dish
         * is made of, so that is what is checked.
         *
         * Two days and two families, so the guarantee is exact: whatever the
         * first morning takes, the second has the other family available and
         * must reach for it. Asserting over more days than there are families
         * would be asserting on the shuffle — the first version of this test did
         * that and passed three times by luck.
         */
        $flour = $this->staple('mąka', IngredientCategory::Grain);
        $eggs = $this->staple('jajko', IngredientCategory::Egg);

        foreach (['Naleśniki A', 'Naleśniki B', 'Naleśniki C'] as $title) {
            $this->builtFrom($title, $flour, MealSlot::Breakfast);
        }

        foreach (['Omlet', 'Jajecznica', 'Zapiekanka jajeczna'] as $title) {
            $this->builtFrom($title, $eggs, MealSlot::Breakfast);
        }

        $this->fill(['2027-05-03', '2027-05-04'], [MealSlot::Breakfast], kcalPerPerson: 500);

        $this->assertCount(2, $this->dominantsPlanned());
    }

    public function test_a_day_is_not_three_meals_of_the_same_thing(): void
    {
        /*
         * The week rule is scoped per meal, so eggs at breakfast said nothing
         * about eggs at lunch — and a generated Thursday came back as
         * jajecznica, zapiekanka jajeczna and suflet jajeczny. Three good
         * dishes, no rule broken, and nobody would eat that day.
         */
        $eggs = $this->staple('jajko', IngredientCategory::Egg);
        $flour = $this->staple('mąka', IngredientCategory::Grain);

        foreach ([MealSlot::Breakfast, MealSlot::Lunch, MealSlot::Dinner] as $slot) {
            $this->builtFrom('Jajeczne '.$slot->value, $eggs, $slot);
            $this->builtFrom('Mączne '.$slot->value, $flour, $slot);
        }

        $this->fill(
            ['2027-05-03'],
            [MealSlot::Breakfast, MealSlot::Lunch, MealSlot::Dinner],
            kcalPerPerson: 1500,
        );

        // Two families, three meals: the third has to repeat one of them, but
        // the first two must differ. Three of a kind is what this forbids.
        $this->assertGreaterThan(1, count($this->dominantsPlanned()));
    }

    /**
     * The distinct products the planned week draws its calories from.
     *
     * @return list<int>
     */
    private function dominantsPlanned(): array
    {
        $nutrition = $this->app->make(RecipeNutrition::class);

        $dominants = MealPlanEntry::query()
            ->with(['recipe.ingredients.ingredient', 'recipe.ingredients.unit'])
            ->get()
            ->map(static fn (MealPlanEntry $entry): ?int => $entry->recipe === null
                ? null
                : $nutrition->for($entry->recipe)->dominantIngredientId)
            ->filter()
            ->unique()
            ->values();

        return array_map(intval(...), $dominants->all());
    }

    public function test_swapping_a_dish_keeps_what_the_meal_fed_you(): void
    {
        /*
         * "Dajesz dwa razy bajgle, a moja dziewczyna stwierdzi, że chce coś
         * innego." The button was already there; what it did not do was keep the
         * day's arithmetic. Carrying the old portions over to a dish of a
         * different size changes what the day feeds you without saying so —
         * four portions of a 200 kcal dish and four of a 500 kcal one are 1 200
         * calories apart.
         */
        $small = $this->dish('Bajgle', MealSlot::Breakfast, kcalPerPortion: 200, protein: 20);
        $this->dish('Tosty', MealSlot::Breakfast, kcalPerPortion: 400, protein: 20);

        $entry = MealPlanEntry::query()->create([
            'user_id' => $this->user->id,
            'date' => '2027-05-03',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $small->id,
            'servings' => 4,
        ]);

        $this->forgetBooks();

        $swapped = $this->app->make(PlanGenerator::class)->swap($this->user, $entry->fresh());

        $this->assertNotNull($swapped);

        $entry->refresh();

        // 4 × 200 kcal was the meal; 2 × 400 is the same meal made of something
        // else. The portions moved so the calories would not.
        $this->assertSame(2, $entry->servings);
        $this->assertSame(800.0, $this->plannedKcal($entry));
    }

    public function test_a_swap_offers_nothing_when_the_meal_has_no_alternative(): void
    {
        // A dead button is worse than a message, so the caller is told rather
        // than left looking at an unchanged dish.
        $only = $this->dish('Jedyne danie', MealSlot::Breakfast, kcalPerPortion: 400, protein: 20);

        $entry = MealPlanEntry::query()->create([
            'user_id' => $this->user->id,
            'date' => '2027-05-03',
            'slot' => MealSlot::Breakfast,
            'recipe_id' => $only->id,
            'servings' => 2,
        ]);

        $this->forgetBooks();

        $this->assertNull($this->app->make(PlanGenerator::class)->swap($this->user, $entry->fresh()));
    }

    private function plannedKcal(MealPlanEntry $entry): float
    {
        $recipe = $entry->recipe?->load(['ingredients.ingredient', 'ingredients.unit']);

        if ($recipe === null) {
            return 0.0;
        }

        return ($this->app->make(RecipeNutrition::class)->for($recipe)->reliableKcalPerPortion() ?? 0.0)
            * $entry->servings;
    }

    public function test_a_dish_priced_from_a_minority_of_itself_has_no_price(): void
    {
        /*
         * "Polędwica wołowa pieczona… 4,89 zł" in the alternatives sheet: the
         * beef had no reading and the onion did, so the steak was priced by its
         * garnish. A number that looks authoritative and is out by an order of
         * magnitude is worse than no number.
         */
        $dish = $this->dish('Stek', MealSlot::Lunch, kcalPerPortion: 500, protein: 30);

        // One of the two real ingredients priced: a third short of the floor.
        $garnish = $dish->ingredients()->orderByDesc('position')->first();

        PriceObservation::query()->create([
            'source' => 'test',
            'external_id' => 'garnish',
            'title' => 'dodatek',
            'ingredient_id' => $garnish?->ingredient_id,
            'price_minor' => 200,
            'unit_price_minor' => 200,
            'unit_price_per' => 'mass',
            'observed_on' => now()->toDateString(),
        ]);

        $this->forgetBooks();

        $facts = $this->app->make(RecipeFacts::class)->forRecipes([$dish->id]);

        $this->assertNull($facts[$dish->id]->costPerPortion);
    }

    public function test_the_screen_can_ask_for_a_targeted_week(): void
    {
        $this->dish('Obiad', MealSlot::Lunch, kcalPerPortion: 500, protein: 30, pricePerKilo: 2000);

        $this->actingAs($this->user)
            ->post(route('meal-plan.generate'), [
                'days' => [['date' => '2027-05-03', 'slots' => [MealSlot::Lunch->value]]],
                'targets' => [
                    'people' => 2,
                    'kcal' => 1000,
                    'budget' => 50,
                    'shares' => [MealSlot::Lunch->value => 100],
                ],
            ])
            ->assertSessionHas('generated');

        $this->assertSame(1, MealPlanEntry::query()->count());
    }

    public function test_a_day_that_does_not_add_up_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAs($this->user)
            ->post(route('meal-plan.generate'), [
                'days' => [['date' => '2027-05-03', 'slots' => [MealSlot::Lunch->value]]],
                'targets' => [
                    'people' => 2,
                    'kcal' => 2000,
                    'shares' => [MealSlot::Lunch->value => 60],
                ],
            ])
            ->assertSessionHasErrors('targets.shares');
    }

    /**
     * @param  list<string>  $dates
     * @param  list<MealSlot>  $slots
     * @return array{added: int, skipped: int, empty: list<string>, overspent: int}
     */
    private function fill(
        array $dates,
        array $slots,
        int $kcalPerPerson,
        ?Money $budget = null,
    ): array {
        $wanted = [];

        foreach ($dates as $date) {
            $wanted[$date] = $slots;
        }

        $this->forgetBooks();

        return $this->app->make(PlanGenerator::class)->fill(
            $this->user,
            $wanted,
            2,
            $this->targets($kcalPerPerson, $budget, $slots),
        );
    }

    /**
     * The books are singletons and these tests write their data after the
     * container could have read them. Only a test needs to say so.
     */
    private function forgetBooks(): void
    {
        $this->app->make(MeasureBook::class)->forget();
        $this->app->make(NutritionBook::class)->forget();
        $this->app->forgetInstance(PriceBook::class);
    }

    /**
     * @param  list<MealSlot>  $slots
     */
    private function targets(int $kcalPerPerson, ?Money $budget = null, array $slots = []): PlanTargets
    {
        $slots = $slots === [] ? [MealSlot::Lunch] : $slots;
        $share = 1 / count($slots);

        $shares = [];

        foreach ($slots as $slot) {
            $shares[$slot->value] = $share;
        }

        // Rounding a third three times leaves a hair short of a whole day, and
        // the constructor is right to be strict about it.
        $shares[$slots[0]->value] += 1 - array_sum($shares);

        return new PlanTargets(
            people: 2,
            kcalPerPerson: $kcalPerPerson,
            shares: $shares,
            budget: $budget,
        );
    }

    private function product(
        string $name,
        IngredientCategory $category = IngredientCategory::Other,
    ): Ingredient {
        return Ingredient::query()->create([
            'slug' => str($name)->slug()->value(),
            'name' => $name,
            'category' => $category,
            'source' => IngredientSource::Import,
        ]);
    }

    /** A product with a figure, so a recipe built on it can be counted. */
    private function staple(string $name, IngredientCategory $category): Ingredient
    {
        $product = $this->product($name, $category);

        IngredientNutrition::query()->create([
            'ingredient_id' => $product->id,
            'kcal_per_100g' => 500,
            'protein_g_per_100g' => 30,
        ]);

        return $product;
    }

    /** A distinct recipe row whose calories come mostly from one product. */
    private function builtFrom(string $title, Ingredient $staple, MealSlot $slot): void
    {
        $recipe = Recipe::query()->create([
            'slug' => str($title)->slug()->value(),
            'title' => $title,
            'servings' => 1,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/'.str($title)->slug()->value(),
        ]);

        $filler = $this->product($title.' dodatek');

        // Without a figure the filler counts as unread and drags the recipe
        // under `MINIMUM_COVERAGE` — the rule working, not a fixture detail.
        IngredientNutrition::query()->create([
            'ingredient_id' => $filler->id,
            'kcal_per_100g' => 0,
            'protein_g_per_100g' => 0,
        ]);

        foreach ([$staple, $filler] as $position => $product) {
            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'ingredient_id' => $product->id,
                'unit_id' => Unit::query()->where('code', 'g')->value('id'),
                'quantity' => $position === 0 ? 100 : 10,
                'raw_text' => $product->name,
                'position' => $position,
            ]);
        }

        DB::table('recipe_meal_slots')->insert([
            'recipe_id' => $recipe->id,
            'slot' => $slot->value,
        ]);
    }

    /**
     * A recipe worth a stated number of calories a portion.
     *
     * One line and one portion keeps the arithmetic legible: 100 g of a product
     * worth N kcal per 100 g is a dish worth N kcal.
     */
    /**
     * @param  list<string>  $categories
     */
    private function dish(
        string $title,
        MealSlot $slot,
        float $kcalPerPortion,
        float $protein,
        ?int $pricePerKilo = null,
        IngredientCategory $made = IngredientCategory::Other,
        array $categories = [],
    ): Recipe {
        $product = $this->product($title.' produkt', $made);

        IngredientNutrition::query()->create([
            'ingredient_id' => $product->id,
            'kcal_per_100g' => $kcalPerPortion,
            'protein_g_per_100g' => $protein,
        ]);

        if ($pricePerKilo !== null) {
            PriceObservation::query()->create([
                'source' => 'test',
                'external_id' => $product->slug,
                'title' => $product->name,
                'ingredient_id' => $product->id,
                'price_minor' => $pricePerKilo,
                'unit_price_minor' => $pricePerKilo,
                'unit_price_per' => 'mass',
                'observed_on' => now()->toDateString(),
            ]);
        }

        $recipe = Recipe::query()->create([
            'slug' => str($title)->slug()->value(),
            'title' => $title,
            'servings' => 1,
            'source_name' => 'example.test',
            'source_url' => 'https://example.test/'.str($title)->slug()->value(),
        ]);

        RecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'ingredient_id' => $product->id,
            'unit_id' => Unit::query()->where('code', 'g')->value('id'),
            'quantity' => 100,
            'raw_text' => '100 g '.$product->name,
            'position' => 0,
        ]);

        /*
         * A second ingredient worth nothing, so the arithmetic above stays
         * legible while the dish clears `MIN_REAL_INGREDIENTS`. A one-ingredient
         * recipe is a cooking instruction, and the generator is right to refuse
         * it — `test_a_one_ingredient_recipe_is_not_a_meal` is that rule.
         */
        $filler = $this->product($title.' dodatek');

        IngredientNutrition::query()->create([
            'ingredient_id' => $filler->id,
            'kcal_per_100g' => 0,
            'protein_g_per_100g' => 0,
        ]);

        /*
         * Priced too when the dish is, because a cost built from under two
         * thirds of a recipe is refused — see
         * `test_a_dish_priced_from_a_minority_of_itself_has_no_price`.
         */
        if ($pricePerKilo !== null) {
            PriceObservation::query()->create([
                'source' => 'test',
                'external_id' => $filler->slug,
                'title' => $filler->name,
                'ingredient_id' => $filler->id,
                'price_minor' => $pricePerKilo,
                'unit_price_minor' => $pricePerKilo,
                'unit_price_per' => 'mass',
                'observed_on' => now()->toDateString(),
            ]);
        }

        RecipeIngredient::query()->create([
            'recipe_id' => $recipe->id,
            'ingredient_id' => $filler->id,
            'unit_id' => Unit::query()->where('code', 'g')->value('id'),
            'quantity' => 10,
            'raw_text' => '10 g '.$filler->name,
            'position' => 1,
        ]);

        DB::table('recipe_meal_slots')->insert([
            'recipe_id' => $recipe->id,
            'slot' => $slot->value,
        ]);

        foreach ($categories as $slug) {
            $recipe->categories()->attach(Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'position' => 0],
            ));
        }

        return $recipe;
    }

    /** The potatoes and the surówka a plate can be built from. */
    private function sides(): void
    {
        // Planned as a snack so neither can be drawn as the obiad itself.
        $this->dish('Ziemniaki z koperkiem', MealSlot::Snack, kcalPerPortion: 300, protein: 6, made: IngredientCategory::Grain);
        $this->dish('Surówka z marchewki', MealSlot::Snack, kcalPerPortion: 100, protein: 2);
    }

    /**
     * @return list<array{title: string, servings: int}>
     */
    private function plateOn(string $date): array
    {
        return MealPlanEntry::query()
            ->where('user_id', $this->user->id)
            ->onDates([$date])
            ->where('slot', MealSlot::Lunch->value)
            ->with('recipe')
            ->orderBy('id')
            ->get()
            ->map(static fn (MealPlanEntry $entry): array => [
                'title' => (string) $entry->recipe?->title,
                'servings' => $entry->servings,
            ])
            ->all();
    }
}
