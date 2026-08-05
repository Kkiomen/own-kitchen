<?php

declare(strict_types=1);

namespace App\Travel;

use Illuminate\Http\Request;

/**
 * What the traveller asked to see, on its way to the deals API.
 *
 * **Nothing here is validated, and that is deliberate.** The API ignores an
 * unusable value rather than rejecting it — `?sort=xx&from=2026-13-99` answers
 * 200 with the defaults — and it echoes back the filters that actually took
 * effect. So this class only has to carry a query string faithfully; what the
 * screen then renders its controls from is the *response*, never this object. A
 * second opinion here could only disagree with the far end, and a filter bar
 * showing a value the results do not honour is worse than one that lags.
 *
 * The one thing it does refuse to do is invent keys: only the eight the API
 * documents are forwarded, so a stray query parameter of ours cannot become one
 * of theirs by accident.
 */
final readonly class DealFilters
{
    public function __construct(
        public ?string $sort = null,
        public ?string $type = null,
        public bool $weekends = false,
        public bool $steals = false,
        public ?string $origin = null,
        public ?string $destination = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $limit = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            sort: self::text($request, 'sort'),
            type: self::text($request, 'type'),
            weekends: $request->boolean('weekends'),
            steals: $request->boolean('steals'),
            // Upper-cased because the API matches IATA codes literally and a
            // link typed by hand says "wro" as often as "WRO".
            origin: self::code($request, 'origin'),
            destination: self::code($request, 'destination'),
            from: self::text($request, 'from'),
            to: self::text($request, 'to'),
            limit: $request->has('limit') ? $request->integer('limit') : null,
        );
    }

    /**
     * The query string to send. Absent keys mean "do not filter by this", so a
     * null is left out rather than sent empty — `origin=` is a value the far end
     * would have to interpret, and it does not have to.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'sort' => $this->sort,
            'type' => $this->type,
            // The API takes 1/0; false is simply not sent, which is what "no
            // filter" means. Sending `weekends=0` asks a question nobody asked.
            'weekends' => $this->weekends ? 1 : null,
            'steals' => $this->steals ? 1 : null,
            'origin' => $this->origin,
            'destination' => $this->destination,
            /*
             * Both or neither: a holiday must contain the whole journey, and the
             * API matches a round trip on its departure *and* its return. One
             * half of a range would filter on a bound the user never set.
             */
            'from' => $this->to === null ? null : $this->from,
            'to' => $this->from === null ? null : $this->to,
            'limit' => $this->limit,
        ], fn (string|int|null $value): bool => $value !== null && $value !== '');
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function code(Request $request, string $key): ?string
    {
        $value = self::text($request, $key);

        return $value === null ? null : mb_strtoupper($value);
    }
}
