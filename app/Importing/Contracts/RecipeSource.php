<?php

declare(strict_types=1);

namespace App\Importing\Contracts;

use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Importing\Exceptions\RecipeNotParsable;

/**
 * Inbound port for a website we import recipes from. One implementation per site;
 * adding a site never touches the pipeline, only adds an adapter.
 *
 * Implementations are responsible for honouring the site's crawl policy.
 */
interface RecipeSource
{
    /**
     * Stable identifier stored on every imported recipe, e.g. "kwestiasmaku.com".
     */
    public function name(): string;

    /**
     * Walk the site's listings and yield recipe references, newest first.
     *
     * @return iterable<RecipeReference>
     */
    public function discover(int $limit): iterable;

    /**
     * @throws RecipeNotParsable
     */
    public function fetch(RecipeReference $reference): RecipeDraft;
}
