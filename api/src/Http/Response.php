<?php

declare(strict_types=1);

namespace MusicRadar\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly mixed $data = null,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function error(string $message, int $status, array $extra = []): self
    {
        return new self(['error' => $message] + $extra, $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        if ($this->status !== 204) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
