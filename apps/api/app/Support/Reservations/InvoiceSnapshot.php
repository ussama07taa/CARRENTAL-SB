<?php
namespace App\Support\Reservations;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Vehicle;
class InvoiceSnapshot {
 public function make(int $reservation): array {
            $r = Reservation::findOrFail($reservation);
            abort_if($r->status === 'annulee', 422, 'Ce dossier est annulé. La facturation après annulation reste à examiner.');
            $financial = app(RentalPayment::class)->summary($r);
            abort_if(($financial['total_centimes'] ?? null) === null, 422, 'Convenez du prix avant de préparer la facture.');
            $customer = Customer::findOrFail($r->customer_id);
            $agency = app(\App\Support\Reservations\AgencyDocumentProfile::class)->current();

            return ['agency' => $agency, 'id' => $r->id, 'language' => $customer->language === 'ar' ? 'ar' : 'fr',
                'customer' => $customer->only(['first_name', 'last_name', 'address']),
                'vehicle' => Vehicle::findOrFail($r->vehicle_id)->only(['make', 'model', 'plate']),
                'starts' => $r->starts_at->setTimezone(RequestPeriod::TIMEZONE)->format('d/m/Y H:i'),
                'ends' => $r->ends_at->setTimezone(RequestPeriod::TIMEZONE)->format('d/m/Y H:i'),
                'date' => now(RequestPeriod::TIMEZONE)->format('d/m/Y'),
                'tax' => $financial['tax'] ?? null, 'total_centimes' => (int) $financial['total_centimes']];
 }
 public function fingerprint(array $snapshot): string { return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)); }
}
