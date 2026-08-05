<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalogue\TagMealSlots;
use App\Enums\MealSlot;
use Illuminate\Console\Command;

class TagMealSlotsCommand extends Command
{
    protected $signature = 'recipes:meal-slots';

    protected $description = 'Recompute which meals of the day each recipe suits, from database/data/meal-slots.php';

    public function handle(TagMealSlots $tag): int
    {
        $this->info('Przeliczam pory posiłków…');

        $counts = $tag->run(function (int $done, int $total): void {
            $this->output->write("\r  {$done} / {$total}");
        });

        $this->newLine(2);
        $this->table(
            ['Posiłek', 'Przepisów'],
            array_map(
                static fn (string $slot, int $count): array => [MealSlot::from($slot)->label(), $count],
                array_keys($counts),
                array_values($counts),
            ),
        );

        return self::SUCCESS;
    }
}
