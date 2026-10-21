<?php

namespace App\Console\Commands;

use App\Actions\Companies\CreateCompanyWithResponsible;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SetupCompany extends Command
{
    protected $signature = 'company:setup';

    protected $description = 'Créer la première agence et son responsable depuis un terminal privé.';

    public function handle(CreateCompanyWithResponsible $create): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Un terminal interactif est requis. Aucun mot de passe en argument.');

            return self::FAILURE;
        }
        if (Company::query()->exists()) {
            $this->error('Une agence est déjà configurée. Aucune modification effectuée.');

            return self::FAILURE;
        }
        $companyName = (string) $this->ask('Nom de l’agence');
        $name = (string) $this->ask('Nom du responsable');
        $email = (string) $this->ask('Adresse e-mail du responsable');
        $password = (string) $this->secret('Mot de passe (12 caractères minimum)', false);
        $confirmation = (string) $this->secret('Confirmer le mot de passe', false);
        if (! hash_equals($password, $confirmation)) {
            $this->error('Les mots de passe ne correspondent pas. Aucune création.');

            return self::FAILURE;
        }
        try {
            $company = $create->handle($companyName, $name, $email, $password);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $errors) {
                foreach ($errors as $error) {
                    $this->error($error);
                }
            }

            return self::FAILURE;
        }
        $this->info("Agence #{$company->id} et responsable créés. L’interface de connexion sera ajoutée à l’étape suivante.");

        return self::SUCCESS;
    }
}
