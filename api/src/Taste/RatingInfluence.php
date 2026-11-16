<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

/**
 * How much the listener's own ratings should count versus Last.fm.
 *
 * Ratings take over gradually and no single rating can swing the profile:
 *   - overall share of ratings in the profile = n / (n + K), capped at MAX
 *     (0 ratings -> 0 %, 15 -> 50 % of the cap, many -> the cap)
 *   - a genre's rating signal is a shrunk average: ratings for that genre
 *     are pulled toward neutral (3 stars) by PRIOR imaginary neutral ratings,
 *     so one 1-star or 5-star review only nudges it.
 *   - "Not interested" and "Save for later" are NOT ratings: the first is a
 *     separate negative signal on the release/artist, the second is ignored
 *     by the profile.
 */
final class RatingInfluence
{
    public const K = 15;
    public const MAX = 0.6;
    public const PRIOR = 5;

    public static function share(int $ratingCount): float
    {
        return $ratingCount <= 0 ? 0.0 : self::MAX * $ratingCount / ($ratingCount + self::K);
    }

    /**
     * Signal for one genre in [-1, 1] from its ratings (1-5 stars).
     *
     * @param list<int> $stars
     */
    public static function genreSignal(array $stars): float
    {
        $n = count($stars);
        $mean = (array_sum($stars) + 3 * self::PRIOR) / ($n + self::PRIOR);
        return round(($mean - 3) / 2, 3);
    }
}
