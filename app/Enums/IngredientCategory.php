<?php

declare(strict_types=1);

namespace App\Enums;

enum IngredientCategory: string
{
    case Vegetable = 'vegetable';
    case Fruit = 'fruit';
    case Meat = 'meat';
    case Fish = 'fish';
    case Dairy = 'dairy';
    case Egg = 'egg';
    case Grain = 'grain';
    case Legume = 'legume';
    case NutSeed = 'nut_seed';
    case Herb = 'herb';
    case Spice = 'spice';
    case Fat = 'fat';
    case Sweetener = 'sweetener';
    case Baking = 'baking';
    case Sauce = 'sauce';
    case Beverage = 'beverage';
    /**
     * Baking paper, cling film, skewers. Recipe ingredient lists mix these in with
     * food, but they must never count towards "can I cook this from the fridge?"
     * or a food budget.
     */
    case Equipment = 'equipment';
    case Other = 'other';

    public function isFood(): bool
    {
        return $this !== self::Equipment;
    }

    /**
     * The picture shown beside a product when nothing more specific is known.
     *
     * Emoji rather than a drawn icon, and only here: interface and appliance
     * icons stay drawn, because they are controls and must take the ink colour.
     * A product is neither — it is identified at a glance in a list of forty,
     * which is exactly what colour does well and a monochrome stroke does not.
     * Nobody is going to draw nine hundred vegetables either.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::Vegetable => '🥕',
            self::Fruit => '🍎',
            self::Meat => '🥩',
            self::Fish => '🐟',
            self::Dairy => '🧀',
            self::Egg => '🥚',
            self::Grain => '🌾',
            self::Legume => '🫘',
            self::NutSeed => '🥜',
            self::Herb => '🌿',
            self::Spice => '🧂',
            self::Fat => '🧈',
            self::Sweetener => '🍯',
            self::Baking => '🧁',
            self::Sauce => '🥫',
            self::Beverage => '🥤',
            self::Equipment => '🧰',
            self::Other => '🍽️',
        };
    }

    /**
     * Categories whose ingredients are usually always in the cupboard, so a missing
     * one should not stop a recipe from being suggested.
     */
    public function isTypicallyStaple(): bool
    {
        return in_array($this, [self::Spice, self::Fat], true);
    }

    /**
     * Things added for flavour rather than for substance.
     *
     * Deliberately wider than `isTypicallyStaple()`, and a different idea: fresh
     * parsley is not a cupboard staple, but forgetting it does not stop dinner.
     * "Can I cook this?" must not answer no because a garnish was never written
     * down — a dish is not blocked by the herb sprinkled on top of it.
     *
     * Herbs are in here on the strength of the numbers as much as the argument:
     * exempting spices and fats alone moved 350 cookable recipes to 363, because
     * curated spices already carry `is_staple`. Adding herbs moves it to 587,
     * and "Natka pietruszki" alone appears on 1 480 ingredient lines.
     *
     * Sauces and sweeteners are **not** in here and should not be added: soy
     * sauce, mustard and honey are things you either have or have to buy, and
     * calling them free would make the filter lie in the expensive direction.
     */
    public function isSeasoning(): bool
    {
        return in_array($this, [self::Spice, self::Fat, self::Herb], true);
    }

    /**
     * Whether a line of this can be left out of a calorie count without moving
     * the number.
     *
     * A deliberately **narrower** list than `isSeasoning()`, and the difference
     * is the whole reason this exists separately: fat is assumed to be at hand
     * when shopping, and is the single most calorie-dense thing in a kitchen.
     * Oil at 884 kcal per 100 g heads the list of products a recipe names
     * without an amount — 1 017 lines of it — so excusing it here would let a
     * recipe report a confident figure with its frying left out.
     *
     * Spices and herbs are excused because no plausible amount of them matters:
     * a heaped spoon of paprika is fifteen calories, and a recipe is not
     * mis-planned by that. They are also, by far, the commonest thing a recipe
     * declines to measure ("sól do smaku"), so counting them as unread would
     * make almost every dish in the catalogue look uncountable for the most
     * ordinary reason there is.
     *
     * Two questions that look alike and are not: `isSeasoning()` asks "must I
     * buy this?", this asks "can this move a calorie count?". Merging them would
     * be wrong in opposite directions on fat.
     */
    public function isCaloricallyNegligible(): bool
    {
        return in_array($this, [self::Spice, self::Herb], true);
    }

    /**
     * The same rule as a list of stored values, for the coverage query that has
     * to apply it in SQL.
     *
     * @return list<string>
     */
    public static function caloricallyNegligible(): array
    {
        return array_values(array_map(
            static fn (self $category): string => $category->value,
            array_filter(self::cases(), static fn (self $category): bool => $category->isCaloricallyNegligible()),
        ));
    }

    /**
     * The same rule as a list of stored values, for the one query that has to
     * apply it in SQL.
     *
     * It reads the category rather than the `is_staple` column because that
     * column is written by the dictionary seeder: it is true for the 344 curated
     * products and false for every one of the ~1300 the importer invented. So
     * "przyprawa gyros" and "przyprawa do mięsa Knorr" counted as missing
     * products and hid recipes that were perfectly cookable.
     *
     * @return list<string>
     */
    public static function assumedAtHand(): array
    {
        return array_values(array_map(
            static fn (self $category): string => $category->value,
            array_filter(self::cases(), static fn (self $category): bool => $category->isSeasoning()),
        ));
    }
}
