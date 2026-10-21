<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Tenant
{
    private ?int $companyId = null;

    public static function id(): int
    {
        return app(self::class)->companyId ?? throw new HttpException(403, 'Contexte agence requis.');
    }

    /** Trusted jobs/commands only. Context is not user authorization. */
    public static function run(int $companyId, Closure $callback): mixed
    {
        abort_unless(Company::query()->whereKey($companyId)->exists(), 403, 'Agence inaccessible.');
        $context = app(self::class);
        $previous = $context->companyId;
        $previousTeam = getPermissionsTeamId();
        try {
            $context->companyId = $companyId;
            setPermissionsTeamId($companyId);

            return $callback();
        } finally {
            $context->companyId = $previous;
            setPermissionsTeamId($previousTeam);
        }
    }

    /** User must come from authentication, never a request-supplied user ID. */
    public static function forUser(User $user, int $companyId, Closure $callback): mixed
    {
        abort_unless($user->exists && DB::table('company_memberships')
            ->where('company_id', $companyId)->where('user_id', $user->getKey())->exists(),
            403, 'Agence inaccessible.');

        return self::run($companyId, $callback);
    }
}
