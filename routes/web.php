<?php

use App\Http\Controllers\ManifestController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// One app: every user lands on their role's interface (REQ-0271).
// Visitors see the public website; a signed-in user lands on their role's interface (REQ-0271).
Route::get('/', [\App\Http\Controllers\SiteController::class, 'home'])->name('site.home');
Route::get('/preview', [\App\Http\Controllers\SiteController::class, 'preview'])->name('site.preview');   // the new public page, for review beside «/»
Route::post('/demo', [\App\Http\Controllers\SiteController::class, 'demo'])->middleware('throttle:5,1')->name('site.demo');
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

// Building QR public page — permanent short link (REQ-0393). Registration is free (decision 3) and rate-limited.
Route::get('/b/{code}', [\App\Http\Controllers\BuildingPageController::class, 'show'])->where('code', '[2-9A-HJ-NP-Z]{6}')->name('building.page');
Route::post('/b/{code}', [\App\Http\Controllers\BuildingPageController::class, 'register'])->where('code', '[2-9A-HJ-NP-Z]{6}')->middleware('throttle:10,1')->name('building.register');

// Property photo (private disk; RLS through the signed-in user decides who may see it — owner request 2026-11-23).
Route::get('/media/property/{id}/photo', function (int $id) {
    $p = \App\Models\Property::find($id);
    abort_unless($p && $p->photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->photo_path), 404);
    // office staff limited to some properties see only their photos (review M18 A8); owners and tenants pass through RLS
    abort_if(auth()->user()->role->portal() === 'manager' && ! \App\Authorization\Scope::canSeeProperty(auth()->user(), $p->id), 403);
    return \Illuminate\Support\Facades\Storage::disk('local')->response($p->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
})->middleware(['web', 'auth'])->whereNumber('id')->name('property.photo');

Route::get('/export',\App\Http\Controllers\DataExportController::class)->middleware(['web', 'auth', \App\Http\Middleware\RequireTwoFactorOnRoute::class])->name('data.export');

// Technician interface API + offline sync (REQ-0313, REQ-0495). Same-origin session auth inside the PWA.
Route::middleware(['web', 'auth', \App\Http\Middleware\RequireTwoFactorOnRoute::class])->prefix('api/tech')->group(function () {
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
    abort_unless(\App\Filament\Support\PropertyScope::apply(\App\Models\Document::query(), 'document')->whereKey($id)->exists(), 403);   // property scope (REQ-0494)
    $vault = app(\App\Documents\DocumentVault::class);
    abort_unless($vault->canSee($user, $doc) && ($user->hasPermission('properties.view') || $user->hasPermission('properties.*')), 403);
    $path = $vault->open($doc, $user, request()->boolean('download') ? 'download' : 'view');
    abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    return request()->boolean('download')
        ? \Illuminate\Support\Facades\Storage::disk('local')->download($path, $doc->title_ar . '.' . pathinfo($path, PATHINFO_EXTENSION))
        : \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
})->middleware(['web', 'auth', \App\Http\Middleware\RequireTwoFactorOnRoute::class])->name('media.document');

// Circular attachment (owner request 2026-11-24): a tenant sees only circulars visible to his building and unit; staff
// with leasing permission see all of their office's. RLS hides other offices'.
Route::get('/media/announcement/{id}/{n}', function (int $id, int $n) {
    $user = auth()->user();
    abort_unless($user && $user->subscriber_id, 403);
    $a = \App\Models\Announcement::find($id);
    abort_unless($a, 404);
    if ($user->role === \App\Authorization\Role::Tenant) {
        $visible = collect(app(\App\Portals\TenantPortal::class)->announcements($user))->pluck('id')->contains($id);
        abort_unless($visible, 403);
    } else {
        abort_unless($user->hasPermission('leasing.view'), 403);
    }
    $path = ((array) $a->attachments)[$n] ?? null;
    abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
})->middleware(['web', 'auth', \App\Http\Middleware\RequireTwoFactorOnRoute::class])->whereNumber(['id', 'n'])->name('media.announcement');

// Quick-migration Excel template (owner request 2026-11-24)
Route::get('/manage/quick-migration/template.xlsx', function () {
    abort_unless(auth()->user()?->hasPermission('properties.import'), 403);
    $path = app(\App\Migration\ExcelTemplate::class)->build(tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx');
    return response()->download($path, 'قالب-استيراد-العقود.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
})->middleware(['web', 'auth'])->name('migration.template');

// Office logo (owner request 2026-11-24). Public by nature: it is shown under the office's own name, also on the public
// building page. Only paths inside the office's own branding folder are served.
Route::get('/media/office/{sid}/logo/{mode}', function (int $sid, string $mode) {
    $key = $mode === 'dark' ? 'theme.logo_dark' : 'theme.logo_light';
    $path = app(\App\Tenancy\TenantContext::class)->run($sid, fn () => app(\App\Settings\Settings::class)->get($key));
    abort_unless(is_string($path) && str_starts_with($path, "branding/$sid/") && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=3600', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
})->whereNumber('sid')->whereIn('mode', ['light', 'dark'])->name('office.logo');

// Work order photos (review M18: uploaded but shown nowhere). Staff with maintenance permission, the assigned
// technician, or the tenant of the lease that reported it; RLS hides other offices' orders.
Route::get('/media/work-order/{id}/{kind}/{n}', function (int $id, string $kind, int $n) {
    $user = auth()->user();
    abort_unless($user && $user->subscriber_id, 403);
    $wo = \App\Models\WorkOrder::find($id);
    abort_unless($wo, 404);
    $tenantOwns = $user->role === \App\Authorization\Role::Tenant && $wo->lease_id
        && \App\Models\Lease::where('id', $wo->lease_id)->whereHas('tenant', fn ($q) => $q->where('user_id', $user->id))->exists();
    abort_unless($user->hasPermission('maintenance.view') || $wo->assignee_user_id === $user->id || $tenantOwns, 403);
    abort_if($user->role->portal() === 'manager' && $wo->assignee_user_id !== $user->id && ! \App\Authorization\Scope::canSeeProperty($user, (int) $wo->property_id), 403);   // REQ-0494
    $path = ((array) $wo->{'photos_' . $kind})[$n] ?? null;
    abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
})->middleware(['web', 'auth', \App\Http\Middleware\RequireTwoFactorOnRoute::class])->whereNumber(['id', 'n'])->whereIn('kind', ['report', 'before', 'after'])->name('media.work_order');

// Header heartbeat: task and unread counts, for the notification sound (owner request 2026-11-24).
Route::get('/pulse', \App\Http\Controllers\PulseController::class)->middleware(['web', 'auth', 'throttle:30,1'])->name('pulse');
