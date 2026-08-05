<?php

use App\Offers\OfferRefresh;
use App\Pricing\PriceRefresh;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Read the leaflets whenever the ones we hold have gone stale, rather than at a
 * fixed hour on a Monday.
 *
 * Polish leaflets do turn over on a Monday, and a weekly slot is the obvious
 * schedule — on a server. This runs on a desktop that is asleep at four in the
 * morning, so the slot was simply missed and the plan quietly went on quoting
 * prices from the fortnight before. Checking hourly and importing only when
 * `OfferRefresh` says the prices are a week old costs one cheap query an hour,
 * catches up by itself whenever the machine is next awake, and cannot run twice
 * for the same week.
 *
 * Thirteen chains at the site's own ten second delay takes hours, hence
 * `runInBackground` — nothing else on the schedule should queue behind it — and
 * `withoutOverlapping`, because two writers on SQLite is a bad idea even with
 * WAL, and this run is long enough to still be going at the next check.
 */
Schedule::command('promotions:import gazetki')
    ->hourly()
    ->when(fn (): bool => app(OfferRefresh::class)->isStale())
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Refresh what things normally cost, on the same staleness rule and for the same
 * reason — a desktop that may be asleep at any given hour.
 *
 * Cheap, unlike the leaflet crawl: two calls to an open API plus a pass over
 * promotions we already hold. It still needs `withoutOverlapping`, because the
 * leaflet import above can be running for hours and two SQLite writers is a bad
 * idea even with WAL.
 *
 * Deliberately not chained to the leaflet import. The two sources are
 * independent readings of the same question, and half an answer is worth having:
 * if the crawl is failing or has not run, the statistical figures still price
 * flour, milk, butter and eggs.
 */
Schedule::command('prices:import')
    ->hourly()
    ->when(fn (): bool => app(PriceRefresh::class)->isStale())
    ->withoutOverlapping()
    ->runInBackground();
