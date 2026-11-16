<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

/**
 * Which artists to watch for new albums and EPs - two levels:
 *
 *   following   you chose to follow it (manual only). New releases are
 *               ALWAYS included in "Recommended for You".
 *   interested  from your Last.fm history (or kept from earlier). New
 *               releases are detected and evaluated against your profile -
 *               never silently missed just because you haven't followed yet.
 *   ignored     you chose not to watch it.
 *
 * Every Last.fm top artist starts as "interested" - incl. new interests
 * (Initiate, Speed, Drain) and old favourites. Nothing is removed
 * automatically: an artist that drops out of the Last.fm top lists stays
 * "interested" (see ProfileBuilder::retain()) until you change it.
 *
 * The old automatic rules now only produce a *suggestion* to follow.
 */
final class FollowPolicy
{
    public const FOLLOWING = 'following';
    public const INTERESTED = 'interested';
    public const IGNORED = 'ignored';

    public const LEVELS = [self::FOLLOWING, self::INTERESTED, self::IGNORED];

    /**
     * @param array<string, array{rank: int, plays: int}> $periods keyed by overall / 12month / 3month
     * @param string|null $manualLevel  level chosen in artist-overrides.php
     * @param bool $retained            not in the current Last.fm lists, kept from earlier
     * @return array{level: string, reason: string, source: string, suggest_following: ?string}
     */
    public static function decide(array $periods, ?string $manualLevel = null, bool $retained = false): array
    {
        $suggestion = self::suggestion($periods);

        if ($manualLevel !== null) {
            if (!in_array($manualLevel, self::LEVELS, true)) {
                throw new \InvalidArgumentException("Unknown level '$manualLevel'");
            }
            return [
                'level' => $manualLevel,
                'reason' => match ($manualLevel) {
                    self::FOLLOWING => 'You follow this artist',
                    self::INTERESTED => 'You marked this artist as interesting',
                    self::IGNORED => 'You chose not to watch this artist',
                },
                'source' => 'manual',
                'suggest_following' => $manualLevel === self::INTERESTED ? $suggestion : null,
            ];
        }

        $all = $periods['overall'] ?? null;
        $year = $periods['12month'] ?? null;
        $recent = $periods['3month'] ?? null;

        [$reason, $source] = match (true) {
            $retained => ['Was among your top artists earlier - kept', 'retained'],
            $all && $year && $recent => ['A constant in your listening - top artist all-time, this year and lately', 'lastfm_core'],
            $all !== null => ["One of your all-time favourites (#{$all['rank']}, {$all['plays']} plays)", 'lastfm_alltime'],
            $year !== null && $recent !== null => ['In your top artists this year and lately', 'lastfm_sustained'],
            $year !== null => ["Played a lot this year (#{$year['rank']})", 'lastfm_12month'],
            $recent !== null => ["New in your listening (#{$recent['rank']} the last 3 months)", 'lastfm_3month'],
            default => ['Known from your listening history', 'lastfm'],
        };

        return ['level' => self::INTERESTED, 'reason' => $reason, 'source' => $source, 'suggest_following' => $suggestion];
    }

    /** Strong, lasting signals - worth asking whether to follow. */
    private static function suggestion(array $periods): ?string
    {
        $all = $periods['overall'] ?? null;
        $year = $periods['12month'] ?? null;
        $recent = $periods['3month'] ?? null;

        return match (true) {
            $all && $year && $recent => 'top artist in all periods',
            $all !== null && $all['rank'] <= 15 => "all-time top 15 (#{$all['rank']})",
            $year !== null && $year['rank'] <= 15 => "this year's top 15 (#{$year['rank']})",
            $year !== null && $recent !== null => 'stayed in your top artists all year',
            default => null,
        };
    }
}
