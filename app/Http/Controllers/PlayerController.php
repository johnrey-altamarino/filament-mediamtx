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

        return view('player', [
            'whepBaseUrl'     => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint'   => url('/api/read'),
            'playbackEnabled' => $isEmbed || $directAllowed,
        ]);
    }
}
