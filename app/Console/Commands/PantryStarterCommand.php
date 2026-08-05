<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Fills a kitchen with a plausible everyday stock, so "pokaż tylko to, co
 * ugotuję" has something to compare against on the first visit.
 *
 * A command rather than a seeder, and deliberately not part of `DatabaseSeeder`:
 * this writes into somebody's real kitchen, and a `migrate:fresh --seed` that
 * silently claimed you own thirty products would be a lie the shopping list then
 * acts on. Every row it writes is editable and removable on the kitchen screen.
 *
 * **No amounts are written.** An entry with no quantity means "some, amount
 * unknown", which is exactly what is true of a kitchen nobody has measured. A
 * made-up "500 g" would read as a fact and be subtracted from a shopping list —
 * see `Quantity::sum()` for why this codebase refuses to invent numbers.
 */
class PantryStarterCommand extends Command
{
    protected $signature = 'pantry:starter {--email= : Which account to stock, when there is more than one}';

    protected $description = 'Stock a kitchen with the everyday products the catalogue leans on most';

    /**
     * Chosen from the products the catalogue actually uses most, minus the ones
     * already assumed to be at hand — seasoning is exempt from the shortfall
     * count anyway, so listing it would change nothing.
     *
     * @var list<string>
     */
    private const array PRODUCTS = [
        // The two that appear in more recipes than anything else.
        'Czosnek', 'Cebula',

        // Fridge staples for two people.
        'Jajko', 'Masło', 'Mleko', 'Ser żółty', 'Jogurt naturalny', 'Śmietana 18%',

        // Vegetables that keep.
        'Marchew', 'Ziemniak', 'Papryka', 'Pomidor', 'Ogórek', 'Cukinia', 'Pieczarki',

        // The dry shelf.
        'Mąka pszenna', 'Cukier', 'Ryż', 'Makaron', 'Płatki owsiane', 'Bułka tarta',
        'Kasza gryczana', 'Fasola',

        // Bought weekly rather than kept, but usually there.
        'Pierś z kurczaka', 'Chleb', 'Cytryna', 'Jabłko',

        // Jars and bottles that outlive the shopping they came with.
        'Koncentrat pomidorowy', 'Musztarda', 'Majonez', 'Sos sojowy',
        'Ocet balsamiczny', 'Miód',
    ];

    public function handle(): int
    {
        $email = $this->option('email');

        $user = User::query()
            ->when(is_string($email) && $email !== '', fn ($query) => $query->where('email', $email))
            ->orderBy('id')
            ->first();

        if ($user === null) {
            $this->error('Nie ma takiego konta. Załóż je w aplikacji albo podaj --email.');

            return self::FAILURE;
        }

        $stocked = 0;
        $unknown = [];

        foreach (self::PRODUCTS as $name) {
            $ingredient = Ingredient::query()->where('name', $name)->first();

            if ($ingredient === null) {
                // Named products only: inventing one here would put a product in
                // the catalogue that no recipe can ever match.
                $unknown[] = $name;

                continue;
            }

            PantryItem::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'ingredient_id' => $ingredient->id,
                    // The shelf this kind of thing usually lives on — the same
                    // rule the kitchen's own form suggests.
                    'location' => StorageLocation::suggestFor($ingredient->category)->value,
                ],
                [],
            );

            $stocked++;
        }

        $this->info("Wstawiono {$stocked} produktów do kuchni konta {$user->email}.");

        if ($unknown !== []) {
            $this->warn('Nie znaleziono w katalogu: '.implode(', ', $unknown));
        }

        return self::SUCCESS;
    }
}
