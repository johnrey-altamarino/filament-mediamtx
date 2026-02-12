<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\JwksController;

// Route::get('/', function () {return view('admin');});

Route::get('/.well-known/jwks.json', JwksController::class);
