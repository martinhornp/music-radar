<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

/**
 * How much a genre tag tells about the music (0..1), learned from the releases
 * themselves - no hand-kept list of exceptions:
 *
 *   frequency  a tag on many releases ("rock", "electronic") says less:
 *              min(1, ln(N / releases with the tag) / ln(frequency_ref))
 *   cohesion   do the releases carrying the tag resemble each other on their
 *              OTHER tags? Specific genres ("hard techno", "beatdown hardcore")
 *              gather related music; broad ones cut across. The mean pairwise
 *              cosine, exact in one pass (|sum of unit vectors|^2 - n) / (n(n-1)),
 *              shrunk toward the average for rare tags (cohesion_prior), so a
 *              rare but vague tag is not strong evidence by default
 *   structure  the existing short list of broad tags caps them at
 *              generic_weight; a tag made of broad words only ("pop rock",
 *              "alternative rock", "indie pop") at broad_compound_cap
 *
 *   information = min(structure cap, sqrt(frequency x cohesion))
 *
 * Also the scale's "full information" (FULL_GENRE_INFORMATION was 2 specific
 * tags at 1.0 each): 2 x the mean information of the specific tags present.
 */
final class TagInformation
{
    /**
     * @param list<array<string, float>> $vectors  one tag => weight vector per release
     * @return array{info: array<string, float>, default: float, full: float}
     */
    public static function fromVectors(array $vectors, array $config): array
    {
        $c = $config['tag_information'];
        $n = count($vectors);
        $df = [];
        $sum = [];
        $withOthers = [];
        foreach ($vectors as $v) {
            foreach ($v as $t => $_) {
                $df[$t] = ($df[$t] ?? 0) + 1;
                $rest = $v;
                unset($rest[$t]);
                $norm = sqrt(array_sum(array_map(static fn ($w) => $w * $w, $rest)));
                if ($norm <= 0) {
                    continue; // only this tag: counts in n, adds no direction
                }
                $withOthers[$t] = ($withOthers[$t] ?? 0) + 1;
                foreach ($rest as $o => $w) {
                    $sum[$t][$o] = ($sum[$t][$o] ?? 0.0) + $w / $norm;
                }
            }
        }
        $cohesion = [];
        foreach ($df as $t => $k) {
            if ($k >= 2) {
                $sq = array_sum(array_map(static fn ($x) => $x * $x, $sum[$t] ?? []));
                $cohesion[$t] = max(0.0, ($sq - ($withOthers[$t] ?? 0)) / ($k * ($k - 1)));
            }
        }
        $weight = array_intersect_key($df, $cohesion);
        $avg = $weight ? array_sum(array_map(static fn ($t) => $cohesion[$t] * $df[$t], array_keys($weight))) / array_sum($weight) : 0.2;
        $prior = (float) $c['cohesion_prior'];
        $shrunk = [];
        foreach ($df as $t => $k) {
            $shrunk[$t] = ($k * ($cohesion[$t] ?? $avg) + $prior * $avg) / ($k + $prior);
        }
        $ref = array_values(array_filter($shrunk, static fn ($t) => $df[$t] >= (int) $c['ref_min_releases'], ARRAY_FILTER_USE_KEY));
        sort($ref);
        $refValue = $ref ? max(1e-6, $ref[(int) floor((float) $c['ref_percentile'] * (count($ref) - 1))]) : 1.0;

        $info = [];
        foreach ($df as $t => $k) {
            $frequency = min(1.0, log($n / $k) / log((float) $c['frequency_ref']));
            $coh = min(1.0, max(0.1, $shrunk[$t] / $refValue));
            $info[$t] = round(min(self::cap($t, $config), sqrt(max(0.0, $frequency) * $coh)), 3);
        }
        // A tag no release carries (only in your profile): rare, average cohesion.
        $default = round(sqrt(min(1.0, $avg / $refValue)), 3);
        $occ = [];
        foreach ($vectors as $v) {
            foreach ($v as $t => $_) {
                if ($info[$t] >= (float) $config['model']['specific_min']) {
                    $occ[] = $info[$t];
                }
            }
        }
        return ['info' => $info, 'default' => $default, 'full' => $occ ? round(2 * array_sum($occ) / count($occ), 3) : 2.0];
    }

    /** Structure cap from the existing broad-tag list (no new list). */
    public static function cap(string $tag, array $config): float
    {
        $generic = $config['generic_tags'];
        if (in_array($tag, $generic, true)) {
            return (float) $config['generic_weight'];
        }
        $rest = ' ' . $tag . ' ';
        usort($generic, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($generic as $g) {
            $rest = (string) preg_replace('/(?<=[\s\-\/])' . preg_quote($g, '/') . '(?=[\s\-\/])/', ' ', $rest);
        }
        return trim($rest, " -/") === '' ? (float) $config['tag_information']['broad_compound_cap'] : 1.0;
    }
}
