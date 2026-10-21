<?php

namespace App\Models;

use App\Support\Customers\CustomerFields;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = CustomerFields::EDITABLE;

    protected $hidden = ['company_id', 'creation_key', 'creation_hash'];

    protected $appends = ['reference'];

    public function getReferenceAttribute(): string
    {
        return 'CL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', fn (Builder $query) => $query->where($query->qualifyColumn('company_id'), Tenant::id()));
        static::saving(function (self $customer): void {
            $companyId = Tenant::id();
            abort_if($customer->company_id !== null && (int) $customer->company_id !== $companyId, 403, 'Agence inaccessible.');
            abort_if($customer->exists && (int) $customer->getOriginal('company_id') !== $companyId, 403, 'Agence inaccessible.');
            $customer->company_id = $companyId;
        });
        static::deleting(fn () => abort(403, 'Suppression non disponible.'));
    }
}
