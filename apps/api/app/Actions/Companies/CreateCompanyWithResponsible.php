<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateCompanyWithResponsible
{
    /** Operator-only bootstrap. Not public signup or account linking. */
    public function handle(string $companyName, string $responsibleName, string $email, #[\SensitiveParameter] string $password): Company
    {
        $data = Validator::make([
            'company_name' => trim($companyName),
            'responsible_name' => trim($responsibleName),
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ], [
            'company_name' => ['required', 'string', 'max:255'],
            'responsible_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:72', Password::min(12),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('Le mot de passe ne doit pas dépasser 72 octets.');
                    }
                },
            ],
        ])->validate();

        return DB::transaction(function () use ($data): Company {
            DB::statement('LOCK TABLE companies IN SHARE ROW EXCLUSIVE MODE');
            if (Company::query()->exists()) {
                throw ValidationException::withMessages(['company_name' => 'Une agence est déjà configurée. Aucune modification effectuée.']);
            }
            if (User::query()->whereRaw('lower(email) = ?', [$data['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'Ce compte existe déjà. Son mot de passe et ses accès restent inchangés.']);
            }
            $company = Company::query()->create(['name' => $data['company_name']]);
            $user = User::query()->create([
                'name' => $data['responsible_name'], 'email' => $data['email'], 'password' => $data['password'],
            ]);
            Tenant::run($company->id, function () use ($company, $user): void {
                $membership = new CompanyMembership;
                $membership->user_id = $user->id;
                $membership->is_primary_responsible = true;
                $membership->save();
                DB::table('company_setup_events')->insert([
                    'company_id' => $company->id,
                    'responsible_membership_id' => $membership->id,
                    'source' => 'operator_cli',
                    'operator_name' => posix_getpwuid(posix_geteuid())['name'] ?? 'uid:'.posix_geteuid(),
                    'created_at' => now(),
                ]);
            });

            return $company;
        });
    }
}
