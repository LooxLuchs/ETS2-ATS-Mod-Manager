<?php

use Ets2AtsModManager\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::get('/presence', [ServerController::class, 'presence']);
Route::get('/quick', [ServerController::class, 'quick']);
Route::get('/', [ServerController::class, 'index']);
Route::post('/presets', [ServerController::class, 'store']);
Route::post('/import', [ServerController::class, 'import']);
Route::post('/import-current', [ServerController::class, 'importCurrent']);
Route::put('/active/order', [ServerController::class, 'editActive']);
Route::post('/active/packages', [ServerController::class, 'applyActive']);
Route::get('/presets/{preset}', [ServerController::class, 'show'])->whereUuid('preset');
Route::get('/presets/{preset}/download', [ServerController::class, 'download'])->whereUuid('preset');
Route::post('/presets/{preset}/activate', [ServerController::class, 'activate'])->whereUuid('preset');
Route::put('/presets/{preset}/order', [ServerController::class, 'edit'])->whereUuid('preset');
Route::put('/presets/{preset}/name', [ServerController::class, 'rename'])->whereUuid('preset');
Route::delete('/presets/{preset}', [ServerController::class, 'destroy'])->whereUuid('preset');
Route::post('/presets/{preset}/restore/{operation}', [ServerController::class, 'restore'])->whereUuid('preset')->whereUuid('operation');
Route::post('/operations/{operation}/recover', [ServerController::class, 'recover'])->whereUuid('operation');
