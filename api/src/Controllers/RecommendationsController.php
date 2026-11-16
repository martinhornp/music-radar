<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;

/**
 * Recommended for You + Wildcards: the active recommendation run in the
 * database (written by the daily import). Read-only.
 */
final class RecommendationsController
{
    public function __construct(private readonly CurrentFeed $feed, private readonly ?Closure $links = null)
    {
    }

    public function index(Request $request): Response
    {
        try {
            $feed = $this->feed->load()['feed'];
        } catch (\JsonException $e) {
            error_log('feed.json is invalid: ' . $e->getMessage());
            return Response::error('Recommendations are unavailable', 500);
        }
        if ($feed === null) {
            return Response::error('No recommendations available yet', 404);
        }
        if ($this->links !== null) {
            $feed['releases'] = ($this->links)($feed['releases']);
        }
        // Changes at most once per import: let browsers reuse it briefly.
        return new Response($feed, 200, ['Cache-Control' => 'public, max-age=300']);
    }
}
