<?php

declare(strict_types=1);

namespace MusicRadar\Feedback;

use MusicRadar\Database\Database;
use RuntimeException;

/**
 * The bridge between stored feedback and the recommendation engine.
 *
 * The same items (the format of GET /api/feedback) can come from:
 *   - the database directly (on the server, or the local Docker database)
 *   - the website's API (the discovery pipeline running on this PC reads the
 *     ratings saved on one.com - the database there is not reachable from
 *     outside)
 * and are turned into the engine's feedback: ratings with the rated
 * release's tags and artist, and the releases marked "Not interested".
 * "Save for later" is left out on purpose - a bookmark is not a taste signal.
 */
final class FeedbackSet
{
    /** @param list<array> $items */
    private function __construct(public readonly array $items)
    {
    }

    public static function fromItems(array $items): self
    {
        $valid = array_values(array_filter($items, static fn ($i) => is_array($i)
            && is_string($i['releaseId'] ?? null) && FeedbackChange::validReleaseId($i['releaseId'])
            && is_array($i['release'] ?? null)));
        return new self($valid);
    }

    /**
     * "none" (no feedback), "db" (the configured database) or the URL of a
     * site's GET /api/feedback.
     *
     * @throws RuntimeException if the source cannot be read - better to stop
     *                          than to recommend silently without your ratings
     */
    public static function fromSource(string $source, array $dbConfig): self
    {
        if ($source === 'none') {
            return new self([]);
        }
        if ($source === 'db') {
            if (!Database::isConfigured($dbConfig)) {
                throw new RuntimeException('No database configured for feedback');
            }
            return self::fromRepository(new FeedbackRepository(Database::connect($dbConfig)));
        }
        if (preg_match('#^https?://#', $source)) {
            return self::fromUrl($source);
        }
        throw new RuntimeException("Unknown feedback source \"$source\" (use none, db or a URL)");
    }

    public static function fromRepository(FeedbackRepository $repository): self
    {
        return self::fromItems($repository->all());
    }

    /** @throws RuntimeException if the API cannot be read */
    public static function fromUrl(string $url, int $timeoutSeconds = 20): self
    {
        $context = stream_context_create(['http' => [
            'timeout' => $timeoutSeconds,
            'header' => "Accept: application/json\r\nUser-Agent: MartinsMusicRadar/0.1 (discovery pipeline)\r\n",
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        if ($body === false || $status !== 200) {
            throw new RuntimeException("Could not read feedback from $url (HTTP $status)");
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            throw new RuntimeException("Unexpected feedback response from $url");
        }
        return self::fromItems($data['items']);
    }

    /** @return array{ratings: list<array>, not_interested_releases: list<string>} */
    public function forEngine(): array
    {
        $ratings = [];
        $notInterested = [];
        foreach ($this->items as $i) {
            if (is_int($i['rating'] ?? null) && $i['rating'] >= 1 && $i['rating'] <= 5) {
                $ratings[] = [
                    'release_id' => $i['releaseId'],
                    'artist_mbid' => $i['release']['artistMbid'] ?? null,
                    'artist' => (string) ($i['release']['artist'] ?? ''),
                    'title' => (string) ($i['release']['title'] ?? ''),
                    'tags' => array_values(array_filter((array) ($i['release']['tags'] ?? []), 'is_string')),
                    'stars' => $i['rating'],
                ];
            }
            if (($i['notInterested'] ?? false) === true) {
                $notInterested[] = $i['releaseId'];
                // Also a 1-star signal for the artist only: no tags, so genres are
                // untouched. A release is never both rated and Not interested.
                if (($i['release']['artistMbid'] ?? null) && !is_int($i['rating'] ?? null)) {
                    $ratings[] = [
                        'release_id' => $i['releaseId'],
                        'artist_mbid' => $i['release']['artistMbid'],
                        'artist' => (string) ($i['release']['artist'] ?? ''),
                        'title' => (string) ($i['release']['title'] ?? ''),
                        'tags' => [],
                        'stars' => 1,
                        'not_interested' => true,
                    ];
                }
            }
        }
        return ['ratings' => $ratings, 'not_interested_releases' => $notInterested];
    }

    /** @return array{ratings: int, not_interested: int, saved: int} */
    public function summary(): array
    {
        return [
            'ratings' => count(array_filter($this->items, static fn ($i) => ($i['rating'] ?? null) !== null)),
            'not_interested' => count(array_filter($this->items, static fn ($i) => ($i['notInterested'] ?? false) === true)),
            'saved' => count(array_filter($this->items, static fn ($i) => ($i['saved'] ?? false) === true)),
        ];
    }
}
