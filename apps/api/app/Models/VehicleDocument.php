<?php

namespace App\Models;

use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleDocument extends Model
{
    use HasFactory;

    public const KINDS = ['registration', 'insurance', 'inspection', 'tax', 'circulation'];

    public const METADATA = ['kind', 'change_kind', 'reason', 'effective_on', 'expires_on', 'provider', 'policy_number', 'tax_year', 'paid_on'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $visible = ['id', 'kind', 'version', 'change_kind', 'reason', 'effective_on', 'expires_on', 'provider', 'policy_number', 'tax_year', 'paid_on', 'mime', 'size', 'created_at', 'has_file', 'deadline', 'actor'];

    protected $appends = ['has_file', 'deadline'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'tax_year' => 'integer'];
    }

    public function getHasFileAttribute(): bool
    {
        return $this->path !== null;
    }

    public function getDeadlineAttribute(): array
    {
        $today = CarbonImmutable::today('Africa/Casablanca');
        if (! $this->expires_on) {
            return ['state' => 'recorded', 'days' => null];
        }
        $days = (int) $today->diffInDays(CarbonImmutable::parse($this->expires_on, 'Africa/Casablanca'), false);
        if ($days < 0) {
            $state = 'overdue';
        } elseif ($this->effective_on && $this->effective_on > $today->toDateString()) {
            $state = 'scheduled';
        } elseif ($days <= 7) {
            $state = 'urgent';
        } elseif ($days <= 30) {
            $state = 'soon';
        } else {
            $state = 'valid';
        }

        return ['state' => $state, 'days' => $days];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('company', fn (Builder $q) => $q->where($q->qualifyColumn('company_id'), Tenant::id()));
        static::creating(function (self $record): void {
            $company = Tenant::id();
            abort_if($record->company_id !== null && (int) $record->company_id !== $company, 403);
            $record->company_id = $company;
        });
        static::updating(fn () => abort(403, 'Créez une nouvelle version.'));
        static::deleting(fn () => abort(403, 'Suppression non disponible.'));
    }
}
