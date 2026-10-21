<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_pickups', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedBigInteger('vehicle_id');
            $t->unsignedBigInteger('actor_membership_id');
            $t->unsignedBigInteger('inspection_id');
            $t->unsignedBigInteger('contract_id');
            $t->unsignedBigInteger('signed_file_id');
            $t->timestampTz('picked_up_at');
            $t->string('reason', 1000);
            $t->jsonb('snapshot');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'reservation_id']);
            $t->unique(['company_id', 'request_key']);
            foreach (['reservation_id' => 'reservations', 'vehicle_id' => 'vehicles', 'actor_membership_id' => 'company_memberships'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->foreign(['company_id', 'reservation_id', 'inspection_id'], 'pickup_inspection_fk')->references(['company_id', 'reservation_id', 'id'])->on('rental_departure_inspections')->restrictOnDelete();
            $t->foreign(['company_id', 'reservation_id', 'contract_id', 'signed_file_id'], 'pickup_signed_file_fk')->references(['company_id', 'reservation_id', 'contract_id', 'id'])->on('rental_contract_files')->restrictOnDelete();
        });
        DB::unprepared('CREATE TRIGGER rental_pickups_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON rental_pickups FOR EACH STATEMENT EXECUTE FUNCTION reject_reservation_event_mutation();');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_hold_no_overlap, DROP CONSTRAINT reservation_request_status, DROP CONSTRAINT reservation_confirmed_fields');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee','en_location')), ADD CONSTRAINT reservation_confirmed_fields CHECK(status NOT IN ('confirmee','en_location') OR (confirmed_at IS NOT NULL AND frozen_total_centimes IS NOT NULL AND frozen_total_centimes>0 AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND blocked_until>=ends_at))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_hold_no_overlap EXCLUDE USING gist(company_id WITH =,vehicle_id WITH =,tstzrange(starts_at,blocked_until,'[)') WITH &&) WHERE(status IN ('temporaire','confirmee','en_location'))");
        DB::statement("CREATE UNIQUE INDEX rental_vehicle_one_out ON reservations(company_id,vehicle_id) WHERE(status='en_location')");
    }

    public function down(): void
    {
        if (DB::table('rental_pickups')->exists() || DB::table('reservations')->where('status', 'en_location')->exists()) {
            throw new RuntimeException('Conserver les départs enregistrés.');
        }
        DB::statement('DROP INDEX rental_vehicle_one_out');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservation_hold_no_overlap, DROP CONSTRAINT reservation_request_status, DROP CONSTRAINT reservation_confirmed_fields');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_request_status CHECK(status IN ('demande','temporaire','confirmee')),ADD CONSTRAINT reservation_confirmed_fields CHECK(status<>'confirmee' OR (confirmed_at IS NOT NULL AND frozen_total_centimes IS NOT NULL AND frozen_total_centimes>0 AND blocked_until IS NOT NULL AND preparation_minutes IS NOT NULL AND blocked_until>=ends_at))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_hold_no_overlap EXCLUDE USING gist(company_id WITH =,vehicle_id WITH =,tstzrange(starts_at,blocked_until,'[)') WITH &&) WHERE(status IN ('temporaire','confirmee'))");
        Schema::dropIfExists('rental_pickups');
    }
};
