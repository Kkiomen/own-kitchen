<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A private kitchen for one household stays out of every search index.
 *
 * The header is what these pin, not `robots.txt`: a crawler told not to fetch a
 * page never reads that page's "do not index", so the file alone would still
 * allow the address itself to be listed.
 */
class PreventIndexingTest extends TestCase
{
    use RefreshDatabase;

    private const string EXPECTED = 'noindex, nofollow, noarchive, noimageindex';

    public function test_a_signed_in_page_says_it_must_not_be_indexed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertHeader('X-Robots-Tag', self::EXPECTED);
    }

    /**
     * The pages a crawler can actually reach are the ones that matter — the
     * rest answer a redirect to this one.
     */
    public function test_the_login_page_says_it_too(): void
    {
        $this->get(route('login'))->assertHeader('X-Robots-Tag', self::EXPECTED);
    }

    /** A redirect is a response a crawler follows, so it carries it as well. */
    public function test_a_redirect_away_from_a_private_page_says_it_too(): void
    {
        $this->get(route('pantry.index'))
            ->assertRedirect(route('login'))
            ->assertHeader('X-Robots-Tag', self::EXPECTED);
    }

    public function test_the_health_check_says_it_too(): void
    {
        $this->get('/up')->assertHeader('X-Robots-Tag', self::EXPECTED);
    }

    public function test_robots_txt_turns_every_crawler_away(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertIsString($robots);
        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /', $robots);
    }
}
