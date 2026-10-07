<?php

declare(strict_types=1);

namespace App\Planning;

use App\Catalogue\TitleNeedle;
use App\Importing\Parsing\PolishTextNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The recipes that can stand beside an obiad's main course — potatoes or
 * groats, and a surówka. `database/data/side-dishes.php` nominates them by
 * title; this class admits only the ones whose ingredients agree.
 *
 * Kept apart from the generator because "what is a side" is a question about
 * the catalogue, asked once, and "which side today" is a question about the
 * week, asked at every obiad.
 */
final class SideDishes
{
    public const string STARCH = 'starch';

    public const string SALAD = 'salad';

    /**
     * A soup served before the main — the first half of a Polish obiad. Both
     * reviewers asked for soup two or three times a week in every round, and
     * a soup filling enough to be the whole obiad is rare: three bowls of rosół
     * each was the only way the planner could make one into a meal.
     */
    public const string SOUP = 'soup';

    /*
     * What kind of starch a side is, because a main is eaten with one kind and
     * not another: gulasz with kasza or kluski, chili with rice, a roast with
     * roast potatoes. "Pulpeciki chili con carne" beside pieczone ziemniaki and
     * gulasz beside chips were both generated, and a reviewer read each as a
     * plate assembled by somebody who had never eaten either.
     */
    public const string RICE = 'rice';

    public const string GROATS = 'groats';

    public const string BOILED = 'boiled';

    /** Kluski, kopytka, pyzy — "11 obiadów z kluskami w trzy tygodnie". */
    public const string DUMPLINGS = 'dumplings';

    public const string ROASTED = 'roasted';

    /** @var array<string, list<string>> */
    private const array STYLES = [
        self::RICE => ['ryz', 'ryzu', 'pilaw'],
        self::GROATS => ['kasz*', 'bulgur*', 'kuskus*'],
        self::DUMPLINGS => ['klusk*', 'kopytk*', 'pyzy'],
        self::BOILED => [
            'puree', 'gotowane', 'tluczone', 'mlode ziemniaki', 'mlode ziemniaczki', 'z koperkiem', 'chateau',
        ],
    ];

    /** Dish families that are a meal in themselves — see `dish-families.php`. */
    private const array WHOLE_MEALS = [
        'zupa', 'salatka', 'kotlety', 'nalesniki', 'kanapki', 'tortille', 'owsianka', 'jajka', 'zapiekanka', 'curry',
    ];

    /** @var array<string, list<string>> */
    private array $needles;

    /** @var array<string, array<int, RecipeFact>>|null */
    private ?array $admitted = null;

    /**
     * Each starch side's kind and each side's stated cuisine, by id.
     *
     * @var array<int, string>
     */
    private array $styles = [];

