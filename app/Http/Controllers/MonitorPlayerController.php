<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MonitorPlayerController extends Controller
{
    /**
     * Internal single-stream player used by the Multistream Monitor tiles and
     * its "Open" action. Guarded only by auth (route middleware) — it does NOT
     * carry the public player's IP gate, embed/referrer gate, or direct-playback
     * toggle. Reuses the public player view read-only in direct mode: with an
     * empty embed source, the player's same-origin /api/read call passes the
     * referrer gate (referer origin == app origin).
     */
    public function __invoke(Request $request): Response
    {
        $response = response()->view('player', [
            'whepBaseUrl' => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
            'playbackEnabled' => true,
            'embedSource' => '',
        ]);

        // Only the same-origin monitor page may frame this internal player.
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }
}
