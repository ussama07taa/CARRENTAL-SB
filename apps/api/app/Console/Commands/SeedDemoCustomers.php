<?php

namespace App\Console\Commands;

use App\Actions\Customers\SaveCustomer;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemoCustomers extends Command
{
    protected $signature = 'customers:demo';

    protected $description = 'Ajouter des clients fictifs dans la démonstration isolée uniquement.';

    public function handle(SaveCustomer $save): int
    {
        if (! app()->environment('demo') || DB::connection()->getDatabaseName() !== 'bensebih_demo') {
            $this->error('Commande réservée à la base de démonstration.');

            return self::FAILURE;
        }
        $company = Company::sole();
        Tenant::run($company->id, function () use ($save): void {
            $member = CompanyMembership::where('is_primary_responsible', true)->sole();
            $examples = [
                ['Amine', 'El Mansouri', '+212600000101', 'Casablanca'],
                ['Salma', 'Bennani', '+212600000102', 'Rabat'],
                ['Youssef', 'Alaoui', '+212600000103', 'Marrakech'],
                ['Nadia', 'El Idrissi', '+212600000104', null],
                ['Mehdi', 'Tazi', '+212600000105', 'Tanger'],
                ['Lina', 'Berrada', '+212600000106', 'Casablanca'],
            ];
            foreach ($examples as $index => [$first, $last, $phone, $city]) {
                $save->handle([
                    'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
                    'city' => $city, 'language' => 'fr',
                    'email' => strtolower($first).'@example.test',
                    'creation_key' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                    'acknowledge_duplicates' => true,
                ], $member->user_id);
            }
        });
        $this->info('6 fichiers fictifs prêts. Aucun compte ni mot de passe modifié.');

        return self::SUCCESS;
    }
}
