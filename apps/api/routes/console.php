<?php

use App\Support\Reservations\TemporaryHold;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reservations:expire-holds', function () {
    if (! app(TemporaryHold::class)->ready()) {
        $this->info('Réservations temporaires non activées.');

        return;
    }
    $count = 0;
    foreach (DB::table('reservations')->where('status', 'temporaire')->where('hold_expires_at', '<=', now())->distinct()->pluck('company_id') as $companyId) {
        $count += Tenant::run((int) $companyId, fn () => app(TemporaryHold::class)->expire());
    }
    $this->info($count.' réservation(s) temporaire(s) expirée(s).');
})->purpose('Libérer les réservations temporaires expirées en conservant leur historique');

Schedule::command('reservations:expire-holds')->everyMinute()->withoutOverlapping();
