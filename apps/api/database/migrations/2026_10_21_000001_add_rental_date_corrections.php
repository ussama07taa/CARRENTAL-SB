<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_date_corrections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->timestampTz('old_starts_at');
            $t->timestampTz('old_ends_at');
            $t->timestampTz('new_starts_at');
            $t->timestampTz('new_ends_at');
            $t->timestampTz('new_blocked_until');
            $t->text('reason');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'reservation_id', 'id']);
            $t->foreign(['company_id', 'reservation_id'])->references(['company_id', 'id'])->on('reservations');
            $t->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('vehicles');
            $t->foreign(['company_id', 'actor_membership_id'])->references(['company_id', 'id'])->on('company_memberships');
            $t->foreign(['company_id', 'reservation_id', 'previous_id'], 'date_correction_previous_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_date_corrections');
        });
        Schema::table('reservations', function (Blueprint $t): void {
            $t->unsignedBigInteger('active_date_correction_id')->nullable();
            $t->foreign(['company_id', 'id', 'active_date_correction_id'], 'reservation_date_correction_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_date_corrections');
        });
        DB::statement('ALTER TABLE rental_date_corrections ADD CHECK (new_ends_at > new_starts_at AND new_ends_at-new_starts_at=old_ends_at-old_starts_at)');
        DB::unprepared('CREATE TRIGGER rental_date_corrections_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_date_corrections FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation()');
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_confirmed_rental()'::regprocedure) AS definition")->definition;
        $anchor = '  IF OLD.confirmed_at IS NOT NULL THEN';
        if (substr_count($definition, $anchor) !== 1) {
            throw new RuntimeException('Protection des réservations inattendue. Migration interrompue.');
        }
        $guard = <<<'SQL'
  IF NEW.active_date_correction_id IS DISTINCT FROM OLD.active_date_correction_id THEN
   IF OLD.status <> 'confirmee' OR NEW.status <> OLD.status OR OLD.active_swap_id IS NOT NULL OR OLD.active_extension_id IS NOT NULL
    OR (NEW.frozen_total_centimes,NEW.confirmed_at,NEW.vehicle_id,NEW.customer_id,NEW.preparation_minutes,NEW.active_swap_id,NEW.active_extension_id,NEW.assignment_starts_at)
       IS DISTINCT FROM (OLD.frozen_total_centimes,OLD.confirmed_at,OLD.vehicle_id,OLD.customer_id,OLD.preparation_minutes,OLD.active_swap_id,OLD.active_extension_id,OLD.assignment_starts_at)
    OR EXISTS(SELECT 1 FROM rental_pickups WHERE company_id=OLD.company_id AND reservation_id=OLD.id)
    OR NOT EXISTS(SELECT 1 FROM rental_date_corrections c JOIN company_memberships m ON m.company_id=c.company_id AND m.id=c.actor_membership_id
      WHERE c.company_id=OLD.company_id AND c.reservation_id=OLD.id AND c.id=NEW.active_date_correction_id AND m.is_primary_responsible
      AND c.previous_id IS NOT DISTINCT FROM OLD.active_date_correction_id AND c.vehicle_id=OLD.vehicle_id
      AND c.old_starts_at=OLD.starts_at AND c.old_ends_at=OLD.ends_at AND c.new_starts_at=NEW.starts_at AND c.new_ends_at=NEW.ends_at
      AND c.new_blocked_until=NEW.blocked_until AND NEW.blocked_until=NEW.ends_at+make_interval(mins=>OLD.preparation_minutes))
   THEN RAISE EXCEPTION 'Correction de dates non autorisée'; END IF;
   RETURN NEW;
  END IF;
SQL;
        DB::unprepared(str_replace($anchor, $guard."\n".$anchor, $definition));
    }

    public function down(): void
    {
        if (DB::table('rental_date_corrections')->exists()) {
            throw new RuntimeException('Conserver les corrections de dates et leur historique.');
        }
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_confirmed_rental()'::regprocedure) AS definition")->definition;
        $start = strpos($definition, '  IF NEW.active_date_correction_id IS DISTINCT FROM OLD.active_date_correction_id THEN');
        $end = strpos($definition, '  IF OLD.confirmed_at IS NOT NULL THEN', $start === false ? 0 : $start);
        if ($start === false || $end === false) {
            throw new RuntimeException('Protection inattendue. Retour de migration interrompu.');
        }
        DB::unprepared(substr($definition, 0, $start).substr($definition, $end));
        Schema::table('reservations', function (Blueprint $t): void {
            $t->dropForeign('reservation_date_correction_fk');
            $t->dropColumn('active_date_correction_id');
        });
        Schema::drop('rental_date_corrections');
    }
};
