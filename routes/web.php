<?php

use App\Http\Controllers\ManifestController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// One app: every user lands on their role's interface (REQ-0271).
Route::get('/', fn () => redirect(auth()->check()
    ? Filament::getPanel(auth()->user()->role->portal())->getUrl()
    : Filament::getPanel('manager')->getLoginUrl()));
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');
