<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRentalPaymentProofRequest;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RentalPaymentProofController extends Controller
{
    public function store(SaveRentalPaymentProofRequest $request, int $reservation, int $payment)
    {
        abort_unless(Schema::hasTable('rental_payment_proofs'), 503, 'Les justificatifs nécessitent l’activation de la mise à jour.');
        Reservation::findOrFail($reservation);
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            $result = DB::transaction(function () use ($request, $reservation, $payment, $actor, $file, $checksum, &$path) {
                $receipt = DB::table('rental_payments')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('id', $payment)->lockForUpdate()->first();
                abort_unless($receipt, 404);
                abort_unless(in_array($receipt->method, ['virement', 'carte'], true), 422, 'Les justificatifs sont réservés aux virements et paiements par carte.');
                $old = DB::table('rental_payment_proofs')->where('company_id', Tenant::id())->where('request_key', $request->validated('request_key'))->first();
                if ($old) {
                    abort_unless((int) $old->payment_id === $payment && hash_equals($old->checksum, $checksum), 409, 'Cette opération correspond à un autre justificatif.');

                    return ['id' => $old->id, 'replayed' => true];
                }
                $path = Tenant::id().'/'.$reservation.'/'.$payment.'/'.Str::uuid();
                Storage::disk('payment_proofs')->put($path, file_get_contents($file->getRealPath()));
                $id = DB::table('rental_payment_proofs')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'payment_id' => $payment, 'actor_membership_id' => $actor->id, 'request_key' => $request->validated('request_key'), 'path' => $path, 'mime' => $file->getMimeType(), 'checksum' => $checksum, 'size' => $file->getSize(), 'created_at' => now()]);
                DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'actor_membership_id' => $actor->id, 'action' => 'rental_payment_proof_attached', 'data' => json_encode(['payment_id' => $payment, 'proof_id' => $id]), 'created_at' => now()]);

                return ['id' => $id, 'replayed' => false];
            });
        } catch (\Throwable $e) {
            if ($path) {
                try {
                    Storage::disk('payment_proofs')->delete($path);
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            } if ($e instanceof QueryException && $e->getCode() === '23505') {
                abort(409, 'Cette opération existe déjà. Réessayez avec le même justificatif.');
            } throw $e;
        }

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }

    public function content(int $reservation, int $payment, int $proof)
    {
        abort_unless(Schema::hasTable('rental_payment_proofs'), 503, 'Les justificatifs nécessitent l’activation de la mise à jour.');
        Reservation::findOrFail($reservation);
        $doc = DB::table('rental_payment_proofs')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('payment_id', $payment)->where('id', $proof)->first();
        abort_unless($doc, 404);
        $disk = Storage::disk('payment_proofs');
        abort_unless($disk->exists($doc->path), 404);
        $bytes = $disk->get($doc->path);
        abort_unless(hash_equals($doc->checksum, hash('sha256', $bytes)), 409, 'Justificatif à vérifier.');
        $ext = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$doc->mime];

        return response($bytes)->header('Content-Type', $doc->mime)->header('Content-Disposition','inline; filename="justificatif-'.$doc->id.'.'.$ext.'"')->header('X-Content-Type-Options','nosniff')->header('Cache-Control','private, no-store');
    }
}
