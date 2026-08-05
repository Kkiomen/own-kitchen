<?php

namespace App\Http\Middleware;

use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],

            /*
             * How many things are still to do, for the badge in the nav.
             *
             * Shared rather than a prop on one page, because the point of
             * writing "podjedź po chleb" down is not having to remember it — a
             * count you only see once you are already on the tasks screen is a
             * reminder you have to go looking for.
             *
             * A closure: Inertia drops props a partial visit did not ask for
             * before evaluating them, so scrolling the catalogue does not pay
             * for this count. One indexed COUNT otherwise.
             *
             * Flat, and not nested under `tasks`: the tasks screen has a page
             * prop by that name, and a page prop wins — the badge vanished on
             * the one screen where the number is most obviously right.
             */
            'openTasks' => fn (): int => $request->user() === null
                ? 0
                : Task::query()->of($request->user())->stillToDo()->count(),
            /*
             * What the last write did, so a screen can say "dopisano 6" rather
             * than appearing to do nothing.
             */
            'flash' => [
                'shopping' => fn (): mixed => $request->session()->get('shopping'),
                'plan' => fn (): mixed => $request->session()->get('plan'),
                'generated' => fn (): mixed => $request->session()->get('generated'),
                'swapped' => fn (): mixed => $request->session()->get('swapped'),
            ],
        ];
    }
}
