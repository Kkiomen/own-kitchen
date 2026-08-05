<?php

declare(strict_types=1);

namespace App\Pricing\Sources\Gus;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;

/**
 * The Statistics Poland Local Data Bank, over its public JSON API.
 *
 * Two calls, not a crawl. The variables of one subject come back in a single
 * page, and their values come back in batches of `BATCH` — so a whole refresh of
 * every food price the office publishes is a handful of requests, which is why
 * this needs no throttle and no page cache the size of the recipe crawler's.
 *
 * The anonymous tier is rate limited per quarter hour, and a batched read stays
 * far inside it. `X-ClientId` raises the limit and is sent when one is
 * configured; the API answers without it, so it is not required to run this.
 */
final class GusBdlClient
{
    /**
     * How many variables to ask for in one call.
     *
     * The endpoint takes repeated `var-id` parameters, and the limit that bites
     * first is the URL's length rather than anything the API states. Sixty
     * six-digit ids is a query string of about half a kilobyte — comfortably
     * short, and it turns ninety products into two requests.
     */
    private const int BATCH = 60;

    /** The largest page the API will serve. Its default is ten. */
    private const int PAGE_SIZE = 100;

    /** POLSKA. The country as a whole, which is what a national average is. */
    private const string COUNTRY = '000000000000';

    public function __construct(
        private readonly Http $http,
        /**
         * Injected rather than reached for through the facade, so a test cannot
         * be answered out of the development cache. It was: a faked HTTP layer
         * returning two products was silently overruled by forty-nine real ones
         * left there by a live run, and the test failed with a number that came
         * from nowhere in it.
         */
        private readonly Cache $cache,
    ) {}

    /**
     * Every variable under one subject, e.g. all retail food prices.
     *
     * @return list<array{id: int, name: string}>
     */
    public function variables(string $subjectId): array
    {
        $variables = [];

        foreach ($this->results('variables', ['subject-id' => $subjectId]) as $result) {
            $name = $result['n1'] ?? null;

            // `n1` is the variable's own label and the only field naming the
            // product. A variable without one cannot be matched to anything, so
            // it is skipped here rather than carried as an unnamed reading.
            if (! is_int($result['id'] ?? null) || ! is_string($name)) {
                continue;
            }

            $variables[] = ['id' => $result['id'], 'name' => $name];
        }

        return $variables;
    }

    /**
     * The national figure for each variable, for the years asked about.
     *
     * @param  list<int>  $variableIds
     * @param  list<int>  $years
     * @return array<int, array<int, float>> Values by year, keyed by variable id.
     */
    public function nationalValues(array $variableIds, array $years): array
    {
        $values = [];

        foreach (array_chunk($variableIds, self::BATCH) as $batch) {
            $results = $this->results('data/by-unit/'.self::COUNTRY, [
                'var-id' => $batch,
                'year' => $years,
            ]);

            foreach ($results as $result) {
                $id = $result['id'] ?? null;

                if (! is_int($id)) {
                    continue;
                }

                foreach ($result['values'] ?? [] as $value) {
                    $year = (int) ($value['year'] ?? 0);
                    $figure = $value['val'] ?? null;

                    // A year the office has not published yet comes back as a
                    // null value rather than an absent one. Reading it as zero
                    // would put a free product on the estimate.
                    if ($year > 0 && is_numeric($figure)) {
                        $values[$id][$year] = (float) $figure;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Every result across every page.
     *
     * **The API paginates in tens whatever you ask it for**, and says so only in
     * `totalRecords`. Reading the first page and stopping looked exactly like a
     * source that publishes twenty prices — the request succeeded, the JSON was
     * well formed, and more than half the catalogue was silently missing. A
     * larger `page-size` is honoured for some endpoints and ignored for others,
     * so the loop is the part that can be relied on; the parameter only makes it
     * shorter.
     *
     * @param  array<string, list<int|string>|int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function results(string $path, array $query): array
    {
        $results = [];
        $page = 0;

        do {
            $body = $this->get($path, $query + ['page-size' => self::PAGE_SIZE, 'page' => $page]);
            $batch = $body['results'] ?? [];

            foreach ($batch as $result) {
                if (is_array($result)) {
                    $results[] = $result;
                }
            }

            $total = (int) ($body['totalRecords'] ?? 0);
            $page++;

            // Guarded on an empty page as well as on the count: a `totalRecords`
            // that disagreed with what it sends would otherwise loop for ever.
        } while ($batch !== [] && count($results) < $total);

        return $results;
    }

    /**
     * @param  array<string, list<int|string>|int|string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $url = rtrim((string) config('pricing.sources.gus.base_url'), '/').'/'.$path
            .'?'.$this->queryString($query + ['format' => 'json', 'lang' => 'pl']);

        /*
         * Cached because the answer changes once a year at most, and because a
         * failed run re-tried five minutes later should not spend the anonymous
         * rate limit twice on the same question. Keyed on the whole URL, so
         * asking about different years is a different question.
         */
        return $this->cache->remember(
            'pricing:gus:'.md5($url),
            now()->addHours((int) config('pricing.sources.gus.cache_ttl_hours', 24)),
            function () use ($url): array {
                $response = $this->http
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => (string) config('pricing.user_agent'),
                    ])
                    ->when(
                        is_string(config('pricing.sources.gus.client_id')),
                        fn ($request) => $request->withHeaders([
                            'X-ClientId' => (string) config('pricing.sources.gus.client_id'),
                        ]),
                    )
                    ->timeout((int) config('pricing.sources.gus.timeout_seconds', 30))
                    ->get($url)
                    ->throw();

                /** @var array<string, mixed> $body */
                $body = $response->json() ?? [];

                return $body;
            },
        );
    }

    /**
     * The API asks for a list by **repeating the key** — `var-id=1&var-id=2`.
     *
     * Built by hand because every standard encoder writes `var-id[0]=1` instead,
     * which this API does not read as a list: it answered with a fraction of what
     * was asked for and no error at all, so the first run looked like a source
     * that simply publishes less than it does. A silent wrong answer is worth
     * eight lines of string building.
     *
     * @param  array<string, list<int|string>|int|string>  $query
     */
    private function queryString(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $one);
            }
        }

        return implode('&', $parts);
    }
}
