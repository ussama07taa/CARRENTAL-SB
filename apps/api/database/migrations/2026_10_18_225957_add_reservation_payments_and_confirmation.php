<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['reservation_quotes', 'rental_payments', 'rental_payment_verifications', 'reservation_confirmations'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('reservation_id');
                $t->unsignedBigInteger('actor_membership_id');
                $t->uuid('request_key');
                $t->string('request_hash', 64);
                $t->timestampTz('created_at');
                $t->unique(['company_id', 'id']);
                $t->unique(['company_id', 'request_key']);
                $t->foreign(['company_id', 'reservation_id'], $name.'_r_fk')->references(['company_id', 'id'])->on('reservations')->restrictOnDelete();
                $t->foreign(['company_id', 'actor_membership_id'], $name.'_a_fk')->references(['company_id', 'id'])->on('company_memberships')->restrictOnDelete();
                if ($name === 'reservation_quotes') {
                    $t->bigInteger('total_centimes');
                    $t->string('reason', 1000);
                } elseif ($name === 'rental_payments') {
                    $t->bigInteger('amount_centimes');
                    $t->string('method', 20);
                    $t->date('received_on');
                    $t->string('reference', 200)->nullable();
                    $t->string('note', 1000)->nullable();
                } elseif ($name === 'rental_payment_verifications') {
                    $t->unsignedBigInteger('payment_id');
                    $t->string('reason', 1000);
                    $t->unique(['company_id', 'payment_id']);
                    $t->foreign(['company_id', 'payment_id'])->references(['company_id', 'id'])->on('rental_payments')->restrictOnDelete();
                } else {
                    $t->unsignedBigInteger('quote_id');
                    $t->jsonb('snapshot');
                    $t->unique(['company_id', 'reservation_id']);
                    $t->foreign(['company_id', 'quote_id'])->references(['company_id', 'id'])->on('reservation_quotes')->restrictOnDelete();
                }
            });
            DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();");
        }
        DB::statement('ALTER TABLE reservation_quotes ADD CHECK (total_centimes BETWEEN 1 AND 99999999999)');
        DB::statement("ALTER TABLE rental_payments ADD CHECK (amount_centimes BETWEEN 1 AND 99999999999), ADD CHECK (method IN ('especes','virement','carte'))");
        Schema::table('reservations', function (Blueprint $t): void {
            $t->timestampTz('confirmed_at')->nullable();
            $t->bigInteger('frozen_total_centimes')->nullable();
        });
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_hold_no_overlap, DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK (status IN ('demande','temporaire','confirmee')), ADD CONSTRAINT reservation_confirmed_fields CHECK (status <> 'confirmee' OR (confirmed_at IS NOT NULL AND frozen_total_centimes IS NOT NULL AND frozen_total_centimes > 0 AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND blocked_until >= ends_at))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_hold_no_overlap EXCLUDE USING gist (company_id WITH =, vehicle_id WITH =, tstzrange(starts_at,blocked_until,'[)') WITH &&) WHERE (status IN ('temporaire','confirmee'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_confirmed_rental() RETURNS trigger AS $$ BEGIN
 IF TG_TABLE_NAME = 'reservation_quotes' THEN
  PERFORM 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM reservations WHERE company_id=NEW.company_id AND id=NEW.reservation_id AND confirmed_at IS NOT NULL) THEN RAISE EXCEPTION 'Le prix confirmé est figé'; END IF;
 ELSE
  IF OLD.confirmed_at IS NOT NULL AND (NEW.frozen_total_centimes,NEW.confirmed_at,NEW.starts_at,NEW.ends_at,NEW.vehicle_id,NEW.customer_id,NEW.blocked_until,NEW.preparation_minutes) IS DISTINCT FROM (OLD.frozen_total_centimes,OLD.confirmed_at,OLD.starts_at,OLD.ends_at,OLD.vehicle_id,OLD.customer_id,OLD.blocked_until,OLD.preparation_minutes) THEN RAISE EXCEPTION 'Accord confirmé immuable'; END IF;
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER confirmed_rental_frozen BEFORE UPDATE ON reservations FOR EACH ROW EXECUTE FUNCTION protect_confirmed_rental();
CREATE TRIGGER confirmed_quote_frozen BEFORE INSERT ON reservation_quotes FOR EACH ROW EXECUTE FUNCTION protect_confirmed_rental();
SQL);
    }

    public function down(): void
    {
        if (DB::table('reservation_confirmations')->exists() || DB::table('rental_payments')->exists()) {
            throw new RuntimeException('Des accords ou reçus existent : conserver cette mise à jour.');
        }
        DB::unprepared('DROP TRIGGER confirmed_rental_frozen ON reservations; DROP TRIGGER confirmed_quote_frozen ON reservation_quotes; DROP FUNCTION protect_confirmed_rental();');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_confirmed_fields, DROP CONSTRAINT reservation_hold_no_overlap, DROP CONSTRAINT reservation_request_status');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK (status IN ('demande','temporaire'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_hold_no_overlap EXCLUDE USING gist (company_id WITH =, vehicle_id WITH =, tstzrange(starts_at,blocked_until,'[)') WITH &&) WHERE (status='temporaire')");
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn(['confirmed_at', 'frozen_total_centimes']));
        foreach (['reservation_confirmations', 'rental_payment_verifications', 'rental_payments', 'reservation_quotes'] as $name) {
            Schema::drop($name);
        }
    }
};
