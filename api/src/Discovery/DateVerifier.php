<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;

/**
 * Is a release's first release date documented to the day?
 *
 *   confirmed            the release group's first release date is a full day
 *   probable             only year/month is known for the group, but one of its
 *                        releases has a full date in the window - likely, not proven
 *   unconfirmed          only year/month known, no full date anywhere
 *
 * Anything but "confirmed" must not be presented as a certain new release;
 * it goes to "needs date check" instead.
 */
final class DateVerifier
{
    public function __construct(private readonly MusicBrainzClient $mb)
    {
    }

    /** @return array{status: string, date: string, note: ?string, release_dates: list<string>} */
    public function verify(array $release, string $from, string $to): array
    {
        $date = (string) ($release['first_release_date'] ?? '');
        if (strlen($date) === 10) {
            return ['status' => 'confirmed', 'date' => $date, 'note' => null, 'release_dates' => [$date]];
        }

        $dates = [];
        try {
            $rg = $this->mb->lookupReleaseGroup((string) $release['id']);
            $dates = array_values(array_filter(array_map(static fn ($r) => (string) ($r['date'] ?? ''), $rg['releases'] ?? [])));
        } catch (MusicBrainzException) {
            // fall through: unconfirmed
        }
        sort($dates);
        $full = array_values(array_filter($dates, static fn ($d) => strlen($d) === 10));
        $inWindow = array_values(array_filter($full, static fn ($d) => $d >= $from && $d <= $to));
        $earlierFull = array_filter($full, static fn ($d) => $d < $from);

        if ($inWindow !== [] && $earlierFull === []) {
            return [
                'status' => 'probable',
                'date' => $inWindow[0],
                'note' => "MusicBrainz only knows \"$date\" for the release group; one release is dated {$inWindow[0]} - not proven to be the first",
                'release_dates' => $dates,
            ];
        }
        return [
            'status' => 'unconfirmed',
            'date' => $date,
            'note' => "only \"$date\" is known - could be older than this window",
            'release_dates' => $dates,
        ];
    }
}
