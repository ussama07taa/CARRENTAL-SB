<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaintenanceWork
{
    public const KINDS = ['oil', 'brakes', 'tires', 'battery', 'engine', 'body', 'service', 'repair'];

    public function rules(string $prefix = ''): array
    {
        $p = $prefix;
        $today = now('Africa/Casablanca')->toDateString();

        return [$p.'kind' => ['required', Rule::in(self::KINDS)], $p.'title' => ['required', 'string', 'max:150'], $p.'status' => ['required', Rule::in(['pending', 'progress', 'done', 'deferred'])], $p.'cost_status' => ['required', Rule::in(['unknown', 'estimate', 'confirmed'])], $p.'amount_centimes' => ['nullable', 'integer', 'between:0,1000000000'], $p.'completed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today], $p.'kilometers' => ['nullable', 'integer', 'between:0,9999999'], $p.'next_on' => ['nullable', 'date_format:Y-m-d'], $p.'next_km' => ['nullable', 'integer', 'between:1,9999999'], $p.'reason' => ['nullable', 'string', 'max:500'], $p.'notes' => ['nullable', 'string', 'max:2000']];
    }

    public function latest(object $entry, ?array $snapshot = null): array
    {
        $rows = $snapshot !== null ? ($snapshot['works'][$entry->id] ?? collect())->map(fn ($row) => clone $row)->all() : DB::table('maintenance_work_items')->where('company_id', Tenant::id())->where('maintenance_id', $entry->id)->orderBy('id')->get()->groupBy('item_key')->map(fn ($versions) => $versions->sortByDesc('version')->first())->values()->all();
        if ($rows) {
            foreach ($rows as $row) {
                $row->amount_centimes = $row->amount_centimes === null ? null : (int) $row->amount_centimes;
                unset($row->company_id,$row->request_hash,$row->request_key,$row->actor_membership_id);
            }

            return $rows;
        }
        $done = ! $entry->in_garage || ($snapshot !== null ? isset($snapshot['returns'][$entry->id]) : DB::table('maintenance_returns')->where('company_id', Tenant::id())->where('maintenance_id', $entry->id)->exists());

        return [(object) ['id' => null, 'item_key' => null, 'version' => 0, 'kind' => $entry->kind, 'title' => $entry->title, 'status' => $done ? 'done' : 'progress', 'cost_status' => 'confirmed', 'amount_centimes' => (int) $entry->cost_centimes, 'completed_on' => $done ? $entry->performed_on : null, 'kilometers' => $entry->kilometers, 'next_on' => $entry->next_on, 'next_km' => $entry->next_km, 'reason' => null, 'notes' => $entry->notes, 'legacy' => true]];
    }

    public function financial(object $entry, ?array $snapshot = null): object
    {
        $entry->items = $this->latest($entry, $snapshot);
        app(MaintenanceCancellations::class)->apply($entry, $snapshot);
        $entry->cost_centimes = 0;
        $entry->cost_final = true;
        $entry->unknown_count = 0;
        $entry->estimated_centimes = 0;
        foreach ($entry->items as $item) {
            if ($item->status === 'cancelled') {
                continue;
            }
            if ($item->cost_status === 'unknown') {
                $entry->unknown_count++;
            }$entry->cost_centimes += (int) ($item->amount_centimes ?? 0);
            if ($item->cost_status === 'estimate') {
                $entry->estimated_centimes += (int) $item->amount_centimes;
            }if ($item->cost_status !== 'confirmed') {
                $entry->cost_final = false;
            }
        }
        $entry->revision = $snapshot !== null ? (int) collect($entry->items)->max('id') : (int) (DB::table('maintenance_work_items')->where('company_id', Tenant::id())->where('maintenance_id', $entry->id)->max('id') ?? 0);

        return $entry;
    }

    public function validateItem(array $input, object $entry): array
    {
        $fields = ['kind', 'title', 'status', 'cost_status', 'amount_centimes', 'completed_on', 'kilometers', 'next_on', 'next_km', 'reason', 'notes'];
        $item = array_fill_keys($fields, null);
        $item = array_replace($item, array_intersect_key($input, $item));
        if ($item['cost_status'] === 'unknown') {
            $item['amount_centimes'] = null;
        } elseif ($item['amount_centimes'] === null) {
            throw ValidationException::withMessages(['amount_centimes' => 'Renseignez le montant ou choisissez « Non défini ».']);
        }
        if ($item['status'] === 'done' && ! $item['completed_on']) {
            throw ValidationException::withMessages(['completed_on' => 'Indiquez la date de réalisation.']);
        }
        if ($item['completed_on'] && $item['completed_on'] < $entry->performed_on) {
            throw ValidationException::withMessages(['completed_on' => 'La réalisation ne peut pas précéder l’entrée au garage.']);
        }
        if ($item['status'] === 'deferred' && ! trim($item['reason'] ?? '')) {
            throw ValidationException::withMessages(['reason' => 'Expliquez pourquoi ce travail est reporté.']);
        }
        if ($item['status'] !== 'done') {
            if ($item['next_on'] || $item['next_km']) {
                throw ValidationException::withMessages(['next_on' => 'Programmez le prochain entretien après la réalisation de ce travail.']);
            }$item['completed_on'] = null;
            $item['kilometers'] = null;
        }
        if ($item['next_on'] && $item['next_on'] <= $item['completed_on']) {
            throw ValidationException::withMessages(['next_on' => 'Le prochain entretien doit être après la réalisation.']);
        }
        if ($item['next_km'] && $item['kilometers'] !== null && $item['next_km'] <= $item['kilometers']) {
            throw ValidationException::withMessages(['next_km' => 'Le prochain compteur doit dépasser le relevé de cette intervention.']);
        }

        return $item;
    }

    public function append(object $entry, array $item, int $actor, string $key, int $version, string $requestKey, string $hash): int
    {
        return DB::table('maintenance_work_items')->insertGetId([...$item, 'company_id' => Tenant::id(), 'maintenance_id' => $entry->id, 'actor_membership_id' => $actor, 'item_key' => $key, 'version' => $version, 'request_key' => $requestKey, 'request_hash' => $hash, 'created_at' => now()]);
    }

    public function preserveLegacy(object $entry, int $actor): void
    {
        $items = $this->latest($entry);
        if (! ($items[0]->legacy ?? false)) {
            return;
        }
        $legacy = (array) $items[0];
        $legacy['status'] = ($legacy['status'] === 'done' || $entry->next_on || $entry->next_km) ? 'done' : 'progress';
        $legacy['completed_on'] = $legacy['status'] === 'done' ? $entry->performed_on : null;
        $item = $this->validateItem($legacy, $entry);
        // Legacy planned reminders stay attached to their original record until completed.
        $this->append($entry, $item, $actor, (string) Str::uuid(), 1, (string) Str::uuid(), hash('sha256', 'legacy-'.$entry->id));
    }
}
