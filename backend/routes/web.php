<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aplikasi ini API-only: frontend (Vue) disajikan oleh Nginx dari folder
| frontend/dist, bukan dari Laravel. Route di bawah hanya untuk info dasar.
|
*/

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Security Patrol Monitoring System API',
        'data' => [
            'name' => 'Security Patrol API',
            'version' => '1.0.0',
            'docs' => '/api/v1',
            'health' => '/up',
        ],
    ]);
});
