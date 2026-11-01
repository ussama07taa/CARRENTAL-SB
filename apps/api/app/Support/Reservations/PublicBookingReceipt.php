<?php

namespace App\Support\Reservations;

use App\Mail\WebsiteRequestReceived;
use App\Models\Reservation;
use Illuminate\Support\Facades\Mail;

class PublicBookingReceipt
{
    public function reference(Reservation $reservation): string
    {
        return 'DEM-'.str_pad((string) $reservation->id, 6, '0', STR_PAD_LEFT);
    }

    public function token(Reservation $reservation): string
    {
        return hash_hmac('sha256', 'public-booking|'.$reservation->company_id.'|'.$reservation->id.'|'.$reservation->request_key, config('app.key'));
    }

    public function response(Reservation $reservation, bool $created): array
    {
        $reference = $this->reference($reservation);
        $token = $this->token($reservation);
        $demo = ! app()->environment('production');
        $status = $demo ? 'preview' : 'unverified';
        $url = rtrim((string) config('public_site.url'), '/');
        $email = $reservation->website_request['contact']['email'] ?? null;
        if ($created && $email && $url) {
            $mailer = $demo ? 'log' : config('mail.default');
            $sender = (string) config('mail.from.address');
            if (! $demo && (in_array($mailer, ['log', 'array']) || ! $sender || str_ends_with($sender, '@example.com'))) {
                $status = 'not_configured';
            } else {
                try {
                    Mail::mailer($mailer)->to($email)->send(new WebsiteRequestReceived($reference, $reservation->language, $url.'/'.$reservation->language.'/suivi#'.http_build_query(['reference' => $reference, 'token' => $token])));
                    $status = $demo ? 'preview' : 'sent';
                } catch (\Throwable) {
                    // A failed email never rolls back the committed request.
                    $status = 'failed';
                }
            }
        } elseif (! $url || ! $email) {
            $status = 'not_configured';
        }

        return ['reference' => $reference, 'tracking_token' => $token, 'mail_status' => $status];
    }
}
