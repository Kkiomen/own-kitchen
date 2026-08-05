<?php

declare(strict_types=1);

use App\Enums\UnitDimension;

/**
 * The closed vocabulary of measures. `factor_to_base` is expressed in the
 * dimension's base unit: grams, millilitres, or items.
 *
 * Spoons and glasses are volumes by convention (Polish recipes mean a level
 * tablespoon at 15 ml). Anything a cook eyeballs is marked approximate so the
 * budget code can refuse to price it.
 */
return [
    ['code' => 'g', 'name' => 'gram', 'symbol' => 'g', 'dimension' => UnitDimension::Mass, 'factor' => 1],
    ['code' => 'dag', 'name' => 'dekagram', 'symbol' => 'dag', 'dimension' => UnitDimension::Mass, 'factor' => 10],
    ['code' => 'kg', 'name' => 'kilogram', 'symbol' => 'kg', 'dimension' => UnitDimension::Mass, 'factor' => 1000],

    ['code' => 'ml', 'name' => 'mililitr', 'symbol' => 'ml', 'dimension' => UnitDimension::Volume, 'factor' => 1],
    ['code' => 'l', 'name' => 'litr', 'symbol' => 'l', 'dimension' => UnitDimension::Volume, 'factor' => 1000],
    ['code' => 'tsp', 'name' => 'łyżeczka', 'symbol' => 'łyżeczka', 'dimension' => UnitDimension::Volume, 'factor' => 5],
    ['code' => 'tbsp', 'name' => 'łyżka', 'symbol' => 'łyżka', 'dimension' => UnitDimension::Volume, 'factor' => 15],
    ['code' => 'cup', 'name' => 'szklanka', 'symbol' => 'szkl.', 'dimension' => UnitDimension::Volume, 'factor' => 250],
    ['code' => 'pinch', 'name' => 'szczypta', 'symbol' => 'szczypta', 'dimension' => UnitDimension::Volume, 'factor' => 0.5, 'approximate' => true],
    ['code' => 'drop', 'name' => 'kropla', 'symbol' => 'kropla', 'dimension' => UnitDimension::Volume, 'factor' => 0.05, 'approximate' => true],
    ['code' => 'handful', 'name' => 'garść', 'symbol' => 'garść', 'dimension' => UnitDimension::Volume, 'factor' => 60, 'approximate' => true],

    ['code' => 'piece', 'name' => 'sztuka', 'symbol' => 'szt.', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'clove', 'name' => 'ząbek', 'symbol' => 'ząbek', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'package', 'name' => 'opakowanie', 'symbol' => 'opak.', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'can', 'name' => 'puszka', 'symbol' => 'puszka', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'jar', 'name' => 'słoik', 'symbol' => 'słoik', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'bunch', 'name' => 'pęczek', 'symbol' => 'pęczek', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'slice', 'name' => 'plaster', 'symbol' => 'plaster', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'leaf', 'name' => 'listek', 'symbol' => 'listek', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'sprig', 'name' => 'gałązka', 'symbol' => 'gałązka', 'dimension' => UnitDimension::Count, 'factor' => 1],
    ['code' => 'cube', 'name' => 'kostka', 'symbol' => 'kostka', 'dimension' => UnitDimension::Count, 'factor' => 1],
];
