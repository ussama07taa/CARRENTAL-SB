<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_equipment_catalog', fn (Blueprint $t) => $t->timestampTz('archived_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('vehicle_equipment_catalog', fn (Blueprint $t) => $t->dropColumn('archived_at'));
    }
};
