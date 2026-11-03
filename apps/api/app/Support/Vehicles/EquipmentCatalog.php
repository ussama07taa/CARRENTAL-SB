<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentCatalog
{
    public static function remember(array $labels, bool $restore = false): array
    {
        return DB::transaction(function () use ($labels, $restore) {
            $company = Tenant::id();
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $name = trim($labels['fr'] ?? '');
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $name));
            DB::table('vehicle_equipment_catalog')->insertOrIgnore(['company_id' => $company, 'name' => $name, 'name_key' => $key, 'labels' => json_encode($labels), 'created_at' => now(), 'updated_at' => now()]);
            if ($restore) {
                DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('name_key', $key)->update(['archived_at' => null, 'updated_at' => now()]);
            }
            $row = DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('name_key', $key)->first();

            return ['id' => $row->id, 'name' => $row->name, 'labels' => json_decode($row->labels, true)];
        });
    }

    public static function manage(int $id, array $input): void
    {
        DB::transaction(function () use ($id, $input) {
            $company = Tenant::id();
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->archived_at !== null || $row->name !== $input['expected_name'], 409, 'Cet équipement a changé. Rouvrez le formulaire pour actualiser la liste.');
            if ($input['action'] === 'rename') {
                $name = trim(preg_replace('/\s+/u', ' ', $input['name']));
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => 'Saisissez le nom de l’équipement.']);
                }
                $key = mb_strtolower($name);
                if ($key === $row->name_key) {
                    DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('id', $id)->update(['name' => $name, 'labels' => json_encode(array_fill_keys(['fr', 'ar', 'en', 'es'], $name)), 'updated_at' => now()]);

                    return;
                }
                if (DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('name_key', $key)->whereNull('archived_at')->exists()) {
                    throw ValidationException::withMessages(['name' => 'Ce nom existe déjà dans la liste.']);
                }
                // Keep the former name as an archived entry: saving an old vehicle must not republish it.
                self::remember(array_fill_keys(['fr', 'ar', 'en', 'es'], $name), true);
            }
            DB::table('vehicle_equipment_catalog')->where('company_id', $company)->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
        });
    }

    public static function all(): array
    {
        return DB::table('vehicle_equipment_catalog')->where('company_id', Tenant::id())->whereNull('archived_at')->orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'labels' => json_decode($r->labels, true)])->all();
    }
}
