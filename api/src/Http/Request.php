<?php

declare(strict_types=1);

namespace MusicRadar\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $params Route parameters, filled in by the router.
     * @param array<string, string> $headers Lower-case header names (only the few the API uses).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly string $body = '',
        public array $params = [],
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public static function fromGlobals(string $basePath): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            (string) file_get_contents('php://input'),
            [],
            [
                'content-type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
                'origin' => (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
                'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
                'x-import-token' => (string) ($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? ''),
                'cookie' => (string) ($_SERVER['HTTP_COOKIE'] ?? ''),
                // The address the web server sees - not a header a client can set.
                'remote-addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                // Only used behind a trusted proxy (see ClientAddress).
                'x-forwarded-for' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            ],
        );
    }

    /** Decoded JSON body, or an empty array if the body is empty/invalid. */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
