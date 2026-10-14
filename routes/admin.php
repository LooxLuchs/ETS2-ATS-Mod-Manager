<?php

use Ets2AtsModManager\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AdminController::class, 'index']);
Route::post('/activations', [AdminController::class, 'store']);
Route::put('/activations/{activation}', [AdminController::class, 'update'])->whereNumber('activation');
Route::delete('/activations/{activation}', [AdminController::class, 'destroy'])->whereNumber('activation');
