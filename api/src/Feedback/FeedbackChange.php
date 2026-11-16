<?php

declare(strict_types=1);

namespace MusicRadar\Feedback;

use InvalidArgumentException;

/**
 * One validated feedback action: exactly one field per request.
 *   {"rating": 1-5}        rate          {"rating": null}        remove rating
 *   {"notInterested": true|false}       {"saved": true|false}
 * Error messages are safe to show to the client.
 */
final class FeedbackChange
{
    public const RELEASE_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        public readonly string $field,
        public readonly int|bool|null $value,
    ) {
    }

    public static function validReleaseId(string $id): bool
    {
        return (bool) preg_match(self::RELEASE_ID, $id);
    }

    public static function fromInput(mixed $data): self
    {
        if (!is_array($data) || array_is_list($data) || count($data) !== 1) {
            throw new InvalidArgumentException('Send exactly one of: rating, notInterested, saved');
        }
        $field = (string) array_key_first($data);
        $value = $data[$field];
        return match ($field) {
            'rating' => $value === null || (is_int($value) && $value >= 1 && $value <= 5)
                ? new self('rating', $value)
                : throw new InvalidArgumentException('rating must be a whole number from 1 to 5, or null'),
            'notInterested', 'saved' => is_bool($value)
                ? new self($field, $value)
                : throw new InvalidArgumentException("$field must be true or false"),
            default => throw new InvalidArgumentException('Send exactly one of: rating, notInterested, saved'),
        };
    }
}
