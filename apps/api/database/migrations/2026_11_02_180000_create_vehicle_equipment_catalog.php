<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_equipment_catalog', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('name', 100);
            $t->string('name_key', 100);
            $t->jsonb('labels');
            $t->timestamps();
            $t->unique(['company_id', 'name_key']);
        });
        DB::table('vehicles')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $v) {
                foreach (json_decode($v->custom_equipment ?? '[]', true) ?? [] as $labels) {
                    $name = trim($labels['fr'] ?? $labels['en'] ?? $labels['ar'] ?? $labels['es'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $key = mb_strtolower(preg_replace('/\s+/u', ' ', $name));
                    DB::table('vehicle_equipment_catalog')->insertOrIgnore(['company_id' => $v->company_id, 'name' => $name, 'name_key' => $key, 'labels' => json_encode($labels), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_equipment_catalog');
    }
};
