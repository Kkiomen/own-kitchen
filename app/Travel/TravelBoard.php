<?php

declare(strict_types=1);

namespace App\Travel;

/**
 * The travel screen's payload, built from the deals API.
 *
 * This is where the other app's JSON stops being the other app's JSON. Two
 * things happen and neither belongs in a controller:
 *
 * - **Prices become integer grosze.** They arrive as `61.8`, and a float that
 *   crosses to the browser is how a column of them renders as 11,999999999 —
 *   the lesson `App\Support\Money` was written for. It is not that class here
 *   because that one is PLN by construction and these figures carry their own
 *   currency code; only the integer discipline is borrowed, and the code travels
 *   beside the number so nothing has to assume złote.
 * - **A board that could not be fetched becomes `available: false`**, not a 500.
 *   An empty list and an unreachable app look identical in the data and mean
 *   opposite things, so the screen is given the difference explicitly.
 *
 * What it deliberately does *not* do is decide anything about the deals: no
 * re-sorting, no re-filtering, no dropping. Hundreds of thousands are stored and
 * at most two hundred are sent, so anything done to the page in hand would rank
 * the wrong ones and could show an empty screen while the far end is full of
 * matches. A different question is a different request.
 */
final class TravelBoard
{
    public function __construct(private readonly DealsApi $api) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DealFilters $filters): array
    {
        try {
            $board = $this->api->dashboard($filters);
        } catch (DealsUnavailable) {
            return $this->unavailable();
        }

        return [
            'available' => true,
            'deals' => $this->deals($board['deals'] ?? []),
            /*
             * Under their own heading, never mixed in. Most blog articles never
             * name their terms, so those offers cannot be shown to fit a holiday
             * — or to miss one. They only come back when a holiday was actually
             * asked for; without one every trip is in `deals`.
             */
            'undatedTrips' => $this->deals($board['undated_trips'] ?? []),
            'airports' => $this->airports($board['airports'] ?? []),
            'totals' => $this->totals($board['totals'] ?? []),
            'thresholds' => $this->thresholds($board['thresholds'] ?? []),
            'currency' => is_string($board['currency'] ?? null) ? $board['currency'] : 'PLN',
            /*
             * The filters that took effect, echoed by the API *after* unusable
             * values were dropped — so the controls render from what actually
             * happened rather than from what we thought we asked for. A
             * hand-edited link showing everything is the intended behaviour, not
             * a validation error to display.
             */
            'filters' => $this->filters($board),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        try {
            $meta = $this->api->meta();
        } catch (DealsUnavailable) {
            // Empty vocabularies rather than a failure: the screen already has
            // `available` to explain itself, and a filter bar with no options is
            // the honest rendering of "I could not ask what the options are".
            return [
                'sorts' => [],
                'types' => [],
                'boards' => [],
                'preferredOrigin' => null,
                'windowDays' => null,
            ];
        }

        return [
            'sorts' => $this->strings($meta['sorts'] ?? []),
            'types' => $this->strings($meta['types'] ?? []),
            'boards' => $this->strings($meta['boards'] ?? []),
            /** The home airport, listed first whatever the sort. */
            'preferredOrigin' => is_string($meta['preferred_origin'] ?? null)
                ? $meta['preferred_origin']
                : null,
            /*
             * Nothing is collected beyond this many days ahead, so a holiday
             * further out finds nothing. The screen says that in words — "no
             * results" for a question that was never going to have any is the
             * kind of empty state people re-ask three times.
             */
            'windowDays' => is_numeric($meta['window_days'] ?? null)
                ? (int) $meta['window_days']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'deals' => [],
            'undatedTrips' => [],
            'airports' => ['origins' => [], 'destinations' => []],
            'totals' => [],
            'thresholds' => [],
            'currency' => 'PLN',
            'filters' => $this->filters([]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deals(mixed $deals): array
    {
        if (! is_array($deals)) {
            return [];
        }

        $shaped = [];

        foreach ($deals as $deal) {
            if (is_array($deal)) {
                $shaped[] = $this->deal($deal);
            }
        }

        return $shaped;
    }

    /**
     * @param  array<string, mixed>  $deal
     * @return array<string, mixed>
     */
    private function deal(array $deal): array
    {
        return [
            /*
             * A fingerprint, not an identity: it changes when the price does, so
             * it is a key for rendering and never something to store and come
             * back to. The old one starts answering 404 the moment a cheaper
             * seat appears on the same route.
             */
            'id' => (string) ($deal['id'] ?? ''),
            'source' => (string) ($deal['source'] ?? ''),
            'type' => (string) ($deal['type'] ?? ''),
            'title' => (string) ($deal['title'] ?? ''),
            /** What the *whole* offer costs — both legs, or the package. */
            'price' => $this->grosze($deal['price'] ?? null) ?? 0,
            'currency' => is_string($deal['currency'] ?? null) ? $deal['currency'] : 'PLN',
            'url' => (string) ($deal['url'] ?? ''),
            'origin' => $this->airport($deal['origin'] ?? null),
            'destination' => $this->airport($deal['destination'] ?? null),
            'departsAt' => $this->text($deal['departs_at'] ?? null),
            'returnsAt' => $this->text($deal['returns_at'] ?? null),
            /*
             * Blog offers only, and not interchangeable with a departure: an
             * article published in the past means nothing, a flight that left in
             * the past is gone.
             */
            'publishedAt' => $this->text($deal['published_at'] ?? null),
            'weekend' => (bool) ($deal['weekend'] ?? false),
            'steal' => (bool) ($deal['steal'] ?? false),
            'typicalPrice' => $this->grosze($deal['typical_price'] ?? null),
            'discount' => is_numeric($deal['discount'] ?? null) ? (int) $deal['discount'] : null,
            'days' => is_numeric($deal['days'] ?? null) ? (int) $deal['days'] : null,
            'board' => $this->text($deal['board'] ?? null),
            'hotelStars' => is_numeric($deal['hotel_stars'] ?? null) ? (int) $deal['hotel_stars'] : null,
            'tripDestination' => $this->text($deal['trip_destination'] ?? null),
            'hotel' => $this->text($deal['hotel'] ?? null),
            'departureCities' => $this->strings($deal['departure_cities'] ?? []),
            /*
             * Several windows are alternatives, not one long stay: "4 lipca"
             * *or* "12-15 września". The screen draws each as its own range.
             */
            'dates' => $this->dates($deal['dates'] ?? []),
            'highlights' => $this->strings($deal['highlights'] ?? []),
            'hasDetails' => (bool) ($deal['has_details'] ?? false),
            'score' => is_numeric($deal['score'] ?? null) ? (int) $deal['score'] : null,
            'pricePerDay' => $this->grosze($deal['price_per_day'] ?? null),
        ];
    }

    /**
     * @return array{code: string, city: string, country: string}|null
     */
    private function airport(mixed $airport): ?array
    {
        if (! is_array($airport) || ! isset($airport['code'])) {
            return null;
        }

        return [
            'code' => (string) $airport['code'],
            // The API already falls back to the code when an airport is
            // unnamed, so this never has to invent a city.
            'city' => (string) ($airport['city'] ?? $airport['code']),
            'country' => (string) ($airport['country'] ?? ''),
        ];
    }

    /**
     * The two selects. **Each side ignores its own filter**: asking with
     * `origin=WRO` lists where Wrocław actually flies, while every departure
     * airport stays on offer so the user can change their mind.
     *
     * @return array{origins: list<array{code: string, label: string}>, destinations: list<array{code: string, label: string}>}
     */
    private function airports(mixed $airports): array
    {
        $airports = is_array($airports) ? $airports : [];

        return [
            'origins' => $this->options($airports['origins'] ?? []),
            'destinations' => $this->options($airports['destinations'] ?? []),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $shaped = [];

        foreach ($options as $option) {
            if (is_array($option) && isset($option['code'])) {
                $shaped[] = [
                    'code' => (string) $option['code'],
                    'label' => (string) ($option['label'] ?? $option['code']),
                ];
            }
        }

        return $shaped;
    }

    /**
     * Counted across everything kept, not over the page returned — which is what
     * lets the tiles stay true while a filter is applied.
     *
     * @return array<string, array{count: int, cheapest: int|null}>
     */
    private function totals(mixed $totals): array
    {
        if (! is_array($totals)) {
            return [];
        }

        $shaped = [];

        foreach ($totals as $type => $total) {
            if (is_string($type) && is_array($total)) {
                $shaped[$type] = [
                    'count' => is_numeric($total['count'] ?? null) ? (int) $total['count'] : 0,
                    'cheapest' => $this->grosze($total['cheapest'] ?? null),
                ];
            }
        }

        return $shaped;
    }

    /**
     * Price ceilings per kind — plus `score`, which is a 0-100 rating and
     * therefore **not** money. Converting that one to grosze would put "0,60 zł"
     * on the screen as a quality bar.
     *
     * @return array<string, int|null>
     */
    private function thresholds(mixed $thresholds): array
    {
        if (! is_array($thresholds)) {
            return [];
        }

        $shaped = [];

        foreach ($thresholds as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $shaped[$key] = $key === 'score'
                ? (is_numeric($value) ? (int) $value : null)
                : $this->grosze($value);
        }

        return $shaped;
    }

    /**
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function filters(array $board): array
    {
        return [
            'sort' => $this->text($board['sort'] ?? null),
            'type' => $this->text($board['type'] ?? null),
            'weekends' => (bool) ($board['weekends'] ?? false),
            'steals' => (bool) ($board['steals'] ?? false),
            'origin' => $this->text($board['origin'] ?? null),
            'destination' => $this->text($board['destination'] ?? null),
            'from' => $this->text($board['from'] ?? null),
            'to' => $this->text($board['to'] ?? null),
        ];
    }

    /**
     * @return list<array{from: string|null, to: string|null, label: string}>
     */
    private function dates(mixed $dates): array
    {
        if (! is_array($dates)) {
            return [];
        }

        $shaped = [];

        foreach ($dates as $date) {
            if (is_array($date)) {
                $shaped[] = [
                    'from' => $this->text($date['from'] ?? null),
                    'to' => $this->text($date['to'] ?? null),
                    // The article's own Polish wording, kept verbatim.
                    'label' => (string) ($date['label'] ?? ''),
                ];
            }
        }

        return $shaped;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Złote as they arrive, grosze as they leave. Null stays null: a price the
     * source never stated is not zero, and zero would quietly win every
     * comparison it entered.
     */
    private function grosze(mixed $zloty): ?int
    {
        return is_numeric($zloty) ? (int) round((float) $zloty * 100) : null;
    }
}
