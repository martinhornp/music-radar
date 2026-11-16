<?php

declare(strict_types=1);

namespace MusicRadar\LastFm;

/**
 * Minimal read-only client for the Last.fm API (https://www.last.fm/api).
 *
 * The API key is sent as a query parameter, as Last.fm requires. It is never
 * included in exceptions or logs - error messages only contain the method.
 */
final class LastFmClient
{
    private const ENDPOINT = 'https://ws.audioscrobbler.com/2.0/';
    private const USER_AGENT = 'MartinsMusicRadar/0.1 (+https://musik.hornpedersen.dk)';

    public const PERIODS = ['overall', '7day', '1month', '3month', '6month', '12month'];

    /** Last.fm asks for a reasonable pace; we stay at ~4 requests per second. */
    private const MIN_INTERVAL = 0.25;
    private float $lastRequestAt = 0.0;

    /**
     * @param \Closure(string): array{int, ?string, string}|null $transport  replaces the HTTP
     *                          call (tests): url -> [status, body, error]
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 10,
        private readonly ?\Closure $transport = null,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/i', $apiKey)) {
            throw new LastFmException(
                $apiKey === ''
                    ? 'Last.fm API key is not configured (set lastfm.api_key in api/config/config.local.php).'
                    : 'Last.fm API key looks malformed (expected 32 hexadecimal characters).'
            );
        }
    }

    /**
     * A user's most played artists.
     *
     * `mbid` is Last.fm's MusicBrainz ID guess - a hint only, it can point to
     * a different artist with a similar name (e.g. "SYL").
     *
     * @return list<array{name: string, playcount: int, url: string, mbid: string}>
     */
    public function userTopArtists(string $user, string $period = 'overall', int $limit = 10): array
    {
        if (!in_array($period, self::PERIODS, true)) {
            throw new LastFmException("Invalid period '$period'.");
        }

        $data = $this->call('user.getTopArtists', [
            'user' => $user,
            'period' => $period,
            'limit' => max(1, min($limit, 1000)),
        ]);

        $artists = $data['topartists']['artist'] ?? [];

        return array_map(
            static fn (array $a): array => [
                'name' => (string) ($a['name'] ?? ''),
                'playcount' => (int) ($a['playcount'] ?? 0),
                'url' => (string) ($a['url'] ?? ''),
                'mbid' => (string) ($a['mbid'] ?? ''),
            ],
            $artists,
        );
    }

    /**
     * A user's most played albums.
     *
     * @return list<array{name: string, artist: string, playcount: int, url: string}>
     */
    public function userTopAlbums(string $user, string $period = 'overall', int $limit = 10): array
    {
        if (!in_array($period, self::PERIODS, true)) {
            throw new LastFmException("Invalid period '$period'.");
        }

        $data = $this->call('user.getTopAlbums', [
            'user' => $user,
            'period' => $period,
            'limit' => max(1, min($limit, 1000)),
        ]);

        return array_map(
            static fn (array $a): array => [
                'name' => (string) ($a['name'] ?? ''),
                'artist' => (string) ($a['artist']['name'] ?? ''),
                'playcount' => (int) ($a['playcount'] ?? 0),
                'url' => (string) ($a['url'] ?? ''),
            ],
            $data['topalbums']['album'] ?? [],
        );
    }

    /**
     * Tags Last.fm users have applied to an artist, strongest first.
     * `count` is Last.fm's relative weight (100 = the artist's top tag).
     *
     * @return array{artist: string, tags: list<array{name: string, count: int}>}
     *   `artist` is the name as Last.fm returned it.
     */
    public function artistTopTags(string $artist): array
    {
        // No autocorrect: names come from the user's own charts and are exact.
        // Autocorrect could swap in a different artist (e.g. 'SYL').
        $data = $this->call('artist.getTopTags', ['artist' => $artist, 'autocorrect' => 0]);

        return [
            'artist' => (string) ($data['toptags']['@attr']['artist'] ?? $artist),
            'tags' => array_map(
                static fn (array $t): array => ['name' => (string) ($t['name'] ?? ''), 'count' => (int) ($t['count'] ?? 0)],
                $data['toptags']['tag'] ?? [],
            ),
        ];
    }

