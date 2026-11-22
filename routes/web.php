<?php

use App\Http\Controllers\ManifestController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// One app: every user lands on their role's interface (REQ-0271).
Route::get('/', fn () => redirect(auth()->check()
    ? Filament::getPanel(auth()->user()->role->portal())->getUrl()
    : Filament::getPanel('manager')->getLoginUrl()));
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

// Building QR public page — permanent short link (REQ-0393). Registration is free (decision 3) and rate-limited.
Route::get('/b/{code}', [\App\Http\Controllers\BuildingPageController::class, 'show'])->where('code', '[2-9A-HJ-NP-Z]{6}')->name('building.page');
Route::post('/b/{code}', [\App\Http\Controllers\BuildingPageController::class, 'register'])->where('code', '[2-9A-HJ-NP-Z]{6}')->middleware('throttle:10,1')->name('building.register');
