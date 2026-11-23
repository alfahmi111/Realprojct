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

// Property photo (private disk; RLS through the signed-in user decides who may see it — owner request 2026-11-23).
Route::get('/media/property/{id}/photo', function (int $id) {
    $p = \App\Models\Property::find($id);
    abort_unless($p && $p->photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->photo_path), 404);
    return \Illuminate\Support\Facades\Storage::disk('local')->response($p->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
})->middleware(['web', 'auth'])->whereNumber('id')->name('property.photo');

Route::get('/export',\App\Http\Controllers\DataExportController::class)->middleware(['web', 'auth'])->name('data.export');

// Technician interface API + offline sync (REQ-0313, REQ-0495). Same-origin session auth inside the PWA.
Route::middleware(['web', 'auth'])->prefix('api/tech')->group(function () {
    Route::get('work-orders', [\App\Http\Controllers\Api\TechnicianController::class, 'workOrders']);
    Route::get('assets/{qr}', [\App\Http\Controllers\Api\TechnicianController::class, 'scan']);
    Route::post('sync', [\App\Http\Controllers\Api\TechnicianController::class, 'sync']);
});

// Public lease-offer acceptance link (REQ-0184).
Route::get('/offer/{token}', [\App\Http\Controllers\OfferAcceptController::class, 'show'])->name('offer.accept');
Route::post('/offer/{token}', [\App\Http\Controllers\OfferAcceptController::class, 'accept'])->middleware('throttle:10,1')->name('offer.accept.post');

// LOCAL DEVELOPMENT ONLY — single-use login links from `php artisan dev:login-link` (never registered elsewhere).
if (app()->environment('local')) {
    Route::get('/dev-login/{token}', function (string $token) {
        $data = \Illuminate\Support\Facades\Cache::pull('dev-login:' . hash('sha256', $token)) ?? abort(404);
        app(\App\Tenancy\TenantContext::class)->setUserId($data['id']);
        $user = \App\Models\User::findOrFail($data['id']);
        \Illuminate\Support\Facades\Auth::login($user);
        session()->put('filament.multi_factor_authenticated', true);
        return redirect($data['path']);
    })->middleware('web');
}

// Vault document (REQ-0270): private disk, through RLS; party documents need documents.parties; every open is logged.
Route::get('/media/document/{id}', function (int $id) {
    $user = auth()->user();
    abort_unless($user && $user->subscriber_id, 403);
    $doc = \App\Models\Document::find($id);   // RLS: another office's document is simply not found
    abort_unless($doc, 404);
    $vault = app(\App\Documents\DocumentVault::class);
    abort_unless($vault->canSee($user, $doc) && ($user->hasPermission('properties.view') || $user->hasPermission('properties.*')), 403);
    $path = $vault->open($doc, $user, request()->boolean('download') ? 'download' : 'view');
    abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    return request()->boolean('download')
        ? \Illuminate\Support\Facades\Storage::disk('local')->download($path, $doc->title_ar . '.' . pathinfo($path, PATHINFO_EXTENSION))
        : \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
})->middleware(['web', 'auth'])->name('media.document');
