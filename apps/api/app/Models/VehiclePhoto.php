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

    /** Latest slot only, with archived photos hidden without reviving older versions. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vehicle_photos as newer')
            ->whereColumn('newer.company_id', 'vehicle_photos.company_id')->whereColumn('newer.vehicle_id', 'vehicle_photos.vehicle_id')
            ->whereColumn('newer.slot', 'vehicle_photos.slot')->whereColumn('newer.version', '>', 'vehicle_photos.version'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vehicle_events as removed')
                ->whereColumn('removed.company_id', 'vehicle_photos.company_id')->whereColumn('removed.vehicle_id', 'vehicle_photos.vehicle_id')
                ->where('removed.action', 'photo_removed')->whereRaw("removed.after::jsonb->>'photo_id' = vehicle_photos.id::text"));
    }

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
