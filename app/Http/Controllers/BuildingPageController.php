<?php

namespace App\Http\Controllers;

use App\BuildingQr\BuildingQrException;
use App\BuildingQr\BuildingQrService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Public building page reached by the QR on the facade (REQ-0377, REQ-0393). No login, under the subscriber's name. */
class BuildingPageController
{
    public function show(string $code, Request $request, BuildingQrService $svc)
    {
        $qr = $svc->resolve($code) ?? abort(404);
        $svc->recordScan($qr, $request->query('src'), $request->headers->get('referer'), CarbonImmutable::now());
        return response()->view('public.building', ['page' => $svc->page($qr, CarbonImmutable::today()), 'code' => $code]);
    }

    public function register(string $code, Request $request, BuildingQrService $svc)
    {
        $qr = $svc->resolve($code) ?? abort(404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:30'], 'email' => ['nullable', 'email'],
            'unit_type' => ['nullable', 'string', 'max:30'], 'budget' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'desired_month' => ['nullable', 'date_format:Y-m'],
            'consent' => ['accepted'],
        ]);
        try {
            $svc->register($qr, $data + ['budget_minor' => isset($data['budget']) ? Money::parse($data['budget'])->minor() : null], true, CarbonImmutable::now());
        } catch (BuildingQrException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
        return redirect()->route('building.page', $code)->with('registered', true);
    }
}
