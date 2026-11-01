<?php

namespace App\Support\Finance;

use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ExpenseReadModel
{
    /** Current values only; histories are loaded when opening a file. */
    public function query(bool $withDocuments = false): Builder
    {
        $sql = <<<'SQL'
WITH tenant AS (SELECT ?::bigint AS id),
versions AS (
 SELECT DISTINCT ON (expense_id) * FROM expense_versions
 WHERE company_id=(SELECT id FROM tenant) ORDER BY expense_id,version DESC
), movements AS (
 SELECT DISTINCT ON (movement_key) * FROM expense_movements
 WHERE company_id=(SELECT id FROM tenant) ORDER BY movement_key,version DESC
), expense_cash AS (
 SELECT expense_id,SUM(amount_centimes) FILTER (WHERE kind='payment') AS gross,
 SUM(amount_centimes) FILTER (WHERE kind='refund') AS refunded FROM movements GROUP BY expense_id
), works AS (
 SELECT DISTINCT ON (item_key) * FROM maintenance_work_items
 WHERE company_id=(SELECT id FROM tenant) ORDER BY item_key,version DESC
), work_totals AS (
 SELECT w.maintenance_id,
 COALESCE(SUM(w.amount_centimes) FILTER (WHERE c.id IS NULL),0) AS cost,
 COALESCE(SUM(w.amount_centimes) FILTER (WHERE c.id IS NULL AND w.cost_status='confirmed'),0) AS confirmed,
 COUNT(*) FILTER (WHERE c.id IS NULL AND w.cost_status='unknown') AS unknown_count,
 COUNT(*) FILTER (WHERE c.id IS NULL AND w.cost_status<>'confirmed')=0 AS final
 FROM works w LEFT JOIN maintenance_cancellations c
 ON c.company_id=w.company_id AND c.maintenance_id=w.maintenance_id AND c.target=w.item_key::text
 GROUP BY w.maintenance_id
), corrections AS (
 SELECT DISTINCT ON (payment_key) * FROM maintenance_payment_corrections
 WHERE company_id=(SELECT id FROM tenant) ORDER BY payment_key,version DESC
), garage_cash AS (
 SELECT p.maintenance_id,SUM(CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE(c.amount_centimes,p.amount_centimes) END) AS gross
 FROM maintenance_payments p LEFT JOIN corrections c ON c.payment_key=p.request_key AND c.company_id=p.company_id
 LEFT JOIN maintenance_payment_cancellations x ON x.payment_key=p.request_key AND x.company_id=p.company_id
 WHERE p.company_id=(SELECT id FROM tenant) GROUP BY p.maintenance_id
), garage_refunds AS (
 SELECT r.maintenance_id,SUM(r.amount_centimes) AS refunded FROM maintenance_refunds r
 WHERE r.company_id=(SELECT id FROM tenant) AND NOT EXISTS
 (SELECT 1 FROM maintenance_refund_cancellations x WHERE x.company_id=r.company_id AND x.refund_id=r.id)
 GROUP BY r.maintenance_id
), current_expenses AS (
 SELECT v.expense_id AS id,'manual'::text AS source,v.title,v.category,v.supplier,v.invoice_reference,
 v.incurred_on AS date,v.due_on,v.cancelled,
 CASE WHEN v.cancelled THEN 0 ELSE v.amount_centimes END::bigint AS cost_centimes,
 CASE WHEN v.cancelled THEN 0 ELSE v.amount_centimes END::bigint AS confirmed_cost_centimes,
 true AS cost_final,0::bigint AS unknown_count,
 COALESCE(c.gross,0)::bigint AS gross_paid_centimes,COALESCE(c.refunded,0)::bigint AS refunded_centimes,
 (SELECT jsonb_agg(jsonb_build_object('vehicle_id',a.vehicle_id,'amount_centimes',a.amount_centimes,
 'vehicle',CASE WHEN car.id IS NULL THEN NULL ELSE jsonb_build_object('id',car.id,'make',car.make,'model',car.model,'plate',car.plate) END) ORDER BY a.id)
 FROM expense_allocations a LEFT JOIN vehicles car ON car.company_id=a.company_id AND car.id=a.vehicle_id
 WHERE a.company_id=v.company_id AND a.version_id=v.id) AS allocations,
 NULL::bigint AS vehicle_id,NULL::jsonb AS vehicle,NULL::jsonb AS odometer
 FROM versions v LEFT JOIN expense_cash c ON c.expense_id=v.expense_id
 UNION ALL
 SELECT m.id,'maintenance',m.title,'maintenance',COALESCE(m.garage,'Garage non renseigné'),NULL,
 m.performed_on,m.payment_due_on,x.id IS NOT NULL,
 CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE(w.cost,m.cost_centimes) END::bigint,
 CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE(w.confirmed,m.cost_centimes) END::bigint,
 CASE WHEN x.id IS NOT NULL THEN true ELSE COALESCE(w.final,true) END,
 CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE(w.unknown_count,0) END::bigint,
 COALESCE(c.gross,0)::bigint,COALESCE(r.refunded,0)::bigint,
 jsonb_build_array(jsonb_build_object('vehicle_id',car.id,'amount_centimes',CASE WHEN x.id IS NOT NULL THEN 0 ELSE COALESCE(w.cost,m.cost_centimes) END,
 'vehicle',jsonb_build_object('id',car.id,'make',car.make,'model',car.model,'plate',car.plate))),
 car.id,jsonb_build_object('id',car.id,'make',car.make,'model',car.model,'plate',car.plate),
 jsonb_build_object('value',car.current_odometer,'date',car.current_odometer_date)
 FROM vehicle_maintenance m JOIN vehicles car ON car.company_id=m.company_id AND car.id=m.vehicle_id
 LEFT JOIN work_totals w ON w.maintenance_id=m.id
 LEFT JOIN maintenance_cancellations x ON x.company_id=m.company_id AND x.maintenance_id=m.id AND x.target='file'
 LEFT JOIN garage_cash c ON c.maintenance_id=m.id LEFT JOIN garage_refunds r ON r.maintenance_id=m.id
 WHERE m.company_id=(SELECT id FROM tenant)
)
SELECT *,source||'-'||id AS key,gross_paid_centimes-refunded_centimes AS paid_centimes,
 GREATEST(0,cost_centimes-gross_paid_centimes+refunded_centimes) AS balance_centimes,
 GREATEST(0,gross_paid_centimes-refunded_centimes-cost_centimes) AS credit_centimes
FROM current_expenses
SQL;

        $query = DB::query()->fromRaw('('.$sql.') AS expense_source', [Tenant::id()]);
        if (! $withDocuments) {
            return $query;
        }
        $query->select('expense_source.*')->selectRaw("CASE WHEN source='manual' THEN EXISTS (SELECT 1 FROM expense_documents d WHERE d.company_id=? AND d.expense_id=expense_source.id) ELSE EXISTS (SELECT 1 FROM vehicle_maintenance m WHERE m.company_id=? AND m.id=expense_source.id AND m.path IS NOT NULL) OR EXISTS (SELECT 1 FROM maintenance_attachments a WHERE a.company_id=? AND a.maintenance_id=expense_source.id) END AS has_document", [Tenant::id(), Tenant::id(), Tenant::id()]);

        return DB::query()->fromSub($query, 'documented_expenses');
    }

    public function row(object $row): array
    {
        $data = (array) $row;
        foreach (['allocations', 'vehicle', 'odometer'] as $key) {
            $data[$key] = $data[$key] === null ? null : json_decode($data[$key], true);
        }
        foreach (['id', 'cost_centimes', 'confirmed_cost_centimes', 'unknown_count', 'gross_paid_centimes', 'refunded_centimes', 'paid_centimes', 'balance_centimes', 'credit_centimes'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        return $data;
    }
}
