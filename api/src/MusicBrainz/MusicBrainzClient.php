<?php

declare(strict_types=1);

namespace MusicRadar\MusicBrainz;

/**
 * Minimal read-only client for the MusicBrainz web service
 * (https://musicbrainz.org/doc/MusicBrainz_API). No API key needed.
 *
 * MusicBrainz rules we follow:
 *  - max 1 request per second (enforced here, across calls)
 *  - a meaningful User-Agent with a way to contact us
 *  - back off and retry on 503 (rate limited)
 *
 * Responses can be cached on disk (optional) so repeated local runs don't
 * hammer the service. The cache lives outside the web root.
 */
final class MusicBrainzClient
{
    private const ENDPOINT = 'https://musicbrainz.org/ws/2/';
    private const USER_AGENT = 'MartinsMusicRadar/0.1 ( https://musik.hornpedersen.dk )';
    private const MIN_INTERVAL = 1.1; // seconds between requests

    private float $lastRequestAt = 0.0;

    /**
     * @param int $maxAttempts  tries per request when rate limited (backoff 3, 6, 9 ... s);
     *                          keep it low under a strict time limit (server import)
     * @param \Closure(string): array|null $transport  replaces the HTTP
     *                          call (tests): url -> [status, body, error, optional Retry-After seconds]
     */
    public function __construct(
        private readonly ?string $cacheDir = null,
        private readonly int $cacheTtlSeconds = 86_400 * 7,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxAttempts = 6,
        private readonly ?\Closure $transport = null,
    ) {
        if ($cacheDir !== null && !is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            throw new MusicBrainzException("Cannot create cache directory $cacheDir");
        }
    }

    /**
     * Artist search. Each result has id, name, score (0-100), type, country,
     * disambiguation, aliases and tags (folksonomy).
     *
     * @return list<array<string, mixed>>
     */
    public function searchArtists(string $name, int $limit = 10): array
    {
        $query = 'artist:"' . self::escapeLucene($name) . '"';
        $data = $this->get('artist', ['query' => $query, 'limit' => $limit]);
        return $data['artists'] ?? [];
    }

