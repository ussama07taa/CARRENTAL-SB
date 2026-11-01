<?php

namespace App\Support\Vehicles;

use App\Models\CompanyMembership;

class MaintenanceAccess
{
    public function financial(int $user): bool
    {

        return (bool) CompanyMembership::where('user_id', $user)->firstOrFail()->is_primary_responsible;

    }

    public function response(array $data, int $user): array
    {

        $financial = $this->financial($user);

        return [...($financial ? $data : $this->operational(json_decode(json_encode($data), true))), 'can_manage_finance' => $financial];

    }

    // Explicit projection: new financial fields are never exposed to staff by default.

    private function operational(array $data): array
    {

        $allowed = array_flip(explode(' ', 'data rental_returns reservation_id id vehicle vehicle_id plate make model odometer value date today total page last_page counts files attention deferred garage all cancelled readiness coverage vehicle_count without_history without_reminder key category entry_id source_entry_id title work_titles km garage_open work_state state action item_key kind status version revision cancellation_revision cancellation reason actor created_at performed_on kilometers notes in_garage returned_on returned note ready readiness_pending followup_return_id checked_on items followups tracking_revision follow_up_on visit started_on history occurred_on completed_on next_on next_km legacy summary reminders remaining_km needs_odometer days since'));

        $out = [];

        foreach ($data as $key => $value) {

            if (! is_int($key) && ! isset($allowed[$key]) && ! preg_match('/^[a-f0-9-]{36}$/i', (string) $key)) {

                continue;

            }

            $out[$key] = is_array($value) ? $this->operational($value) : $value;

        }

        return $out;

    }

}
