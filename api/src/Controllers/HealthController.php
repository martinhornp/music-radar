<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use MusicRadar\Http\Request;
use MusicRadar\Http\Response;

final class HealthController
{
    // Public endpoint: deliberately reveals nothing beyond "it works" -
    // no PHP/app versions, server time or other technical details.
    public function show(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
