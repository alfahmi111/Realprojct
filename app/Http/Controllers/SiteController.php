<?php

namespace App\Http\Controllers;

use App\Models\SiteLead;
use App\Support\Dates\DateService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The public website introducing the platform (owner request 2026-11-24). Visitors see it at «/»; a signed-in user
 * goes straight to their role's interface, as before (REQ-0271). The demo form stores a request for the platform admin.
 */
class SiteController extends Controller
{
    public const UNITS = ['1-20' => 'حتى 20 وحدة', '21-300' => 'من 21 إلى 300 وحدة', '301+' => 'أكثر من 300 وحدة'];

    public function home()
    {
        if (auth()->check()) {
            return redirect(Filament::getPanel(auth()->user()->role->portal())->getUrl());
        }
        return view('site.home', ['loginUrl' => Filament::getPanel('manager')->getLoginUrl(), 'units' => self::UNITS]);
    }

    /**
     * The new public page, shown at /preview beside the current one until the owner approves it (2026-11-25). It does
     * not redirect a signed-in user, so the page can be reviewed from any session. Today's date is the platform's own.
     */
    public function preview(DateService $dates)
    {
        $now = CarbonImmutable::now();
        return view('site.v2', [
            'loginUrl' => Filament::getPanel('manager')->getLoginUrl(),
            'units' => self::UNITS,
            'hijri' => $dates->format($now, 'hijri', 'Asia/Riyadh'),
            'greg' => $dates->format($now, 'gregorian', 'Asia/Riyadh'),
        ]);
    }

    public function demo(Request $request)
    {
        // back to the page the request came from: the current page, or the new one under review
        $back = ($request->input('from') === 'preview' ? route('site.preview') : route('site.home')) . '#demo';
        if (filled($request->input('website'))) {   // honeypot: people never see this field
            return redirect()->to($back)->with('demo_sent', true);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'regex:/^(05\d{8}|\+9665\d{8}|9665\d{8})$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'city' => ['nullable', 'string', 'max:60'],
            'units' => ['nullable', 'in:' . implode(',', array_keys(self::UNITS))],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'اكتب اسمك.',
            'phone.required' => 'اكتب رقم جوالك لنتواصل معك.',
            'phone.regex' => 'اكتب رقم جوال سعودياً يبدأ بـ 05 ويتكون من 10 أرقام.',
            'email.email' => 'البريد الإلكتروني غير صحيح.',
            'consent.accepted' => 'وافق على استخدام بياناتك للتواصل معك بشأن العرض.',
        ]);
        unset($data['consent']);
        SiteLead::create($data + ['consented_at' => now()]);
        return redirect()->to($back)->with('demo_sent', true);
    }
}
