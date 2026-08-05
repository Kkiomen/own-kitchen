<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The order a shop is actually walked in.
 *
 * A list sorted alphabetically sends you back across the shop for the yoghurt
 * you passed on the way in. There are deliberately few of these: nineteen
 * ingredient categories would split a small list into nineteen headings, which
 * is worse than no headings at all.
 */
enum ShoppingAisle: string
{
    case Produce = 'produce';
    case Meat = 'meat';
    case Dairy = 'dairy';
    case Dry = 'dry';
    case Seasoning = 'seasoning';
    case Other = 'other';

    public static function for(?IngredientCategory $category): self
    {
        return match ($category) {
            IngredientCategory::Vegetable,
            IngredientCategory::Fruit,
            IngredientCategory::Herb => self::Produce,
            IngredientCategory::Meat,
            IngredientCategory::Fish => self::Meat,
            IngredientCategory::Dairy,
            IngredientCategory::Egg => self::Dairy,
            IngredientCategory::Grain,
            IngredientCategory::Legume,
            IngredientCategory::NutSeed,
            IngredientCategory::Baking,
            IngredientCategory::Sweetener => self::Dry,
            IngredientCategory::Spice,
            IngredientCategory::Fat,
            IngredientCategory::Sauce => self::Seasoning,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Produce => 'Warzywa i owoce',
            self::Meat => 'Mięso i ryby',
            self::Dairy => 'Nabiał',
            self::Dry => 'Sypkie i pieczywo',
            self::Seasoning => 'Przyprawy, tłuszcze, sosy',
            self::Other => 'Pozostałe',
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Produce => 0,
            self::Meat => 1,
            self::Dairy => 2,
            self::Dry => 3,
            self::Seasoning => 4,
            self::Other => 5,
        };
    }
}
