<?php

use Illuminate\Support\Facades\Route;

// No public web UI in the MVP.
Route::get('/', fn () => response('OK', 200, ['Content-Type' => 'text/plain']));