    /**
     * Last.fm Similar Artists for one artist (up to $limit, strongest first):
     * by MusicBrainz id when given, else by exact name (no autocorrect).
     * `match` is relative within this list (the strongest is 1) and says that
     * listeners connect the artists - not that they sound alike. `artist` is
     * the page name Last.fm answered with. Null if Last.fm doesn't know the
     * artist (error 6).
     *
     * @return array{artist: string, similar: list<array{name: string, mbid: string, match: float}>}|null
     */
    public function artistSimilar(?string $mbid, string $name, int $limit = 250): ?array
    {
        try {
            $data = $this->call('artist.getSimilar', ($mbid ? ['mbid' => $mbid] : ['artist' => $name, 'autocorrect' => 0]) + ['limit' => $limit]);
        } catch (LastFmException $e) {
            if (str_contains($e->getMessage(), 'error 6 ')) {
                return null; // not found
            }
            throw $e;
        }
        return [
            'artist' => (string) ($data['similarartists']['@attr']['artist'] ?? $name),
            'similar' => array_map(static fn (array $s): array => ['name' => (string) ($s['name'] ?? ''), 'mbid' => (string) ($s['mbid'] ?? ''),
                'match' => round((float) ($s['match'] ?? 0), 4)], $data['similarartists']['artist'] ?? []),
        ];
    }

    /**
     * Artist bio summary (Last.fm wiki, user-written) and top tags (Similar
     * Artists: artistSimilar()). Returns null if Last.fm doesn't know the artist.
     *
     * `mbid` is Last.fm's MusicBrainz ID for the artist ('' if none).
     *
     * @return array{name: string, mbid: string, bio: string, tags: list<string>}|null
     */
    public function artistInfo(string $artist): ?array
    {
        try {
            $data = $this->call('artist.getInfo', ['artist' => $artist, 'autocorrect' => 0]);
        } catch (LastFmException $e) {
            if (str_contains($e->getMessage(), 'error 6 ')) {
                return null; // not found
            }
            throw $e;
        }
        $a = $data['artist'] ?? [];
        return [
            'name' => (string) ($a['name'] ?? $artist),
            'mbid' => (string) ($a['mbid'] ?? ''),
            'bio' => self::cleanWiki((string) ($a['bio']['summary'] ?? '')),
            'tags' => array_column($a['tags']['tag'] ?? [], 'name'),
        ];
    }

    /**
     * Album description (Last.fm wiki) and tags, or null if unknown.
     *
     * @return array{name: string, description: string, tags: list<string>}|null
     */
    public function albumInfo(string $artist, string $album): ?array
    {
        try {
            $data = $this->call('album.getInfo', ['artist' => $artist, 'album' => $album, 'autocorrect' => 0]);
        } catch (LastFmException $e) {
            if (str_contains($e->getMessage(), 'error 6 ')) {
                return null;
            }
            throw $e;
        }
        $a = $data['album'] ?? [];
        $tags = $a['tags']['tag'] ?? [];
        if (isset($tags['name'])) {
            $tags = [$tags]; // single tag comes back as an object
        }
        return [
            'name' => (string) ($a['name'] ?? $album),
            'description' => self::cleanWiki((string) ($a['wiki']['summary'] ?? '')),
            'tags' => array_column(is_array($tags) ? $tags : [], 'name'),
        ];
    }

    /** Strips HTML and Last.fm's "Read more on Last.fm" link from wiki text. */
    private static function cleanWiki(string $html): string
    {
        $text = (string) preg_replace('/<a [^>]*>Read more on Last\.fm<\/a>\.?/i', '', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Calls a read-only (unsigned) API method and returns the decoded JSON.
     *
     * @param array<string, scalar> $params
     */
    public function call(string $method, array $params = []): array
    {
        $url = self::ENDPOINT . '?' . http_build_query(
            ['method' => $method] + $params + ['api_key' => $this->apiKey, 'format' => 'json']
        );

        if ($this->transport !== null) {
            [$status, $body, $curlError] = ($this->transport)($url);
            $body ??= false;
        } else {
            $wait = self::MIN_INTERVAL - (microtime(true) - $this->lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $this->lastRequestAt = microtime(true);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
        }

        if ($body === false) {
            throw new LastFmException("Last.fm request for $method failed: $curlError");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new LastFmException("Last.fm returned an invalid response for $method (HTTP $status).");
        }

        // Last.fm reports API errors in the body, e.g. 10 = invalid key, 6 = unknown user.
        if (isset($data['error'])) {
            $message = (string) ($data['message'] ?? 'unknown error');
            throw new LastFmException("Last.fm error {$data['error']} for $method: $message");
        }

        return $data;
    }
}
