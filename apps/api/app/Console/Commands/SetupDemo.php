<?php

namespace App\Console\Commands;

use App\Actions\Companies\CreateCompanyWithResponsible;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupDemo extends Command
{
    protected $signature = 'company:demo';

    protected $description = 'Créer un compte fictif exclusivement dans la base bensebih_demo.';

    public function handle(CreateCompanyWithResponsible $create): int
    {
        if (! app()->environment('demo') || DB::connection()->getDatabaseName() !== 'bensebih_demo') {
            $this->error('Commande réservée à l’environnement demo et à la base bensebih_demo.');

            return self::FAILURE;
        }
        if (Company::query()->exists()) {
            $this->error('Démonstration déjà configurée. Aucun compte modifié.');

            return self::FAILURE;
        }
        $directory = base_path('../../.local');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/demo-account.json';
        if (file_exists($path)) {
            $this->error('Fichier de démonstration déjà présent. Vérifiez-le avant toute création.');

            return self::FAILURE;
        }
        $password = Str::random(24);
        $previousMask = umask(0077);
        try {
            DB::transaction(function () use ($create, $password, $path): void {
                $create->handle('BENSEBIHI · AGENCE DÉMO', 'Responsable Démo', 'responsable@example.test', $password);
                $written = file_put_contents($path, json_encode([
                    'notice' => 'Compte fictif local uniquement. Ne pas réutiliser pour le vrai compte.',
                    'email' => 'responsable@example.test', 'password' => $password,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
                if ($written === false) {
                    throw new \RuntimeException('Impossible de sauvegarder les accès locaux.');
                }
            });
        } finally {
            umask($previousMask);
        }
        $this->info('Démonstration créée. Accès dans .local/demo-account.json à la racine, jamais dans Git.');

        return self::SUCCESS;
    }
}
