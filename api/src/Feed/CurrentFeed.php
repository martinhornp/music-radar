<?php

declare(strict_types=1);

namespace MusicRadar\Feed;

use Closure;
use Throwable;

/**
 * The recommendations the site shows: the active run in the database (the
 * authoritative source), falling back to data/feed.json only while the
 * database has no active run yet (before the first import/migration) or
 * cannot be reached.
 */
final class CurrentFeed
{
    private ?RecommendationStore $store = null;
    private bool $storeTried = false;

    /**
     * @param Closure(): RecommendationStore $storeFactory connects lazily; may throw
     * @param (Closure(string): ?array)|null $stored  any release the radar has stored
     *        (search results), as a feed entry - used when it is not in the feed
     */
    public function __construct(
        private readonly Closure $storeFactory,
        private readonly FeedFile $file,
        private readonly ?Closure $stored = null,
    ) {
    }

    /** @return array{feed: ?array, source: string} */
    public function load(): array
    {
        $store = $this->store();
        if ($store !== null) {
            try {
                $feed = $store->activeFeed();
                if ($feed !== null) {
                    return ['feed' => $feed, 'source' => 'database'];
                }
            } catch (Throwable $e) {
                error_log('recommendations: ' . $e::class . ': ' . $e->getMessage());
            }
        }
        return ['feed' => $this->file->exists() ? $this->file->load() : null, 'source' => 'file'];
    }

    /**
     * A release as the site knows it (trusted snapshot for feedback): from the
     * current feed, or else from everything the radar has stored - or null.
     */
    public function release(string $id): ?array
    {
        $inFeed = $this->releaseInFeed($id);
        if ($inFeed !== null || $this->stored === null) {
            return $inFeed;
        }
        try {
            return ($this->stored)($id);
        } catch (Throwable $e) {
            error_log('recommendations: ' . $e::class . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * What the current recommendations say about these releases now: the
     * active run's entry if it picked them, or else its score (they were
     * assessed but not picked), or else nothing (not assessed: outside the
     * window or too little data; or no database run, only feed.json).
     *
     * @param list<string> $ids
     * @return array<string, array{score: float, scoreParts: array, pick?: array, reasons?: list<array>, sources?: list<string>}>
     */
    public function current(array $ids): array
    {
        $store = $this->store();
        if ($store === null || $ids === []) {
            return [];
        }
        try {
            $out = [];
            foreach ($store->activeScores($ids) as $id => $s) {
                $out[$id] = $s;
            }
            foreach ($store->activeItems($ids) as $id => $item) {
                $out[$id] = array_intersect_key($item, array_flip(['score', 'scoreParts', 'pick', 'reasons', 'sources']));
            }
            return $out;
        } catch (Throwable $e) {
            error_log('recommendations: ' . $e::class . ': ' . $e->getMessage());
            return [];
        }
    }

    private function releaseInFeed(string $id): ?array
    {
        $store = $this->store();
        if ($store !== null) {
            try {
                if ($store->activeRun() !== null) {
                    return $store->activeItem($id);
                }
            } catch (Throwable $e) {
                error_log('recommendations: ' . $e::class . ': ' . $e->getMessage());
            }
        }
        return $this->file->exists() ? $this->file->release($id) : null;
    }

    private function store(): ?RecommendationStore
    {
        if (!$this->storeTried) {
            $this->storeTried = true;
            try {
                $this->store = ($this->storeFactory)();
            } catch (Throwable $e) {
                error_log('recommendations: ' . $e::class . ': ' . $e->getMessage());
            }
        }
        return $this->store;
    }
}
