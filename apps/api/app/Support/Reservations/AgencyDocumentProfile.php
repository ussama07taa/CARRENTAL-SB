<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Support\Tenancy\Tenant;

class AgencyDocumentProfile
{
    public function current(): array
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('agency_profiles')) {
            $row = \Illuminate\Support\Facades\DB::table('agency_profiles')->where('company_id', Tenant::id())->first();
            if ($row) {
                $details = array_filter(json_decode($row->details, true), fn ($v) => is_string($v) && trim($v) !== '');
                $details['branding'] = 'custom';
                if ($row->logo_base64) $details['logo_data_uri'] = 'data:'.$row->logo_mime.';base64,'.$row->logo_base64;
                return $details;
            }
        }
        $path = storage_path('app/private/invoice-profiles.json');
        $profiles = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        $profile = $profiles[(string) Tenant::id()] ?? [];

        return array_filter(['name' => $profile['name'] ?? Company::findOrFail(Tenant::id())->name, ...array_intersect_key($profile, array_flip(['address', 'capital', 'rc', 'ice', 'if', 'tp', 'phone', 'email']))], fn ($v) => is_string($v) && trim($v) !== '');
    }
}
