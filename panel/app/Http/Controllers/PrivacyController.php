<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CloudflareDns;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * Show the privacy policy. It is public, and it only mentions what this installation has switched on.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Privacy', [
            // Who runs this installation, as filled in under Settings; null when nobody did.
            'operator' => Setting::value('privacy_contact'),
            'sessionMinutes' => (int) config('session.lifetime'),
            'sendsMail' => config('mail.default') !== 'log',
            'addsDnsRecords' => CloudflareDns::enabled(),
        ]);
    }
}