    /**
     * Full artist record incl. aliases, genres and tags.
     *
     * @return array<string, mixed>
     */
    public function lookupArtist(string $mbid): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            throw new MusicBrainzException("Invalid MBID '$mbid'.");
        }
        return $this->get("artist/$mbid", ['inc' => 'aliases genres tags']);
    }

    /**
     * The artist's Last.fm page name from MusicBrainz' documented url
     * relations, or null when MusicBrainz has no Last.fm link. Confirms that a
     * Last.fm page found by name is this artist.
     */
    public function artistLastfmPage(string $mbid): ?string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            throw new MusicBrainzException("Invalid MBID '$mbid'.");
        }
        foreach ($this->get("artist/$mbid", ['inc' => 'url-rels'])['relations'] ?? [] as $rel) {
            if (preg_match('#last\.fm/(?:[a-z]{2}/)?music/([^/?\#]+)#i', (string) ($rel['url']['resource'] ?? ''), $m)) {
                return urldecode(str_replace('+', ' ', $m[1]));
            }
        }
        return null;
    }

    /**
     * Release-group search (Lucene query). Returns ['count' => int, 'release-groups' => list].
     *
     * @return array{count: int, release-groups: list<array<string, mixed>>}
     */
    public function searchReleaseGroups(string $query, int $limit = 100, int $offset = 0, ?int $maxAgeSeconds = null): array
    {
        $data = $this->get('release-group', ['query' => $query, 'limit' => min($limit, 100), 'offset' => $offset], $maxAgeSeconds);
        return ['count' => (int) ($data['count'] ?? 0), 'release-groups' => $data['release-groups'] ?? []];
    }

    /**
     * Release search (Lucene query). Returns ['count' => int, 'releases' => list];
     * each release has its date, track-count, artist credit and release group.
     *
     * @return array{count: int, releases: list<array<string, mixed>>}
     */
    public function searchReleases(string $query, int $limit = 100, int $offset = 0, ?int $maxAgeSeconds = null): array
    {
        $data = $this->get('release', ['query' => $query, 'limit' => min($limit, 100), 'offset' => $offset], $maxAgeSeconds);
        return ['count' => (int) ($data['count'] ?? 0), 'releases' => $data['releases'] ?? []];
    }

    /**
     * Many artists in one request: an artist search on their MBIDs. Search
     * results carry tags, country, area, type, start date and description -
     * enough for scoring - at 1/50 of the requests of one lookup per artist.
     *
     * @param list<string> $mbids  up to ~60 per call (URL length)
     * @return array<string, array<string, mixed>>  by MBID; missing ones are not on MusicBrainz' index
     */
    public function artistsByIds(array $mbids): array
    {
        $mbids = array_values(array_filter(array_unique($mbids), static fn ($m) => (bool) preg_match('/^[0-9a-f-]{36}$/', $m)));
        if ($mbids === []) {
            return [];
        }
        $data = $this->get('artist', ['query' => 'arid:(' . implode(' OR ', $mbids) . ')', 'limit' => 100]);
        $out = [];
        foreach ($data['artists'] ?? [] as $a) {
            $out[(string) $a['id']] = $a;
        }
        return $out;
    }

    /**
     * Concrete editions with their credits, track counts and streaming relations.
     *
     * @return array<string, mixed>
     */
    public function releaseEditions(string $mbid, ?int $maxAgeSeconds = null): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            throw new MusicBrainzException('Invalid release-group ID');
        }
        // One bounded page: the link matcher rejects incomplete responses.
        return $this->get('release', ['release-group' => $mbid, 'inc' => 'artist-credits labels url-rels recordings', 'limit' => 100], $maxAgeSeconds);
    }

    /** A release group with its releases (to check release dates). */
    public function lookupReleaseGroup(string $mbid): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            throw new MusicBrainzException("Invalid MBID '$mbid'.");
        }
        return $this->get("release-group/$mbid", ['inc' => 'releases']);
    }

    /**
     * Many release groups in one request (a search on their ids), with their
     * full artist credits - like artistsByIds().
     *
     * @param list<string> $ids  up to ~60 per call (URL length)
     * @param int|null $maxAgeSeconds  how old a cached answer may be (default: the client's cache time)
     * @return array<string, array<string, mixed>>  by id; missing ones are not on MusicBrainz' index (yet)
     */
    public function releaseGroupsByIds(array $ids, ?int $maxAgeSeconds = null): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn ($m) => (bool) preg_match('/^[0-9a-f-]{36}$/', $m)));
        if ($ids === []) {
            return [];
        }
        $data = $this->get('release-group', ['query' => 'rgid:(' . implode(' OR ', $ids) . ')', 'limit' => 100], $maxAgeSeconds);
        $out = [];
        foreach ($data['release-groups'] ?? [] as $rg) {
            $out[(string) $rg['id']] = $rg;
        }
        return $out;
    }

    /**
     * One release group with its artist credits (for one the search index misses).
     *
     * @return array<string, mixed>
     */
    public function lookupReleaseGroupCredits(string $mbid): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $mbid)) {
            throw new MusicBrainzException("Invalid MBID '$mbid'.");
        }
        return $this->get("release-group/$mbid", ['inc' => 'artist-credits']);
    }

    /**
     * All release groups of an artist of the given primary types (browse, not
     * search) - also those where the artist is credited second or later, each
     * with its full artist credit.
     *
     * @return list<array<string, mixed>>
     */
    public function artistReleaseGroups(string $artistMbid, string $types = 'album|ep'): array
    {
        $all = [];
        $offset = 0;
        do {
            $data = $this->get('release-group', ['artist' => $artistMbid, 'type' => $types, 'inc' => 'artist-credits', 'limit' => 100, 'offset' => $offset]);
            $page = $data['release-groups'] ?? [];
            $all = [...$all, ...$page];
            $offset += 100;
        } while ($page !== [] && $offset < (int) ($data['release-group-count'] ?? 0) && $offset < 500);
        return $all;
    }

    /**
     * @param array<string, scalar> $params
     * @param int|null $maxAgeSeconds  accept a cached answer only this fresh (default: cache TTL)
     */
    private function get(string $path, array $params, ?int $maxAgeSeconds = null): array
    {
        $url = self::ENDPOINT . $path . '?' . http_build_query($params + ['fmt' => 'json']);
        $cacheFile = $this->cacheDir ? $this->cacheDir . '/' . sha1($url) . '.json' : null;
        $ttl = $maxAgeSeconds ?? $this->cacheTtlSeconds;

        if ($cacheFile && is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->throttle();
            $response = $this->request($url);
            [$status, $body, $error] = $response;
            $retryAfter = $response[3] ?? 0;

            if ($status === 503 || $status === 429) {
                if ($attempt < $this->maxAttempts) {
                    // Do not extend a bounded server request to wait for a long Retry-After.
                    if ($retryAfter > $attempt * 3) {
                        throw new MusicBrainzException("MusicBrainz returned HTTP $status for $path", $status, $retryAfter);
                    }
                    usleep($attempt * 3_000_000); // rate limited - back off (3, 6, 9 ... s)
                }
                continue;
            }
            if ($body === null) {
                throw new MusicBrainzException("MusicBrainz request failed ($path): $error", $status, $retryAfter);
            }
            if ($status === 404) {
                throw new MusicBrainzException("MusicBrainz: not found ($path)", $status);
            }
            $data = json_decode($body, true);
            if ($status !== 200 || !is_array($data)) {
                throw new MusicBrainzException("MusicBrainz returned HTTP $status for $path", $status, $retryAfter);
            }
            if ($cacheFile) {
                file_put_contents($cacheFile, $body);
            }
            return $data;
        }

        throw new MusicBrainzException("MusicBrainz kept rate limiting requests ($path)", $status ?? 0, $retryAfter ?? 0);
    }

    /** @return array{int, ?string, string, int} status, body, error, Retry-After seconds */
    private function request(string $url): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url);
        }
        $ch = curl_init($url);
        $retryAfter = 0;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, strlen('Retry-After:')));
                    $retryAfter = ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: 0) - time());
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, $body === false ? null : (string) $body, curl_error($ch), $retryAfter];
    }

    private function throttle(): void
    {
        if ($this->transport !== null) {
            return;
        }
        $wait = self::MIN_INTERVAL - (microtime(true) - $this->lastRequestAt);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }

    private static function escapeLucene(string $s): string
    {
        return (string) preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $s);
    }
}
