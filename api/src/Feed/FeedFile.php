<?php

declare(strict_types=1);

namespace MusicRadar\Feed;

/**
 * The recommendations feed (data/feed.json, written by bin/export-feed.php).
 * Read-only; also the trusted source of release snapshots for feedback, so a
 * client can never decide what gets stored about a release.
 */
final class FeedFile
{
    private ?array $feed = null;

    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @throws \JsonException if the file is corrupt */
    public function load(): ?array
    {
        if ($this->feed === null && $this->exists()) {
            $this->feed = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        }
        return $this->feed;
    }

    public function release(string $id): ?array
    {
        foreach ($this->load()['releases'] ?? [] as $r) {
            if (($r['id'] ?? null) === $id) {
                return $r;
            }
        }
        return null;
    }
}
