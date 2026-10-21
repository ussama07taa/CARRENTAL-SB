<?php

namespace App\Models;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['company_id', 'request_key', 'request_hash', 'actor_membership_id'];

    protected function casts(): array
    {
        return ['assignment_starts_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'hold_expires_at' => 'immutable_datetime', 'blocked_until' => 'immutable_datetime', 'preparation_minutes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', fn (Builder $query) => $query->where($query->qualifyColumn('company_id'), Tenant::id()));
        static::creating(function (self $record): void {
            abort_if($record->company_id !== null && (int) $record->company_id !== Tenant::id(), 403);
            $record->company_id = Tenant::id();
        });
        static::updating(fn () => abort(403, 'Modification de demande non disponible.'));
        static::deleting(fn () => abort(403, 'Suppression de demande non disponible.'));
    }
}
