<?php

namespace App\Http\Controllers;

use App\Models\SiteLead;
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

    public function demo(Request $request)
    {
        if (filled($request->input('website'))) {   // honeypot: people never see this field
            return redirect()->to(route('site.home') . '#demo')->with('demo_sent', true);
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
        return redirect()->to(route('site.home') . '#demo')->with('demo_sent', true);
    }
}
