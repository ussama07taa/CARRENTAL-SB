<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalPayment;
use App\Support\Reservations\RequestPeriod;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalInvoicePreviewController extends Controller
{
    public function show(Request $request, int $reservation)
    {
        abort_unless(CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists(), 403, 'La préparation de facture est réservée au responsable.');
        $input = $request->validate(['format' => ['nullable', 'in:pdf'], 'vat_rate' => ['prohibited']]);
        $s = DB::transaction(function () use ($reservation) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            return app(\App\Support\Reservations\InvoiceSnapshot::class)->make($reservation);
        });
        $html = view('invoices.draft', ['s' => $s])->render();
        if (($input['format'] ?? null) === 'pdf') {
            return response(app(ContractPdf::class)->renderHtml($html))->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="brouillon-location-'.$reservation.'.pdf"');
        }

        return response()->json(['html' => $html, 'total_centimes' => $s['total_centimes'], 'tax' => $s['tax'] ?? null, 'tax_status' => isset($s['tax']) ? 'preview' : 'unconfirmed', 'review_hash' => app(\App\Support\Reservations\InvoiceSnapshot::class)->fingerprint($s), 'issuance_enabled' => (bool) config('rental_contracts.real_documents_enabled') && \Illuminate\Support\Facades\Schema::hasTable('rental_invoices')]);
    }
}
