<?php

namespace App\Support\Vehicles;

use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Historical completed interventions only; no financial or readiness operations. */
class MaintenanceExcelImport
{
    public const COLUMNS = ['plate' => 'Immatriculation *', 'performed_on' => 'Date de réalisation (jj/mm/aaaa) *', 'kind' => 'Type de travail *', 'title' => 'Intervention *', 'kilometers' => 'Kilométrage', 'garage' => 'Garage / prestataire', 'notes' => 'Notes'];

    public const LABELS = ['vidange' => 'oil', 'freins' => 'brakes', 'pneus' => 'tires', 'batterie' => 'battery', 'moteur' => 'engine', 'carrosserie' => 'body', 'entretien' => 'service', 'réparation' => 'repair'];

    public function validateRows(array $rows, bool $retry): array
    {
        $seen = [];
        $result = [];
        $work = app(MaintenanceWork::class);
        foreach ($rows as $row) {
            $data = $row['data'];
            $data['creation_key'] ??= (string) Str::uuid();
            $data['plate'] = mb_strtoupper(trim($data['plate'] ?? ''));
            $data['kind'] = self::LABELS[mb_strtolower($data['kind'] ?? '')] ?? $data['kind'];
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data['performed_on'] ?? '', $m)) {
                $data['performed_on'] = "$m[3]-$m[2]-$m[1]";
            }
            $item = $this->item($data);
            $rules = [...$work->rules(), 'plate' => ['required', 'string', 'max:30'], 'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'garage' => ['nullable', 'string', 'max:150'], 'creation_key' => ['required', 'uuid']];
            $errors = Validator::make([...$data, ...$item], $rules, ['required' => 'Le champ :attribute est obligatoire.', 'in' => 'Choisissez un type de travail du modèle.', 'date_format' => 'La date doit respecter jj/mm/aaaa.', 'before_or_equal' => 'La réalisation ne peut pas être dans le futur.', 'integer' => 'Le kilométrage doit être un nombre entier.', 'max' => 'Le champ :attribute est trop long.'], self::COLUMNS)->errors()->all();
            $vehicle = Vehicle::where('plate_key', VehicleFields::identifier($data['plate']))->first();
            if (! $vehicle) {
                $errors[] = 'Cette immatriculation ne correspond à aucune voiture de votre parc.';
            } elseif (isset($data['vehicle_id']) && $data['vehicle_id'] !== $vehicle->id) {
                $errors[] = 'La voiture associée à cette plaque a changé. Analysez le fichier à nouveau.';
            } else {
                $data['vehicle_id'] = $vehicle->id;
            }
            $key = ($vehicle?->id ?? $data['plate']).'|'.($data['performed_on'] ?? '').'|'.$data['kind'].'|'.mb_strtolower(trim($data['title'] ?? ''));
            if (isset($seen[$key])) {
                $errors[] = 'Intervention répétée dans le fichier (ligne '.$seen[$key].').';
            }
            $seen[$key] = $row['line'];
            $own = $retry ? DB::table('vehicle_maintenance')->where('company_id', Tenant::id())->where('request_key', $data['creation_key'])->exists() : false;
            if (! $errors && ! $own) {
                $base = DB::table('vehicle_maintenance')->where('company_id', Tenant::id())->where('vehicle_id', $vehicle->id)->where('performed_on', $data['performed_on']);
                $duplicate = (clone $base)->where('kind', $data['kind'])->whereRaw('lower(trim(title))=lower(trim(?))', [$data['title']])->exists();
                $duplicate = $duplicate || DB::table('maintenance_work_items as w')->join('vehicle_maintenance as m', function ($join) {
                    $join->on('m.company_id', '=', 'w.company_id')->on('m.id', '=', 'w.maintenance_id');
                })->where('m.company_id', Tenant::id())->where('m.vehicle_id', $vehicle->id)->where('w.completed_on', $data['performed_on'])->where('w.kind', $data['kind'])->whereRaw('lower(trim(w.title))=lower(trim(?))', [$data['title']])->exists();
                if ($duplicate) {
                    $errors[] = 'Une intervention de même date, type et intitulé existe déjà pour cette voiture. Vérifiez son historique.';
                }
            }
            $result[] = ['line' => $row['line'], 'data' => $data, 'errors' => array_values(array_unique($errors))];
        }

        return $result;
    }

    public function save(array $data, int $actor, string $sourceHash, int $line): int
    {
        $maintenance = app(VehicleMaintenance::class);
        $work = app(MaintenanceWork::class);
        // The caller holds the agency lock for the whole batch; lock the vehicle too.
        $maintenance->lock($data['vehicle_id']);
        $hash = $maintenance->hash($data);
        if ($existing = $maintenance->replay('vehicle_maintenance', $data['creation_key'], $hash)) {
            return $existing->id;
        }
        $item = $work->validateItem($this->item($data), (object) ['performed_on' => $data['performed_on']]);
        $id = DB::table('vehicle_maintenance')->insertGetId([
            'company_id' => Tenant::id(), 'vehicle_id' => $data['vehicle_id'], 'actor_membership_id' => $actor,
            'kind' => $data['kind'], 'title' => $data['title'], 'performed_on' => $data['performed_on'], 'kilometers' => $data['kilometers'],
            'garage' => $data['garage'], 'notes' => $data['notes'], 'cost_centimes' => 0, 'in_garage' => false,
            'request_key' => $data['creation_key'], 'request_hash' => $hash, 'created_at' => now(),
        ]);
        // Unknown cost is explicit, never represented as a confirmed free intervention.
        $work->append((object) ['id' => $id], $item, $actor, (string) Str::uuid(), 1, (string) Str::uuid(), $hash);
        $maintenance->audit($data['vehicle_id'], $actor, 'maintenance_excel_imported', ['maintenance_id' => $id, 'source_sha256' => $sourceHash, 'line' => $line, 'performed_on' => $data['performed_on'], 'title' => $data['title']]);

        return $id;
    }

    private function item(array $data): array
    {
        return ['kind' => $data['kind'], 'title' => $data['title'], 'status' => 'done', 'cost_status' => 'unknown', 'amount_centimes' => null, 'completed_on' => $data['performed_on'], 'kilometers' => $data['kilometers'], 'notes' => $data['notes']];
    }
}
