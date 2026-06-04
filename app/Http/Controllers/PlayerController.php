<?php

namespace App\Http\Controllers;

use App\Models\PlayerSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function __invoke(Request $request): View
    {
        $isEmbed = $request->headers->get('sec-fetch-dest') === 'iframe';

        $directAllowed = (bool) cache()->remember(
            'player_setting:allow_direct_playback',
            30,
            fn () => PlayerSetting::get('allow_direct_playback', false)
        );

        // When embedded, the embedding site's origin is the real "source" of the view.
        // The browser sends it on the iframe's /play load (Origin, else Referer); the
        // later /api/read call is same-origin and can't see it, so we hand it to the
        // player to echo back. Direct playback leaves this empty (ReadTokenController
        // then falls back to the player URL).
        $embedSource = '';
        if ($isEmbed) {
            $origin = (string) $request->headers->get('origin', '');
            $referer = (string) $request->headers->get('referer', '');
            $embedSource = $this->originOf(($origin !== '' && $origin !== 'null') ? $origin : $referer);
        }

        return view('player', [
            'whepBaseUrl' => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
            'playbackEnabled' => $isEmbed || $directAllowed,
            'embedSource' => $embedSource,
        ]);
    }

    /**
     * Reduce a URL to its origin (scheme://host[:port]), lowercased; '' if unparseable.
     */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
