<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where something is kept. Not cosmetic: it decides which screen you reach for
 * when standing in front of an open door, and the fridge is the only one whose
 * contents go off.
 */
enum StorageLocation: string
{
    case Fridge = 'fridge';
    case Freezer = 'freezer';
    case Pantry = 'pantry';

    public function label(): string
    {
        return match ($this) {
            self::Fridge => 'Lodówka',
            self::Freezer => 'Zamrażarka',
            self::Pantry => 'Spiżarnia',
        };
    }

    /**
     * Dry goods keep for months, so nagging about their dates would be noise.
     */
    public function tracksExpiry(): bool
    {
        return $this !== self::Pantry;
    }

    /**
     * Where this kind of product usually ends up when the shopping is unpacked.
     *
     * A guess, and always changeable on the screen — but a right guess most of
     * the time turns three taps into none, which is what makes putting the whole
     * bag away bearable.
     */
    public static function suggestFor(?IngredientCategory $category): self
    {
        return match ($category) {
            IngredientCategory::Vegetable,
            IngredientCategory::Fruit,
            IngredientCategory::Meat,
            IngredientCategory::Fish,
            IngredientCategory::Dairy,
            IngredientCategory::Egg,
            IngredientCategory::Herb => self::Fridge,
            default => self::Pantry,
        };
    }

    /**
     * The order the screen shows them in — what spoils first comes first.
     */
    public function position(): int
    {
        return match ($this) {
            self::Fridge => 0,
            self::Freezer => 1,
            self::Pantry => 2,
        };
    }
}