    /** @var array<int, string> */
    private array $cuisines = [];

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly RecipeFacts $facts,
        private readonly DishFamily $families,
    ) {
        /** @var array<string, list<string>> $needles */
        $needles = require database_path('data/side-dishes.php');

        $this->needles = $needles;
    }

    /**
     * Every admitted side of one kind, with what is known about it.
     *
     * @return array<int, RecipeFact>
     */
    public function of(string $kind): array
    {
        $this->admitted ??= $this->admit();

        return $this->admitted[$kind] ?? [];
    }

    /** Which kind of side this recipe is admitted as — starch, salad or soup — or null. */
    public function kindOf(int $id): ?string
    {
        foreach ([self::STARCH, self::SALAD, self::SOUP] as $kind) {
            if (isset($this->of($kind)[$id])) {
                return $kind;
            }
        }

        return null;
    }

    /** Rice, groats, boiled or roasted — see the constants above. */
    public function styleOf(int $id): string
    {
        $this->admitted ??= $this->admit();

        return $this->styles[$id] ?? self::ROASTED;
    }

    /** The cuisine a side's title names, if any — "Azjatycka surówka…". */
    public function cuisineOf(int $id): ?string
    {
        $this->admitted ??= $this->admit();

        return $this->cuisines[$id] ?? null;
    }

    /**
     * Read through a cache, because admitting costs thirteen seconds — facts for
     * some fifteen hundred nominated recipes — and the answer changes only when
     * the catalogue does. Filling a week went from two seconds to sixteen when
     * this ran on every request.
     *
     * The key is a fingerprint of everything the answer depends on: the recipes
     * and their lines, the nutrition, weights and prices behind their facts,
     * and the data files that nominate and describe them. A re-import or a
     * dictionary edit therefore takes effect on the next request, exactly as
     * if nothing were cached.
     *
     * @return array<string, array<int, RecipeFact>>
     */
    private function admit(): array
    {
        /** @var array{admitted: array<string, array<int, array<string, mixed>>>, styles: array<int, string>, cuisines: array<int, string>} $cached */
        $cached = Cache::remember('planning:sides:'.$this->fingerprint(), now()->addDay(), function (): array {
            $admitted = $this->admitFresh();

            return [
                'admitted' => array_map(
                    static fn (array $facts): array => array_map(static fn (RecipeFact $fact): array => $fact->toArray(), $facts),
                    $admitted,
                ),
                'styles' => $this->styles,
                'cuisines' => $this->cuisines,
            ];
        });

        $this->styles = $cached['styles'];
        $this->cuisines = $cached['cuisines'];

        return array_map(
            static fn (array $facts): array => array_map(RecipeFact::fromArray(...), $facts),
            $cached['admitted'],
        );
    }

    private function fingerprint(): string
    {
        $files = array_map(
            static fn (string $file): int|false => filemtime(database_path("data/{$file}.php")),
            ['side-dishes', 'dish-families', 'home-classics', 'seasons', 'categories', 'ingredients'],
        );

        return md5((string) json_encode([
            DB::table('recipes')->count(),
            DB::table('recipes')->max('updated_at'),
            DB::table('recipe_ingredients')->max('updated_at'),
            DB::table('category_recipe')->count(),
            DB::table('ingredient_nutrition')->max('updated_at'),
            DB::table('ingredient_measures')->max('updated_at'),
            DB::table('price_observations')->max('id'),
            $files,
        ]));
    }

    /**
     * @return array<string, array<int, RecipeFact>>
     */
    private function admitFresh(): array
    {
        $nominated = [self::STARCH => [], self::SALAD => [], self::SOUP => []];

        foreach (DB::table('recipes')->select(['id', 'title'])->cursor() as $row) {
            $id = (int) $row->id;
            $title = $this->normalizer->normalize((string) $row->title);

            if (TitleNeedle::matchesAny($title, $this->needles['exclude'])) {
                continue;
            }

            foreach ([self::STARCH, self::SALAD] as $kind) {
                if (TitleNeedle::matchesAny($title, $this->needles[$kind])) {
                    $nominated[$kind][] = (int) $row->id;

                    break;
                }
            }

            if ($this->families->of((string) $row->title) === 'zupa') {
                $nominated[self::SOUP][] = $id;
            }

            foreach (self::STYLES as $style => $needles) {
                if (TitleNeedle::matchesAny($title, $needles)) {
                    $this->styles[$id] = $style;

                    break;
                }
            }

            $cuisine = $this->families->cuisineOf((string) $row->title);

            if ($cuisine !== null) {
                $this->cuisines[$id] = $cuisine;
            }
        }

        $facts = $this->facts->forRecipes(array_values(array_unique([
            ...$nominated[self::STARCH],
            ...$nominated[self::SALAD],
            ...$nominated[self::SOUP],
        ])));

        $admitted = [];

        foreach ($nominated as $kind => $ids) {
            $admitted[$kind] = [];

            foreach ($ids as $id) {
                $fact = $facts[$id] ?? null;

                if ($fact !== null && $this->isSide($fact, $kind)) {
                    $admitted[$kind][$id] = $fact;
                }
            }
        }

        return $admitted;
    }

    /**
     * What the title cannot say. "Ziemniaki zapiekane z boczkiem" and
     * "Surówka z tuńczyka" are both main courses wearing a side's name: meat
     * or fish disqualifies, and so does a plate of food a side could never be.
     *
     * Only dishes with a calorie figure: a side exists here to stand in for
     * the extra helpings of the main, and one that cannot be counted would
     * leave the meal's calories a guess.
     */
    private function isSide(RecipeFact $fact, string $kind): bool
    {
        $kcal = $fact->kcalPerPortion;

        // An occasion is not a reason to refuse a side: `pickSide()` applies the
        // season gate, so młode ziemniaki come in June rather than never.
        if ($kcal === null) {
            return false;
        }

        // A starter soup may be rosół: the meat rule is for what sits beside a main.
        if ($kind === self::SOUP) {
            return $kcal >= 80 && $kcal <= 400 && $fact->realIngredients >= 2;
        }

        if (array_diff($fact->themes(), ['wege']) !== []) {
            return false;
        }

        // A dish that is a meal of its own kind: "Krupnik z kaszą", "Placki
        // ziemniaczane", "Sałatka z kaszą bulgur" all name a starch and none of
        // them is what goes beside a cutlet.
        if (in_array($fact->family, self::WHOLE_MEALS, true)) {
            return false;
        }

        return match ($kind) {
            self::STARCH => ($fact->starchShare ?? 0.0) >= 0.4 && $kcal >= 120 && $kcal <= 650
                && $fact->realIngredients <= 5,
            self::SALAD => $kcal <= 300 && $fact->realIngredients <= 8 && ($fact->starchShare ?? 0.0) < 0.3,
            default => false,
        };
    }
}
