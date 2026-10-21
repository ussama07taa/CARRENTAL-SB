<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VehiclePricing
{
    public function forReservation(Reservation $record): array
    {
        return $this->simulate($record->vehicle_id, [
            'starts_local' => $record->starts_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
            'ends_local' => $record->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
            'timezone' => RequestPeriod::TIMEZONE,
            'duration_minutes' => (int) $record->starts_at->diffInMinutes($record->ends_at),
        ]);
    }

    public function ready(): bool
    {
        return Schema::hasTable('vehicle_pricing_versions');
    }

    public function current(): ?object
    {
        return $this->ready() ? DB::table('vehicle_pricing_versions')->where('company_id', Tenant::id())->latest('id')->first() : null;
    }

    public function normal(int $vehicle): array
    {
        Vehicle::findOrFail($vehicle);
        $version = $this->current();
        $configuration = $version ? json_decode($version->configuration, true) : ['vehicles' => [], 'seasons' => []];
        $rate = collect($configuration['vehicles'])->firstWhere('vehicle_id', $vehicle);

        return ['ready' => $this->ready(), 'version_id' => $version?->id, 'daily_centimes' => $rate['daily_centimes'] ?? null, 'minimum_days' => $rate['minimum_days'] ?? 0];
    }

    public function setNormal(int $vehicle, array $input, int $actor): array
    {
        abort_unless(CompanyMembership::findOrFail($actor)->is_primary_responsible, 403, 'Seul le responsable peut modifier les tarifs.');
        abort_unless($this->ready(), 503, 'Les tarifs nécessitent l’activation de la mise à jour de la base.');

        return DB::transaction(function () use ($vehicle, $input, $actor): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            Vehicle::findOrFail($vehicle);
            $previous = empty($input['expected_version']) ? null : DB::table('vehicle_pricing_versions')->where('company_id', Tenant::id())->where('id', $input['expected_version'])->first();
            abort_if(! empty($input['expected_version']) && ! $previous, 409, 'La grille de référence est introuvable. Rechargez le tarif.');
            $configuration = $previous ? json_decode($previous->configuration, true) : ['vehicles' => [], 'seasons' => []];
            $configuration['vehicles'] = collect($configuration['vehicles'])->reject(fn ($rate) => $rate['vehicle_id'] === $vehicle)->push(['vehicle_id' => $vehicle, 'daily_centimes' => (int) $input['daily_centimes'], 'minimum_days' => (int) $input['minimum_days']])->values()->all();

            return $this->save(['configuration' => $configuration, 'expected_version' => $input['expected_version'] ?? null, 'request_key' => $input['request_key'], 'reason' => 'Tarif normal · VH-'.$vehicle.' · '.$input['reason']], $actor);
        });
    }

    public function save(array $input, int $actor): array
    {
        abort_unless($this->ready(), 503, 'L’activation des tarifs nécessite la mise à jour de la base.');

        return DB::transaction(function () use ($input, $actor): array {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($input));
            $old = DB::table('vehicle_pricing_versions')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec d’autres données.');

                return ['id' => $old->id, 'replayed' => true];
            }
            $latest = $this->current();
            abort_unless((int) ($latest?->id ?? 0) === (int) ($input['expected_version'] ?? 0), 409, 'Les tarifs ont changé. Rechargez avant de modifier.');
            $config = $input['configuration'];
            $ids = Vehicle::pluck('id')->all();
            $normal = [];
            foreach ($config['vehicles'] as $i => $r) {
                if (! in_array($r['vehicle_id'], $ids, true)) {
                    throw ValidationException::withMessages(["configuration.vehicles.$i.vehicle_id" => 'Véhicule introuvable dans votre agence.']);
                }$normal[$r['vehicle_id']] = $r;
            }
            $used = [];
            foreach ($config['seasons'] as $i => $season) {
                if ($season['active'] && count($season['rates']) === 0) {
                    throw ValidationException::withMessages(["configuration.seasons.$i" => 'Choisissez au moins une voiture avant d’activer cette période.']);
                }
                foreach ($season['rates'] as $j => $r) {
                    if (! isset($normal[$r['vehicle_id']])) {
                        throw ValidationException::withMessages(["configuration.seasons.$i.rates.$j" => 'Définissez d’abord le tarif normal de cette voiture.']);
                    }
                    if ($season['active']) {
                        foreach ($used[$r['vehicle_id']] ?? [] as $other) {
                            if ($season['starts_on'] <= $other['ends_on'] && $season['ends_on'] >= $other['starts_on']) {
                                throw ValidationException::withMessages(["configuration.seasons.$i" => "Conflit : « {$season['name']} » et « {$other['name']} » pour la même voiture. Corrigez les dates ou désactivez une période."]);
                            }
                        }
                        $used[$r['vehicle_id']][] = $season;
                    }
                }
            }
            $id = DB::table('vehicle_pricing_versions')->insertGetId(['company_id' => Tenant::id(), 'actor_membership_id' => $actor, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'reason' => $input['reason'], 'configuration' => json_encode($config), 'created_at' => now()]);
            foreach ($normal as $r) {
                DB::table('vehicle_pricing_rates')->insert(['company_id' => Tenant::id(), 'version_id' => $id, 'vehicle_id' => $r['vehicle_id'], 'daily_centimes' => $r['daily_centimes'], 'minimum_days' => $r['minimum_days'], 'active' => true]);
            }
            foreach ($config['seasons'] as $season) {
                foreach ($season['rates'] as $r) {
                    DB::table('vehicle_pricing_rates')->insert(['company_id' => Tenant::id(), 'version_id' => $id, 'vehicle_id' => $r['vehicle_id'], 'season_key' => $season['key'], 'starts_on' => $season['starts_on'], 'ends_on' => $season['ends_on'], 'daily_centimes' => $r['daily_centimes'] ?? $normal[$r['vehicle_id']]['daily_centimes'], 'minimum_days' => $r['minimum_days'], 'active' => $season['active']]);
                }
            }

            return ['id' => $id, 'replayed' => false];
        });
    }

    public function simulate(int $vehicle, array $period, bool $initialStay = true): array
    {
        Vehicle::findOrFail($vehicle);
        $version = $this->current();
        abort_unless($version, 422, 'Définissez et enregistrez d’abord les tarifs.');
        $config = json_decode($version->configuration, true);
        $normal = collect($config['vehicles'])->firstWhere('vehicle_id', $vehicle);
        abort_unless($normal, 422, 'Le prix normal de cette voiture n’est pas défini.');
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $period['starts_local'], 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $period['ends_local'], 'UTC');
        $minutes = (int) $start->diffInMinutes($end);
        abort_if($minutes > 527040, 422, 'La simulation est limitée à 366 jours.');
        $lines = [];
        $twiceTotal = 0;
        $minimum = 0;
        $cursor = $start;
        while ($cursor < $end) {
            $remaining = (int) $cursor->diffInMinutes($end);
            $units = $remaining <= 720 ? 1 : 2;
            $rate = $normal['daily_centimes'];
            $label = 'Tarif normal';
            $key = null;
            $lineMinimum = $normal['minimum_days'];
            foreach ($config['seasons'] as $season) {
                if (! $season['active'] || $cursor->toDateString() < $season['starts_on'] || $cursor->toDateString() > $season['ends_on']) {
                    continue;
                }$special = collect($season['rates'])->firstWhere('vehicle_id', $vehicle);
                if ($special) {
                    $rate = $special['daily_centimes'] ?? $rate;
                    $lineMinimum = $special['minimum_days'];
                    $label = $season['name'];
                    $key = $season['key'];
                }
            }
            $minimum = max($minimum, $lineMinimum);
            $next = $cursor->addDay()->min($end);
            $twice = $rate * $units;
            $twiceTotal += $twice;
            $lines[] = ['starts_local' => $cursor->format('Y-m-d\TH:i'), 'ends_local' => $next->format('Y-m-d\TH:i'), 'label' => $label, 'season_key' => $key, 'daily_centimes' => $rate, 'half_day_units' => $units, 'twice_centimes' => $twice];
            $cursor = $next;
        }
        abort_if($initialStay && $minutes < $minimum * 1440, 422, "Cette période nécessite au moins $minimum jour(s) de location pour cette voiture.");
        $today = now(RequestPeriod::TIMEZONE)->toDateString();
        $from = $normal['daily_centimes'];
        foreach ($config['seasons'] as $s) {
            if ($s['active'] && $s['ends_on'] >= $today) {
                $r = collect($s['rates'])->firstWhere('vehicle_id', $vehicle);
                if ($r) {
                    $from = min($from, $r['daily_centimes'] ?? $normal['daily_centimes']);
                }
            }
        }

        return ['version_id' => $version->id, 'vehicle_id' => $vehicle, 'currency' => 'MAD', 'total_centimes' => intdiv($twiceTotal + 1, 2), 'lines' => $lines, 'minimum_days' => $minimum, 'from_daily_centimes' => $from, 'period' => $period, 'rule' => 'Le tarif est celui du début de chaque journée de location. Jusqu’à 12 h : demi-journée ; au-delà : journée. Arrondi une seule fois au total.', 'availability_checked' => false];
    }
}
