<?php

namespace App\Providers;

use App\Catalogue\IngredientEmoji;
use App\Support\Measurement\MeasureBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * One per request: it reads and sorts the needle file on construction,
         * and remembers what it has already matched. Both are wasted if every
         * controller that names a product gets its own copy.
         */
        $this->app->singleton(IngredientEmoji::class);

        /*
         * Also one per request, and for a sharper reason: a shopping plan touches
         * it from the planner, the pantry and the shopping list within one call.
         * Separate copies would read the whole measure table three times to answer
         * the same question.
         */
        $this->app->singleton(MeasureBook::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
