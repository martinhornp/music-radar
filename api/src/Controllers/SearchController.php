<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;
use MusicRadar\Search\ReleaseSearch;
use PDO;
use Throwable;

/**
 * GET /api/search?q=...  - artist and title search in every album/EP the
 * radar has stored, with each result's status in the current
 * recommendations: recommended, wildcard, or stored but not recommended.
 * Read-only; at most ReleaseSearch::MAX_RESULTS results per request.
 */
final class SearchController
{
    /** @param Closure(): PDO $db */
    public function __construct(private readonly Closure $db, private readonly ?Closure $links = null)
    {
    }

    public function index(Request $request): Response
    {
        $query = trim((string) ($request->query['q'] ?? ''));
        if (!mb_check_encoding($query, 'UTF-8')) {
            return Response::error('Invalid characters in the search', 400);
        }
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return Response::error('Search for 2 to 100 characters', 400);
        }
        try {
            $pdo = ($this->db)();
            $search = new ReleaseSearch($pdo);
            $found = $search->search($query);
            $runs = new RecommendationStore($pdo);
            $ids = array_column($found['rows'], 'id');
            $inFeed = $runs->activeItems($ids);
            // Assessed but not picked: the current score too (as in My Music);
            // a release the current run did not assess has none.
            $scores = $runs->activeScores(array_values(array_diff($ids, array_keys($inFeed))));
            $results = array_map(static function (array $row) use ($inFeed, $scores, $search): array {
                $item = $inFeed[$row['id']] ?? null;
                return [
                    // recommended | wildcard | not_recommended
                    'status' => $item ? $item['pick']['kind'] : 'not_recommended',
                    'release' => $item ?? array_replace($search->toFeedEntry($row), $scores[$row['id']] ?? []),
                ];
            }, $found['rows']);
            if ($this->links !== null) {
                $linked = ($this->links)(array_column($results, 'release'));
                foreach ($results as $i => &$result) {
                    $result['release'] = $linked[$i];
                }
                unset($result);
            }
            return new Response([
                'query' => $query,
                'results' => $results,
                'more' => $found['more'],
                'coverage' => $search->coverage(),
            ], 200, ['Cache-Control' => 'public, max-age=60']);
        } catch (Throwable $e) {
            error_log('search: ' . $e::class . ': ' . $e->getMessage());
            return Response::error('Search is unavailable right now', 503);
        }
    }
}
