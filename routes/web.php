<?php

use App\Http\Controllers\Api\JwksController;
use App\Http\Controllers\MonitorPlayerController;
use App\Http\Controllers\MoqPlayerController;
use App\Http\Controllers\PlayerController;
use App\Livewire\MultistreamMonitor;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {return view('admin');});

Route::get('/.well-known/jwks.json', JwksController::class)->middleware('jwks.ip');
Route::get('/play', PlayerController::class)->middleware('player.access');
Route::get('/moq', MoqPlayerController::class)->middleware('player.access');
Route::get('/monitor/play', MonitorPlayerController::class)
    ->middleware('auth')
    ->name('monitor.play');
Route::get('/monitor', MultistreamMonitor::class)
    ->middleware('auth')
    ->name('monitor');

// TEMPORARY: public iframe test for validating an iframe access guard on another
// project. No middleware/auth by design. Remove when testing is finished.
Route::get('/teststream', function () {
    return view('iframe', [
        'src' => 'https://cg-iframe.dotara.dev/color-game/3/webrtc',
    ]);
})->name('iframe.test');
