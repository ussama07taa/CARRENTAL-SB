<?php

namespace App\Support\Reservations;

use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ContractVerification
{
    private function signature(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'contract-verification-v1:'.$payload, config('app.key'), true)), '+/', '-_'), '=');
    }

    public function token(int $company, int $contract): string
    {
        $payload = $company.'.'.$contract;

        return $payload.'.'.$this->signature($payload);
    }

    public function url(int $company, int $contract): string
    {
        return rtrim(config('rental_contracts.verification_base_url') ?: config('app.url'), '/').'/verify-contract/'.$this->token($company, $contract);
    }

    public function svg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 16), new SvgImageBackEnd)))->writeString($url);

        return preg_replace('/<\?xml.*?\?>/s', '', $svg);
    }

    public function lookup(string $token): ?array
    {
        if (! preg_match('/^([1-9][0-9]{0,17})\.([1-9][0-9]{0,17})\.([A-Za-z0-9_-]{43})$/D', $token, $parts)
            || ! hash_equals($this->signature($parts[1].'.'.$parts[2]), $parts[3])) {
            return null;
        }
        if (! DB::table('companies')->where('id', $parts[1])->exists()) {
            return null;
        }

        return Tenant::run((int) $parts[1], function () use ($parts): ?array {
            $contracts = app(RentalContract::class);
            $version = $contracts->table('rental_contract_versions')->where('id', $parts[2])->first();
            if (! $version || ! $contracts->table('rental_contract_files')->where('contract_id', $version->id)->where('kind', 'original')->exists()) {
                return null;
            }
            $newer = $contracts->table('rental_contract_versions')->where('reservation_id', $version->reservation_id)->where('version', '>', $version->version)
                ->whereExists(function ($q): void {
                    $q->selectRaw('1')->from('rental_contract_files')->whereColumn('rental_contract_files.company_id', 'rental_contract_versions.company_id')
                        ->whereColumn('rental_contract_files.contract_id', 'rental_contract_versions.id')->where('kind', 'original');
                })->exists();
            $snapshot = json_decode($version->snapshot, true);

            // Explicit public allowlist: never return the snapshot, client, car, dates of rental, money or file links.
            return [
                'reference' => 'CTR-'.str_pad((string) $version->reservation_id, 6, '0', STR_PAD_LEFT).'-V'.$version->version,
                'version' => (int) $version->version,
                'agency' => $snapshot['presentation']['agency_profile']['name'] ?? $snapshot['agency']['name'] ?? 'Agence',
                'issued_at' => CarbonImmutable::parse($version->created_at)->setTimezone('Africa/Casablanca')->format('d/m/Y H:i'),
                'newer_issued' => $newer,
                'language' => ($snapshot['language'] ?? 'fr') === 'ar' ? 'ar' : 'fr',
            ];
        });
    }
}
