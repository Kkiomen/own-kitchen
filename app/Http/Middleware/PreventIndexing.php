<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-up is open to friends, but this is not something to be *found*: you
 * come here because somebody gave you the address.
 *
 * **The header is the load-bearing half, not `robots.txt`.** `Disallow: /` only
 * asks a crawler not to *fetch* a page — and a page that is never fetched is a
 * page whose "do not index" was never read, so a URL linked from anywhere else
 * can still be listed by its address alone. `X-Robots-Tag` is the instruction
 * that actually keeps it out of an index, and it travels on every response
 * rather than only on the HTML ones: a shared shopping list rendered as JSON is
 * as private as the page around it.
 *
 * Global rather than on the `web` group, so `/up` and anything added later are
 * covered without anybody having to remember this file exists.
 */
class PreventIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // `noimageindex` matters here: nearly every screen carries a recipe
        // photo, and image search is a way in that ignores the page itself.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');

        return $response;
    }
}
