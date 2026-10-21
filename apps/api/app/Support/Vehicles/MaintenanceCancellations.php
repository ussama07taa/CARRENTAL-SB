<?php

namespace App\Support\Vehicles;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaintenanceCancellations
{
    public function rows(array $ids): Collection
    {
        return DB::table('maintenance_cancellations as c')->join('company_memberships as m', function ($j) {
            $j->on('c.company_id', '=', 'm.company_id')->on('c.actor_membership_id', '=', 'm.id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('c.company_id', Tenant::id())->whereIn('c.maintenance_id', $ids)->orderBy('c.id')->get(['c.id', 'c.maintenance_id', 'c.target', 'c.before_centimes', 'c.reason', 'c.created_at', 'u.name as actor'])->groupBy('maintenance_id');
    }

    public function apply(object $entry, ?array $snapshot): void
    {
        $rows = ($snapshot === null ? $this->rows([$entry->id]) : $snapshot['cancellations'])[$entry->id] ?? collect();
        $entry->cancellation = $rows->firstWhere('target', 'file');
        $entry->cancellation_revision = (int) ($rows->max('id') ?? 0);
        foreach ($entry->items as $item) {
            $item->cancellation = $rows->firstWhere('target', $item->item_key) ?? $entry->cancellation;
            if ($item->cancellation) {
                $item->original_status = $item->status;
                $item->status = 'cancelled';
            }
        }
    }
}
