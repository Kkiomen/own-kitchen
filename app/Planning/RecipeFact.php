<?php

declare(strict_types=1);

namespace App\Planning;

use App\Support\Money\Money;

/**
 * One portion of one recipe, in the two currencies a targeted week is planned in.
 *
 * A named type rather than a pair in an array because the two nulls mean
 * genuinely different things and the difference is easy to lose: no calories
 * disqualifies a dish from a calorie-targeted plan, no price only means nobody
 * has priced it. Reading the second as the first would narrow ten thousand
 * recipes down to whatever was in a leaflet.
 */
final readonly class RecipeFact
{
    public function __construct(
        public ?float $kcalPerPortion,
        public ?Money $costPerPortion,
        public ?float $proteinPerPortion = null,
        /**
         * Ingredients that are neither seasoning nor water — what the dish is
         * actually built from.
         */
        public int $realIngredients = 0,
        /** The product it draws most of its calories from. */
        public ?int $dominantIngredientId = null,
        /** As the source declared it; null when it never said. */
        public ?int $minutes = null,
        /**
         * Its quick-pick category slugs — what tells a chicken dinner from a
         * fish one when the week is being varied.
         *
         * @var list<string>
         */
        public array $categories = [],
        /** The date the dish belongs to ("christmas", "easter"…), if any. */
        public ?string $occasion = null,
        /**
         * The seasonal products in it, by canonical name.
         *
         * @var list<string>
         */
        public array $seasonalProduce = [],
        /**
         * Whether the title is a tabloid headline ("Wlewam jogurt do naczynia
         * i wbijam jajko. To śniadanie robi się samo") rather than the name of
         * a dish.
         */
        public bool $isHeadline = false,
        /** The kind of dish — pancakes, a sandwich, a soup — see `DishFamily`. */
        public ?string $family = null,
        /** How many portions the recipe makes, as the source stated it. */
        public ?int $servings = null,
        /** Written for the air fryer — one source writes nothing else. */
        public bool $isAirFryer = false,
        /** One of the dishes a Polish home is built on — see `home-classics.php`. */
        public bool $isHomeClassic = false,
        /** Built on tofu, tempeh or seitan. */
        public bool $isPlantProtein = false,
        /** Liver, gizzards, tongue — see `DishFamily::isOffal()`. */
        public bool $isOffal = false,
        /** "azjatycka", "indyjska", "meksykanska", or null for no stated cuisine. */
        public ?string $cuisine = null,
        /**
         * The stems that name the dish — see `DishFamily::signatureOf()`.
         *
         * @var list<string>
         */
        public array $signature = [],
        /**
         * The share of its calories that comes from potatoes, groats, rice,
         * pasta or bread; null when the calories could not be counted.
         */
        public ?float $starchShare = null,
        /** The title names its own potatoes, groats or rice — see `DishFamily::namesStarch()`. */
        public bool $namesStarch = false,
        /** Served in a sauce — "w sosie", "z sosem" — which wants groats or kluski beside it. */
        public bool $inSauce = false,
        /** "zurek", "rosol", "krem"… — see `DishFamily::soupKindOf()`. Null for anything not a soup. */
        public ?string $soupKind = null,
        /** The title names meat the categories may have missed — see `DishFamily::namesMeat()`. */
        public bool $hasMeatInTitle = false,
        /**
         * How many vegetables or fruit it is built from, not counting onion,
         * garlic and potatoes — see `RecipeFacts::vegetablesIn()`.
         */
        public int $vegetables = 0,
        /** Built on processed meat or a rich cheese — see `RecipeFacts::isRich()`. */
        public bool $isRich = false,
    ) {}

    /**
     * Whether this is the meat-and-vegetables half of a plate that still wants
     * its potatoes. A fifth is the line: a cutlet with a soaked roll in it
     * stays under, a dish served on rice is well over.
     *
     * Unknown is not "needs one" — adding food to a meal on a guess is the
     * same invention as leaving it off.
     */
    public function lacksStarch(): bool
    {
        return ! $this->namesStarch && $this->starchShare !== null && $this->starchShare < 0.2;
    }

    /**
     * Plain values, for a cache that unserializes no classes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...get_object_vars($this), 'costPerPortion' => $this->costPerPortion?->grosze];
    }

    /**
     * @param  array<string, mixed>  $data  what `toArray()` produced
     */
    public static function fromArray(array $data): self
    {
        $cost = $data['costPerPortion'] ?? null;

        return new self(...[...$data, 'costPerPortion' => is_int($cost) ? new Money($cost) : null]);
    }

    /**
     * Whether it is still good the next day, which is what "cook once, eat
     * twice" bets on. Fish and anything cold or assembled lose it: a salmon
     * pasta reheated and two days of sushi were both generated, and both
     * reviewers picked the sushi as the worst dish of three weeks.
     */
    public function reheatsWell(): bool
    {
        return ! in_array('ryby', $this->categories, true)
            && ! in_array($this->family, ['kanapki', 'tortille', 'salatka', 'nalesniki', 'owsianka'], true);
    }

    /**
     * What the dish is built around, for spreading a week across chicken,
     * fish, beef, pork and meat-free days.
     *
     * Every animal category counts — chicken skewers wrapped in bacon are a
     * chicken day *and* a pork day, and reading only one of them let a week
     * serve chicken for lunch and chicken for supper. Empty for a dish the
     * catalogue cannot place, which is then left out of the rotation rather
     * than guessed at.
     *
     * @return list<string>
     */
    public function themes(): array
    {
        $themes = array_values(array_intersect(self::THEMES, $this->categories));

        // Tofu, tempeh and seitan rotate like a meat: three tofu days running
        // was "wege" to the rotation, and wege is barely limited.
        if ($this->isPlantProtein) {
            $themes[] = 'roslinne';
        }

        if ($themes === [] && in_array('wege', $this->categories, true)) {
            return ['wege'];
        }

        return $themes;
    }

    /** @var list<string> */
    public const array THEMES = ['ryby', 'wolowina', 'wieprzowina', 'kurczak'];

    /**
     * Soup, pasta and salad are forms rather than proteins, and rotate
     * separately — a generated week once had four salads for supper, each a
     * different protein and every one of them a salad.
     */
    public function form(): ?string
    {
        foreach (['zupy', 'makarony', 'salatki'] as $form) {
            if (in_array($form, $this->categories, true)) {
                return $form;
            }
        }

        return null;
    }

    /**
     * The share of this dish's calories that comes from protein.
     *
     * The one number that separates a meal from a plate of calories. A week
     * planned on calories and price alone converges on flour and potatoes —
     * they are the cheapest calories in any catalogue — and the result hits
     * 2 500 kcal exactly while being pancakes, gnocchi and macaroni cheese.
     * Measured on the live catalogue before this existed, that is precisely
     * what came out.
     *
     * Null when the reading did not state protein, and null must not be read as
     * zero: a dish nobody has broken down is not a dish without protein.
     */
    public function proteinShare(): ?float
    {
        if ($this->proteinPerPortion === null || $this->kcalPerPortion === null || $this->kcalPerPortion <= 0) {
            return null;
        }

        // Four kilocalories to the gram, the same Atwater figure the dictionary
        // test checks the file against.
        return $this->proteinPerPortion * 4 / $this->kcalPerPortion;
    }

    /**
     * How many portions of this it takes to reach a number of calories.
     *
     * Null when the dish has no calorie figure, and null when the figure is zero
     * or negative — a dish nothing can be divided by. Water and tea are real
     * catalogue entries with a genuine zero, and no number of glasses of tea adds
     * up to a lunch.
     */
    public function portionsFor(float $kcal): ?float
    {
        return $this->kcalPerPortion === null || $this->kcalPerPortion <= 0
            ? null
            : $kcal / $this->kcalPerPortion;
    }
}
