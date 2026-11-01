<?php

namespace App\Http\Controllers;

use App\Support\Reservations\ContractVerification;

class ContractVerificationController extends Controller
{
    public function __invoke(string $token, ContractVerification $verification)
    {
        $data = $verification->lookup($token);

        return response()->view('contracts.verification', ['data' => $data], $data ? 200 : 404)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }
}
