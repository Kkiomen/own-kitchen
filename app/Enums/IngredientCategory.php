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
