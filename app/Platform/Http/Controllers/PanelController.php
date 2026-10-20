<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class PanelController extends Controller
{
    public function __invoke()
    {
        $nonce = Str::random(40);

        // Public shell only. All operator and customer data comes from Bearer APIs.
        return response()->view('platform.panel', ['nonce' => $nonce])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    }
}
