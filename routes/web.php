<?php

use App\Http\Controllers\Api\JwksController;
use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {return view('admin');});

Route::get('/.well-known/jwks.json', JwksController::class)->middleware('jwks.ip');
Route::get('/play', PlayerController::class)->middleware('player.access');
