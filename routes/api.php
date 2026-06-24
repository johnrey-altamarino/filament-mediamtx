<?php

use App\Http\Controllers\Api\MediamtxEventController;
use App\Http\Controllers\Api\ReadTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/read', ReadTokenController::class)->middleware('throttle:token-read');

Route::post('/mediamtx/event', MediamtxEventController::class)
    ->middleware('mediamtx.webhook');
