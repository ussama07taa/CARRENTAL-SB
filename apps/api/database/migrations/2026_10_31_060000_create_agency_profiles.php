<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('agency_profiles', function (Blueprint $t): void {
   $t->unsignedBigInteger('company_id')->primary();
   $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
   $t->jsonb('details');
   $t->text('logo_base64')->nullable();
   $t->string('logo_mime', 30)->nullable();
   $t->timestampsTz();
  });
 }
 public function down(): void { Schema::dropIfExists('agency_profiles'); }
};
