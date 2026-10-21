<?php

namespace App\Models;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VehiclePhoto extends Model
{
    public const SLOTS = ['cover', 'front', 'rear', 'interior', 'boot', 'detail_1', 'detail_2', 'detail_3'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $visible = ['id', 'slot', 'version', 'mime', 'size', 'created_at'];

    protected static function booted(): void
    {
        static::addGlobalScope('company', fn (Builder $query) => $query->where($query->qualifyColumn('company_id'), Tenant::id()));
        static::creating(function (self $document): void {
            $company = Tenant::id();
            abort_if($document->company_id !== null && (int) $document->company_id !== $company, 403);
            $document->company_id = $company;
        });
        static::updating(fn () => abort(403, 'Une nouvelle version est requise.'));
        static::deleting(fn () => abort(403, 'Suppression non disponible.'));
    }
}
