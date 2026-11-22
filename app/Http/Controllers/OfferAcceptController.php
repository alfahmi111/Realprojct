<?php

namespace App\Http\Controllers;

use App\Leasing\LeasingException;
use App\Leasing\LeasingService;
use App\Models\LeaseOffer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Public acceptance link sent to the tenant (REQ-0184). The token resolves the offer's subscriber. */
class OfferAcceptController
{
    public function show(string $token)
    {
        $offer = $this->resolve($token) ?? abort(404);
        return response()->view('public.offer', ['offer' => $offer, 'token' => $token]);
    }

    public function accept(string $token, LeasingService $leasing)
    {
        $this->resolve($token) ?? abort(404);
        try {
            $leasing->acceptOffer($token);
        } catch (LeasingException $e) {
            return back()->withErrors(['offer' => $e->getMessage()]);
        }
        return response()->view('public.offer-accepted');
    }

    private function resolve(string $token): ?LeaseOffer
    {
        $hash = hash('sha256', $token);
        $sid = DB::table('lease_offer_tokens')->where('token_hash', $hash)->value('subscriber_id');
        if (! $sid) {
            return null;
        }
        app(TenantContext::class)->set((int) $sid);
        return LeaseOffer::where('accept_token', $hash)->where('status', 'sent')->with('unit')->first();
    }
}
