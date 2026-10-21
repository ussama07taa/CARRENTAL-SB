<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_extension_agreements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('swap_id')->nullable();
            $t->unsignedBigInteger('previous_activation_id')->nullable();
            $t->unsignedBigInteger('pricing_version_id');
            $t->timestampTz('old_ends_at');
            $t->timestampTz('new_ends_at');
            $t->timestampTz('new_blocked_until');
            $t->bigInteger('base_total_centimes');
            $t->bigInteger('amount_centimes');
            $t->string('exception_reason', 1000)->nullable();
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'extension_agreement_parent');
            $t->unique(['company_id', 'request_key']);
            foreach (['reservation_id' => 'reservations', 'actor_membership_id' => 'company_memberships', 'vehicle_id' => 'vehicles', 'pricing_version_id' => 'vehicle_pricing_versions'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->foreign(['company_id', 'reservation_id', 'swap_id'], 'extension_agreement_swap_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_vehicle_swaps')->restrictOnDelete();
        });
        Schema::table('rental_contract_versions', function (Blueprint $t): void {
            $t->unsignedBigInteger('extension_id')->nullable();
            $t->unique(['company_id', 'reservation_id', 'extension_id', 'id'], 'extension_contract_parent');
            $t->foreign(['company_id', 'reservation_id', 'extension_id'], 'contract_extension_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_extension_agreements')->restrictOnDelete();
        });
        Schema::create('rental_extension_activations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('agreement_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('contract_id');
            $t->unsignedBigInteger('signed_file_id');
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id', 'id'], 'extension_activation_parent');
            $t->unique(['company_id', 'agreement_id']);
            $t->unique(['company_id', 'request_key']);
            $t->foreign(['company_id', 'reservation_id', 'agreement_id'], 'extension_activation_agreement_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_extension_agreements')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'agreement_id', 'contract_id'], 'extension_activation_contract_fk')->references(['company_id', 'reservation_id', 'extension_id', 'id'])->on('rental_contract_versions')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'contract_id', 'signed_file_id'], 'extension_activation_file_fk')->references(['company_id', 'reservation_id', 'contract_id', 'id'])->on('rental_contract_files')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
        });
        Schema::table('rental_extension_agreements', fn (Blueprint $t) => $t->foreign(['company_id', 'reservation_id', 'previous_activation_id'], 'extension_agreement_previous_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_extension_activations')->restrictOnDelete());
        Schema::table('reservations', function (Blueprint $t): void {
            $t->unsignedBigInteger('active_extension_id')->nullable();
            $t->foreign(['company_id', 'id', 'active_extension_id'], 'reservation_active_extension_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_extension_activations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE rental_extension_agreements ADD CHECK(new_ends_at > old_ends_at), ADD CHECK(new_blocked_until >= new_ends_at), ADD CHECK(base_total_centimes BETWEEN 0 AND 99999999999), ADD CHECK(amount_centimes BETWEEN 0 AND 99999999999)');
        foreach (['rental_extension_agreements', 'rental_extension_activations'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_confirmed_rental() RETURNS trigger AS $$ BEGIN
 IF TG_TABLE_NAME = 'reservation_quotes' THEN
  PERFORM 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id AND confirmed_at IS NOT NULL) THEN RAISE EXCEPTION 'Le prix confirmé est figé'; END IF;
 ELSE
  IF OLD.confirmed_at IS NOT NULL THEN
   IF (NEW.frozen_total_centimes,NEW.confirmed_at,NEW.starts_at,NEW.customer_id,NEW.preparation_minutes) IS DISTINCT FROM (OLD.frozen_total_centimes,OLD.confirmed_at,OLD.starts_at,OLD.customer_id,OLD.preparation_minutes) THEN RAISE EXCEPTION 'Accord confirmé immuable'; END IF;
   IF (NEW.ends_at,NEW.blocked_until,NEW.active_extension_id) IS DISTINCT FROM (OLD.ends_at,OLD.blocked_until,OLD.active_extension_id) THEN
    IF OLD.status NOT IN ('confirmee','en_location') OR NEW.status <> OLD.status
     OR (NEW.vehicle_id,NEW.active_swap_id,NEW.assignment_starts_at) IS DISTINCT FROM (OLD.vehicle_id,OLD.active_swap_id,OLD.assignment_starts_at)
     OR NOT EXISTS (
      SELECT 1 FROM rental_extension_activations a JOIN rental_extension_agreements e ON e.company_id=a.company_id AND e.id=a.agreement_id
      JOIN rental_contract_verifications v ON v.company_id=a.company_id AND v.contract_id=a.contract_id AND v.file_id=a.signed_file_id
      WHERE a.company_id=OLD.company_id AND a.reservation_id=OLD.id AND a.id=NEW.active_extension_id
      AND e.vehicle_id=OLD.vehicle_id AND e.swap_id IS NOT DISTINCT FROM OLD.active_swap_id
      AND e.previous_activation_id IS NOT DISTINCT FROM OLD.active_extension_id
      AND e.old_ends_at=OLD.ends_at AND e.new_ends_at=NEW.ends_at AND e.new_blocked_until=NEW.blocked_until
     ) THEN RAISE EXCEPTION 'La prolongation nécessite un accord signé et une activation conservée'; END IF;
   END IF;
   IF (NEW.vehicle_id,NEW.active_swap_id,NEW.assignment_starts_at) IS DISTINCT FROM (OLD.vehicle_id,OLD.active_swap_id,OLD.assignment_starts_at) THEN
    IF OLD.status <> 'retournee' OR NEW.status <> 'confirmee' OR NOT EXISTS (
     SELECT 1 FROM rental_vehicle_swaps s JOIN rental_returns r ON r.company_id=s.company_id AND r.id=s.return_id
     JOIN rental_pickups p ON p.company_id=r.company_id AND p.id=r.pickup_id
     WHERE s.company_id=OLD.company_id AND s.reservation_id=OLD.id AND s.id=NEW.active_swap_id
     AND s.from_vehicle_id=OLD.vehicle_id AND s.to_vehicle_id=NEW.vehicle_id AND s.assigned_at=NEW.assignment_starts_at
     AND r.vehicle_id=OLD.vehicle_id AND p.swap_id IS NOT DISTINCT FROM OLD.active_swap_id
     AND s.assigned_at>=r.returned_at AND s.assigned_at>=OLD.starts_at AND s.assigned_at<OLD.ends_at
     AND NOT EXISTS (SELECT 1 FROM rental_pickups used WHERE used.company_id=s.company_id AND used.swap_id=s.id)
    ) THEN RAISE EXCEPTION 'Le changement de voiture nécessite un retour contrôlé et un accord conservé'; END IF;
   END IF;
  END IF;
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        if (DB::table('rental_extension_agreements')->exists()) {
            throw new RuntimeException('Conserver les accords et les activations de prolongation.');
        }
        Schema::table('reservations', function (Blueprint $t): void {
            $t->dropForeign('reservation_active_extension_fk');
            $t->dropColumn('active_extension_id');
        });
        Schema::table('rental_extension_agreements', fn (Blueprint $t) => $t->dropForeign('extension_agreement_previous_fk'));
        Schema::drop('rental_extension_activations');
        Schema::table('rental_contract_versions', function (Blueprint $t): void {
            $t->dropForeign('contract_extension_fk');
            $t->dropUnique('extension_contract_parent');
            $t->dropColumn('extension_id');
        });
        Schema::drop('rental_extension_agreements');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_confirmed_rental() RETURNS trigger AS $$ BEGIN
 IF TG_TABLE_NAME = 'reservation_quotes' THEN
  PERFORM 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id AND confirmed_at IS NOT NULL) THEN RAISE EXCEPTION 'Le prix confirmé est figé'; END IF;
 ELSE
  IF OLD.confirmed_at IS NOT NULL THEN
   IF (NEW.frozen_total_centimes,NEW.confirmed_at,NEW.starts_at,NEW.ends_at,NEW.customer_id,NEW.blocked_until,NEW.preparation_minutes) IS DISTINCT FROM (OLD.frozen_total_centimes,OLD.confirmed_at,OLD.starts_at,OLD.ends_at,OLD.customer_id,OLD.blocked_until,OLD.preparation_minutes) THEN RAISE EXCEPTION 'Accord confirmé immuable'; END IF;
   IF (NEW.vehicle_id,NEW.active_swap_id,NEW.assignment_starts_at) IS DISTINCT FROM (OLD.vehicle_id,OLD.active_swap_id,OLD.assignment_starts_at) THEN
    IF OLD.status <> 'retournee' OR NEW.status <> 'confirmee' OR NOT EXISTS (
     SELECT 1 FROM rental_vehicle_swaps s JOIN rental_returns r ON r.company_id=s.company_id AND r.id=s.return_id
     JOIN rental_pickups p ON p.company_id=r.company_id AND p.id=r.pickup_id
     WHERE s.company_id=OLD.company_id AND s.reservation_id=OLD.id AND s.id=NEW.active_swap_id
     AND s.from_vehicle_id=OLD.vehicle_id AND s.to_vehicle_id=NEW.vehicle_id AND s.assigned_at=NEW.assignment_starts_at
     AND r.vehicle_id=OLD.vehicle_id AND p.swap_id IS NOT DISTINCT FROM OLD.active_swap_id
     AND s.assigned_at>=r.returned_at AND s.assigned_at>=OLD.starts_at AND s.assigned_at<OLD.ends_at
     AND NOT EXISTS (SELECT 1 FROM rental_pickups used WHERE used.company_id=s.company_id AND used.swap_id=s.id)
    ) THEN RAISE EXCEPTION 'Le changement de voiture nécessite un retour contrôlé et un accord conservé'; END IF;
   END IF;
  END IF;
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
SQL);
    }
};
