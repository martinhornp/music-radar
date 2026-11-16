<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use DomainException;
use InvalidArgumentException;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feedback\FeedbackChange;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;
use Throwable;

/**
 * Your ratings, "Not interested" and "Save for later".
 *
 *   GET  /api/feedback        everything you have marked (with release snapshots,
 *                             carrying the score of the current recommendations)
 *   POST /api/feedback/{id}   one change, e.g. {"rating": 5} - nothing else can be written
 *
 * No login yet: anyone who finds the site can change this feedback. Writes
 * are therefore limited to these three fields, validated strictly, stored
 * with parameterised SQL and logged, and only accepted as JSON from this
 * site itself (other websites cannot submit them from a visitor's browser).
 * Errors never reveal database details.
 */
final class FeedbackController
{
    private const MAX_BODY = 1024;

    /** @param Closure(): FeedbackRepository $repository connects lazily; throws if no database is configured */
    /**
     * @param Closure(Request): bool $loggedIn  changes need the logged-in user (reading does not)
     */
    public function __construct(
        private readonly Closure $repository,
        private readonly CurrentFeed $feed,
        private readonly Closure $loggedIn,
        private readonly ?Closure $links = null,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            return Response::json(['items' => $this->withCurrentScore(($this->repository)()->all())]);
        } catch (Throwable $e) {
            return $this->unavailable($e);
        }
    }

    public function update(Request $request): Response
    {
        $id = (string) ($request->params['id'] ?? '');
        if (!FeedbackChange::validReleaseId($id)) {
            return Response::error('Invalid release id', 400);
        }
        if (!$this->sameOrigin($request)) {
            return Response::error('Forbidden', 403);
        }
        try {
            if (!($this->loggedIn)($request)) {
                return Response::error('Log in to rate or mark releases', 401);
            }
        } catch (Throwable $e) {
            return $this->unavailable($e);
        }
        if (!str_starts_with(strtolower($request->header('content-type')), 'application/json')) {
            return Response::error('Content-Type must be application/json', 415);
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return Response::error('Request too large', 413);
        }
        try {
            $change = FeedbackChange::fromInput(json_decode($request->body, true));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        }

        try {
            // The snapshot always comes from our own feed, never from the client.
            $snapshot = $this->feed->release($id);
            return Response::json($this->withCurrentScore([($this->repository)()->apply($id, $change, $snapshot)])[0]);
        } catch (DomainException) {
            return Response::error('Unknown release', 404);
        } catch (Throwable $e) {
            return $this->unavailable($e);
        }
    }

    /**
     * The snapshot stored with your feedback keeps the release's facts (and
     * teaches the engine its tags), but its score is from the day you marked
     * it. Show the current model's view instead: the active run's entry if it
     * picked the release, its score if it only assessed it, otherwise no score
     * at all - never the old one as if it were current.
     *
     * @param list<array{releaseId: string, release: array}> $items
     */
    private function withCurrentScore(array $items): array
    {
        $current = $this->feed->current(array_column($items, 'releaseId'));
        $none = ['score' => null, 'scoreParts' => null, 'pick' => ['kind' => 'none', 'angle' => null, 'rank' => 0], 'reasons' => []];
        foreach ($items as &$item) {
            $now = $current[$item['releaseId']] ?? [];
            $item['release'] = array_replace($item['release'] ?? [], $none, $now);
        }
        unset($item);
        if ($this->links !== null) {
            $linked = ($this->links)(array_column($items, 'release'));
            foreach ($items as $i => &$item) {
                $item['release'] = $linked[$i];
            }
            unset($item);
        }
        return $items;
    }

    /** Browsers send Origin on cross-site POSTs; it must be this site. */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === '') {
            return true; // same-origin GET/HEAD or a non-browser client
        }
        $parts = parse_url($origin);
        $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $host !== '' && strcasecmp($host, $request->header('host')) === 0;
    }

    private function unavailable(Throwable $e): Response
    {
        error_log('feedback: ' . $e::class . ': ' . $e->getMessage());
        return Response::error('Feedback is unavailable right now', 503);
    }
}
