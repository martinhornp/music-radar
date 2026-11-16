<?php

declare(strict_types=1);

namespace MusicRadar\MusicBrainz;

use RuntimeException;

final class MusicBrainzException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
