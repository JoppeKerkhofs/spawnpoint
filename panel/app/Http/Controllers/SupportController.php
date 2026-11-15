<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SupportController extends Controller
{
    /**
     * Show the support page. It is public, so people who cannot sign in can read it too.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Support', [
            // The same contact as on the privacy policy; null when nobody filled it in.
            'operator' => Setting::value('privacy_contact'),
            'baseDomain' => Setting::value('base_domain'),
            // A reset link only arrives when mail is actually sent.
            'canResetPassword' => Features::enabled(Features::resetPasswords()) && config('mail.default') !== 'log',
        ]);
    }
}
