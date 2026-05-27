<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('player', [
            'whepBaseUrl' => (string) config('services.mediamtx.stream_base_url', ''),
            'tokenEndpoint' => url('/api/read'),
        ]);
    }
}
