<?php

use App\Http\Controllers\Api\JwksController;
use App\Http\Controllers\MonitorPlayerController;
use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {return view('admin');});

Route::get('/.well-known/jwks.json', JwksController::class)->middleware('jwks.ip');
Route::get('/play', PlayerController::class)->middleware('player.access');
Route::get('/monitor/play', MonitorPlayerController::class)
    ->middleware('auth')
    ->name('monitor.play');
