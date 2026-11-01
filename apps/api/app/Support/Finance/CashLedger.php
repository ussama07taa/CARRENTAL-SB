<?php

namespace App\Support\Finance;

use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehiclePurchases;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashLedger
{
    public function query(): Builder
    {
        $sql = <<<'SQL'
WITH tenant AS (SELECT ?::bigint AS id),
expense_latest AS (SELECT DISTINCT ON (expense_id) * FROM expense_versions WHERE company_id=(SELECT id FROM tenant) ORDER BY expense_id,version DESC),
expense_cash AS (SELECT DISTINCT ON (movement_key) * FROM expense_movements WHERE company_id=(SELECT id FROM tenant) ORDER BY movement_key,version DESC),
maintenance_latest AS (SELECT DISTINCT ON(payment_key) * FROM maintenance_payment_corrections WHERE company_id=(SELECT id FROM tenant) ORDER BY payment_key,version DESC),
loan_latest AS (SELECT DISTINCT ON(payment_key) * FROM vehicle_loan_payment_corrections WHERE company_id=(SELECT id FROM tenant) ORDER BY payment_key,version DESC),
flows AS (
 SELECT 'rent-'||p.id AS key,'rental'::text AS source,p.reservation_id AS source_id,'Loyer reçu'::text AS label,
 concat(c.first_name,' ',c.last_name)::text AS party,r.vehicle_id,p.received_on AS occurred_on,p.method,
 p.amount_centimes AS amount_centimes,'rent'::text AS category,p.reference,p.actor_membership_id,p.created_at,
 p.id::text AS revision,EXISTS(SELECT 1 FROM rental_payment_verifications v WHERE v.company_id=p.company_id AND v.payment_id=p.id) AS verified
 FROM rental_payments p JOIN reservations r ON r.company_id=p.company_id AND r.id=p.reservation_id JOIN customers c ON c.company_id=r.company_id AND c.id=r.customer_id
 WHERE p.company_id=(SELECT id FROM tenant) AND NOT EXISTS (SELECT 1 FROM rental_settlement_entries x WHERE x.company_id=p.company_id AND x.rent_payment_id=p.id AND x.kind='payment_void')
 UNION ALL
 SELECT 'fuel-'||p.id,'rental',p.reservation_id,'Carburant reçu',concat(c.first_name,' ',c.last_name),r.vehicle_id,p.received_on,p.method,p.amount_centimes,'fuel',p.reference,p.actor_membership_id,p.created_at,p.id::text,
 EXISTS(SELECT 1 FROM rental_fuel_verifications v WHERE v.company_id=p.company_id AND v.payment_id=p.id)
 FROM rental_fuel_payments p JOIN reservations r ON r.company_id=p.company_id AND r.id=p.reservation_id JOIN customers c ON c.company_id=r.company_id AND c.id=r.customer_id
 WHERE p.company_id=(SELECT id FROM tenant) AND NOT EXISTS (SELECT 1 FROM rental_settlement_entries x WHERE x.company_id=p.company_id AND x.fuel_payment_id=p.id AND x.kind='payment_void')
 UNION ALL
 SELECT 'deposit-'||p.id,'rental',p.reservation_id,'Caution reçue',concat(c.first_name,' ',c.last_name),r.vehicle_id,p.received_on,p.method,p.amount_centimes,'deposit',p.reference,p.actor_membership_id,p.created_at,p.id::text,
 (p.method<>'virement' OR EXISTS(SELECT 1 FROM reservation_departure_entries v WHERE v.company_id=p.company_id AND v.receipt_id=p.id AND v.action='caution_verified'))
 FROM reservation_departure_entries p JOIN reservations r ON r.company_id=p.company_id AND r.id=p.reservation_id JOIN customers c ON c.company_id=r.company_id AND c.id=r.customer_id
 WHERE p.company_id=(SELECT id FROM tenant) AND p.action='caution_received'
 UNION ALL
 SELECT 'settlement-'||p.id,'rental',p.reservation_id,CASE WHEN p.kind='deposit_return' THEN 'Caution rendue' ELSE 'Remboursement client' END,concat(c.first_name,' ',c.last_name),r.vehicle_id,p.occurred_on,p.method,-p.amount_centimes,CASE WHEN p.kind='deposit_return' THEN 'deposit' ELSE 'refund' END,p.reference,p.actor_membership_id,p.created_at,p.id::text,true
 FROM rental_settlement_entries p JOIN reservations r ON r.company_id=p.company_id AND r.id=p.reservation_id JOIN customers c ON c.company_id=r.company_id AND c.id=r.customer_id
 WHERE p.company_id=(SELECT id FROM tenant) AND p.kind IN ('deposit_return','refund_paid')
 UNION ALL
 SELECT 'expense-'||p.movement_key,'expense',p.expense_id,CASE WHEN p.kind='refund' THEN 'Remboursement · ' ELSE '' END||v.title,v.supplier,NULL::bigint,p.occurred_on,p.method,CASE WHEN p.kind='refund' THEN p.amount_centimes ELSE -p.amount_centimes END,'expense',p.reference,p.actor_membership_id,p.created_at,p.id::text,true
 FROM expense_cash p JOIN expense_latest v ON v.company_id=p.company_id AND v.expense_id=p.expense_id
 UNION ALL
 SELECT 'maintenance-'||p.id,'maintenance',p.maintenance_id,m.title,COALESCE(m.garage,'Garage'),m.vehicle_id,p.paid_on,p.method,-COALESCE(c.amount_centimes,p.amount_centimes),'maintenance',p.reference,p.actor_membership_id,p.created_at,concat(p.id,':',c.id),true
 FROM maintenance_payments p JOIN vehicle_maintenance m ON m.company_id=p.company_id AND m.id=p.maintenance_id LEFT JOIN maintenance_latest c ON c.company_id=p.company_id AND c.payment_key=p.request_key
 WHERE p.company_id=(SELECT id FROM tenant) AND NOT EXISTS(SELECT 1 FROM maintenance_payment_cancellations x WHERE x.company_id=p.company_id AND x.payment_key=p.request_key)
 UNION ALL
 SELECT 'garage-refund-'||p.id,'maintenance',p.maintenance_id,'Remboursement · '||m.title,COALESCE(m.garage,'Garage'),m.vehicle_id,p.received_on,p.method,p.amount_centimes,'maintenance',p.reference,p.actor_membership_id,p.created_at,p.id::text,true
 FROM maintenance_refunds p JOIN vehicle_maintenance m ON m.company_id=p.company_id AND m.id=p.maintenance_id
 WHERE p.company_id=(SELECT id FROM tenant) AND NOT EXISTS(SELECT 1 FROM maintenance_refund_cancellations x WHERE x.company_id=p.company_id AND x.refund_id=p.id)
 UNION ALL
 SELECT 'loan-'||p.id,'loan',p.loan_id,'Échéance n° '||i.position,l.lender,l.vehicle_id,p.paid_on,p.method,-COALESCE(c.amount_centimes,p.amount_centimes),'loan',p.reference,p.actor_membership_id,p.created_at,concat(p.id,':',c.id),true
 FROM vehicle_loan_payments p JOIN vehicle_loans l ON l.company_id=p.company_id AND l.id=p.loan_id JOIN vehicle_loan_installments i ON i.company_id=p.company_id AND i.loan_id=p.loan_id AND i.id=p.installment_id LEFT JOIN loan_latest c ON c.company_id=p.company_id AND c.payment_key=p.request_key
 WHERE p.company_id=(SELECT id FROM tenant) AND NOT EXISTS(SELECT 1 FROM vehicle_loan_payment_cancellations x WHERE x.company_id=p.company_id AND x.payment_key=p.request_key)
 UNION ALL
 SELECT 'insurance-'||p.id,'incident',p.ticket_id,'Indemnité assurance',d.title,d.vehicle_id,(p.payload->>'received_on')::date,p.payload->>'method',(p.payload->>'amount_centimes')::bigint,'insurance',p.payload->>'reference',p.actor_membership_id,p.created_at,p.id::text,true
 FROM damage_entries p JOIN damage_tickets d ON d.company_id=p.company_id AND d.id=p.ticket_id
 WHERE p.company_id=(SELECT id FROM tenant) AND p.kind='receipt' AND NOT EXISTS(SELECT 1 FROM damage_entries x WHERE x.company_id=p.company_id AND x.ticket_id=p.ticket_id AND x.kind='receipt_void' AND (x.payload->>'entry_id')::bigint=p.id)
)
SELECT f.*, CASE WHEN method IN ('cash','especes') THEN 'cash' WHEN method IN ('transfer','virement') THEN 'bank' WHEN method IN ('card','carte') THEN 'card' WHEN method='carte_bloquee' THEN 'blocked' ELSE 'cheque' END AS channel,
 concat_ws(' ',v.make,v.model,v.plate) AS vehicle,u.name AS actor
FROM flows f LEFT JOIN vehicles v ON v.company_id=(SELECT id FROM tenant) AND v.id=f.vehicle_id
JOIN company_memberships m ON m.company_id=(SELECT id FROM tenant) AND m.id=f.actor_membership_id JOIN users u ON u.id=m.user_id
SQL;
        $base = DB::query()->fromRaw('('.$sql.') AS flows', [Tenant::id()]);
        if (Schema::hasTable('vehicle_purchase_payments')) {
            $purchase = app(VehiclePurchases::class)->currentPayments()
                ->join('vehicle_purchases as vp', function ($j) {
                    $j->on('vp.id', '=', 'pp.purchase_id')->on('vp.company_id', '=', 'pp.company_id');
                })->join('vehicle_loans as l', function ($j) {
                    $j->on('l.id', '=', 'vp.loan_id')->on('l.company_id', '=', 'vp.company_id');
                })->join('vehicles as v', function ($j) {
                    $j->on('v.id', '=', 'l.vehicle_id')->on('v.company_id', '=', 'l.company_id');
                })->join('company_memberships as m', function ($j) {
                    $j->on('m.id', '=', 'pp.actor_membership_id')->on('m.company_id', '=', 'pp.company_id');
                })->join('users as u', 'u.id', '=', 'm.user_id')->where('pp.amount_centimes', '>', 0)
                ->selectRaw("'purchase-'||pp.payment_key AS key,'loan' AS source,l.id AS source_id,'Apport initial · achat voiture' AS label,vp.seller AS party,l.vehicle_id,pp.paid_on AS occurred_on,pp.method,-pp.amount_centimes AS amount_centimes,'purchase' AS category,pp.reference,pp.actor_membership_id,pp.created_at,pp.id::text AS revision,true AS verified,CASE pp.method WHEN 'cash' THEN 'cash' WHEN 'transfer' THEN 'bank' ELSE 'card' END AS channel,concat_ws(' ',v.make,v.model,v.plate) AS vehicle,u.name AS actor");
            $base = $base->unionAll($purchase);
        }
        if (Schema::hasTable('cash_entries')) {
            $manual = DB::query()->fromSub(app(CashRegister::class)->currentEntries(), 'e')->join('company_memberships as m', function ($j) {
                $j->on('m.id', '=', 'e.actor_membership_id')->on('m.company_id', '=', 'e.company_id');
            })->join('users as u', 'u.id', '=', 'm.user_id')->where('e.kind', '<>', 'opening')->where('e.amount_centimes', '>', 0)
                ->selectRaw("'cash-'||e.entry_key AS key,'cash' AS source,e.id AS source_id,CASE e.kind WHEN 'cash_in' THEN 'Apport en caisse' WHEN 'cash_out' THEN 'Retrait de caisse' WHEN 'bank_deposit' THEN 'Versement vers la banque' ELSE 'Retrait depuis la banque' END AS label,e.reason AS party,NULL::bigint AS vehicle_id,e.occurred_on,'cash' AS method,CASE WHEN e.kind IN ('cash_in','bank_withdrawal') THEN e.amount_centimes ELSE -e.amount_centimes END AS amount_centimes,'transfer' AS category,NULL::text AS reference,e.actor_membership_id,e.created_at,e.id::text AS revision,true AS verified,'cash' AS channel,''::text AS vehicle,u.name AS actor");
            $bank = DB::query()->fromSub($manual, 'x')->whereIn('x.label', ['Versement vers la banque', 'Retrait depuis la banque'])->selectRaw("key||'-bank' AS key,source,source_id,label,party,vehicle_id,occurred_on,'transfer' AS method,-amount_centimes AS amount_centimes,category,reference,actor_membership_id,created_at,revision,verified,'bank' AS channel,vehicle,actor");
            $base = $base->unionAll($manual)->unionAll($bank);
        }

        return DB::query()->fromSub($base, 'ledger');
    }
}
