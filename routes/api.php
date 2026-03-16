<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ReadTokenController;

Route::post('/read', ReadTokenController::class)->middleware('throttle:token-read');
