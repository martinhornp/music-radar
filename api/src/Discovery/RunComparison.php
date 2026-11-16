<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

/**
 * Compares two discovery results (result.json): how many releases, source
 * mix, genre breadth, missing data - and for every recommendation/wildcard
 * whether it was kept, dropped or added, and why.
 */
final class RunComparison
{
    public static function compare(array $prev, array $now): array
    {
        $prevAll = array_column($prev['releases'], null, 'id');
        $nowAll = array_column($now['releases'], null, 'id');
        $nowDecision = array_map(static fn ($a) => $a['decision'] ?? null, $now['assessments'] ?? []);
        $label = static fn (array $r) => "{$r['artist']['name']} - {$r['title']}";

        $why = static function (string $id) use ($nowAll, $now, $nowDecision): string {
            if (!isset($nowAll[$id])) {
                return 'not among the releases now (other candidate set / outside window)';
            }
            $a = $now['assessments'][$id] ?? [];
            return match ($nowDecision[$id] ?? null) {
                'needs_date_check' => 'release date not confirmed (' . ($nowAll[$id]['date_check']['note'] ?? '') . ')',
                'wildcard' => 'moved to Wildcards',
                'recommended' => 'moved to Recommended',
                'excluded' => $a['note'] ?? 'excluded',
                'not_assessable' => 'no genre data',
                default => sprintf('still assessed (fit %.2f) but below the thresholds%s', $a['fit'] ?? 0, isset($a['not_selected']) ? " - {$a['not_selected']}" : ''),
            };
        };
        $whyNew = static fn (string $id) => isset($prevAll[$id])
            ? 'was a candidate before, now ranked higher'
            : 'not in the previous candidates (found now)';
        $diff = static function (array $old, array $new) use ($why, $whyNew, $label, $nowAll, $prevAll): array {
            $oldIds = array_column($old, 'id');
            $newIds = array_column($new, 'id');
            return [
                'kept' => array_map(static fn ($id) => $label($nowAll[$id]), array_values(array_intersect($oldIds, $newIds))),
                'dropped' => array_map(static fn ($id) => ['release' => $label($prevAll[$id]), 'why' => $why($id)], array_values(array_diff($oldIds, $newIds))),
                'added' => array_map(static fn ($id) => ['release' => $label($nowAll[$id]), 'why' => $whyNew($id)], array_values(array_diff($newIds, $oldIds))),
            ];
        };
        $breadth = static function (array $families): array {
            $named = array_filter($families, static fn ($k) => $k !== '' && $k !== '-', ARRAY_FILTER_USE_KEY);
            arsort($named);
            $total = array_sum($named) ?: 1;
            return ['families' => count($named), 'top_family' => array_key_first($named), 'top_share' => round(reset($named) / $total, 2), 'distribution' => $named];
        };
        $summary = static fn (array $r) => [
            'experiment' => $r['experiment'] ?? '?',
            'candidates' => count(array_filter($r['releases'], static fn ($x) => !str_starts_with($x['source'], 'known_artist'))),
            'known_artist_releases' => $r['counts']['known_artist_releases'] ?? null,
            'no_genre_data' => $r['counts']['metadata_insufficient'] ?? null,
            'sources' => $r['bias']['candidate_sources'] ?? [],
            'genres' => $breadth($r['bias']['candidate_primary_families'] ?? []),
            'recommended' => count($r['recommended']),
            'wildcards' => count($r['wildcards']),
        ];

        return [
            'previous_run' => ($prev['generated_at'] ?? '?') . ' (' . ($prev['experiment'] ?? '?') . ')',
            'previous' => $summary($prev),
            'now' => $summary($now),
            'recommended' => $diff($prev['recommended'], $now['recommended']),
            'wildcards' => $diff($prev['wildcards'], $now['wildcards']),
        ];
    }
}
