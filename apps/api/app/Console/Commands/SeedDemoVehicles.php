<?php

namespace App\Console\Commands;

use App\Actions\Vehicles\SaveVehicle;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemoVehicles extends Command
{
    protected $signature = 'vehicles:demo';

    protected $description = 'Ajouter des véhicules fictifs dans la base démo isolée uniquement.';

    public function handle(SaveVehicle $save): int
    {
        if (! app()->environment('demo') || DB::connection()->getDatabaseName() !== 'bensebih_demo') {
            $this->error('Commande réservée à la base de démonstration.');

            return self::FAILURE;
        }
        $company = Company::sole();
        Tenant::run($company->id, function () use ($save): void {
            $member = CompanyMembership::where('is_primary_responsible', true)->sole();
            foreach ([['Dacia', 'Logan', 'diesel', 'manual', 42500], ['Renault', 'Clio', 'petrol', 'manual', 18620], ['Volkswagen', 'T-Cross', 'diesel', 'automatic', 31000], ['Hyundai', 'Tucson', 'hybrid', 'automatic', 7900]] as $i => [$make,$model,$fuel,$gear,$km]) {
                $key = sprintf('10000000-0000-4000-8000-%012d', $i + 1);
                if (Vehicle::where('creation_key', $key)->exists()) {
                    continue;
                }
                $save->handle(['plate' => 'DEMO-'.($i + 1), 'make' => $make, 'model' => $model, 'year' => 2024, 'fuel' => $fuel, 'transmission' => $gear,
                    'equipment' => ['air_conditioning', 'bluetooth', 'usb'], 'initial_odometer' => $km, 'initial_odometer_date' => now()->toDateString(), 'creation_key' => $key], $member->user_id);
            }
        });
        $this->info('Véhicules fictifs prêts. Aucun fichier existant modifié.');

        return self::SUCCESS;
    }
}
