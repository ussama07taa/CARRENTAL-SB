<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\ForeignTravel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationForeignTravelController extends Controller
{
    private function actor(Request $r): CompanyMembership
    {
        return CompanyMembership::where('user_id', $r->user()->id)->firstOrFail();
    }

    public function show(Request $r, int $reservation, ForeignTravel $travel): JsonResponse
    {
        return DB::transaction(function () use ($r, $reservation, $travel): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => $travel->summary(Reservation::findOrFail($reservation), $this->actor($r))]);
        });
    }

    public function store(Request $r, int $reservation, ForeignTravel $travel): JsonResponse
    {
        $action = $r->validate(['action' => ['required', Rule::in(['request', 'approve', 'refuse', 'cancel', 'issue'])]])['action'];
        $specific = match ($action) {
            'request' => ['countries' => ['required', 'array', 'min:1', 'max:20'], 'countries.*' => ['required', 'string', 'min:2', 'max:80', 'distinct:ignore_case'], 'transit_countries' => ['present', 'array', 'max:20'], 'transit_countries.*' => ['required', 'string', 'min:2', 'max:80', 'distinct:ignore_case'], 'starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['required', 'date_format:Y-m-d\TH:i']],
            'approve' => ['insurer' => ['required', 'string', 'max:120'], 'insurance_reference' => ['required', 'string', 'max:150'], 'insurance_starts_on' => ['required', 'date_format:Y-m-d'], 'insurance_expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:insurance_starts_on'], 'coverage_attested' => ['required', 'accepted'], 'responsible_approved' => ['required', 'accepted']],
            default => [],
        };
        $input = $r->validate(['action' => ['required', Rule::in([$action])], ...$specific, 'expected_revision' => ['present', 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'approved' => ['prohibited']],
            ['required' => 'Ce champ est obligatoire.', 'accepted' => 'Confirmez le contrôle réel avant de continuer.', 'date_format' => 'Saisissez une date valide.', 'distinct' => 'Chaque pays doit apparaître une seule fois.', 'after_or_equal' => 'La fin doit être après le début.', 'min' => 'La valeur est insuffisante.']);
        $actor = $this->actor($r);
        $result = $travel->save($reservation, $actor, $input);

        return response()->json(['data' => $travel->summary(Reservation::findOrFail($reservation), $actor), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function content(Request $r, int $reservation, int $authorization, string $format, ForeignTravel $travel): StreamedResponse
    {
        abort_unless(in_array($format, ['pdf', 'docx'], true), 404);
        $record = Reservation::findOrFail($reservation);
        $actor = $this->actor($r);
        abort_unless($actor->is_primary_responsible, 403);
        $summary = $travel->summary($record, $actor);
        abort_unless($summary['authorization'] && $summary['authorization']['id'] === $authorization && $summary['authorization']['download_allowed'], 409, 'Cette autorisation doit être revue : demande ou documents modifiés.');
        $event = $travel->events($reservation)->where('action', 'foreign_authorization_generated')->where('data->issue_id', $authorization)->firstOrFail();
        $file = data_get(json_decode($event->data, true), 'files.'.$format);
        abort_unless($file && Storage::disk('rental_contracts')->exists($file['path']), 404);
        $bytes = Storage::disk('rental_contracts')->get($file['path']);
        abort_unless(hash_equals($file['sha256'], hash('sha256', $bytes)), 503, 'Le document conservé nécessite un contrôle.');

        return response()->streamDownload(fn () => print ($bytes), $summary['authorization']['reference'].'.'.$format, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
