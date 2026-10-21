<?php

namespace App\Models;

use App\Support\Tenancy\Tenant;
use Database\Factories\CompanyMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMembership extends Model
{
    /** @use HasFactory<CompanyMembershipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_primary_responsible' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->where($query->qualifyColumn('company_id'), Tenant::id());
        });
        static::saving(function (self $membership): void {
            $companyId = Tenant::id();
            abort_if($membership->company_id !== null && (int) $membership->company_id !== $companyId,
                403, 'Agence inaccessible.');
            if ($membership->exists) {
                abort_if((int) $membership->getOriginal('company_id') !== $companyId
                    || $membership->isDirty(['company_id', 'user_id', 'is_primary_responsible']),
                    403, 'Modification du rattachement interdite.');
            }
            $membership->company_id = $companyId;
        });
        static::deleting(function (self $membership): void {
            abort(403, 'Suppression du rattachement non disponible.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
