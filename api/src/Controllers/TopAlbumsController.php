<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;
use MusicRadar\TopAlbums\TopAlbums;
use PDO;
use Throwable;

/**
 * GET /api/top-albums - your most played albums (the one-time import), most
 * played first, for the "Bedøm albums" page. Which ones you rated comes from
 * your feedback (GET /api/feedback), so a rating is never stored twice.
 */
final class TopAlbumsController
{
    /** @param Closure(): PDO $db */
    public function __construct(private readonly Closure $db)
    {
    }

    public function index(Request $request): Response
    {
        try {
            $albums = (new TopAlbums(($this->db)()))->all();
            return new Response(['albums' => $albums, 'total' => count($albums)], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $e) {
            error_log('top-albums: ' . $e::class . ': ' . $e->getMessage());
            return Response::error('The album list is unavailable right now', 503);
        }
    }
}
