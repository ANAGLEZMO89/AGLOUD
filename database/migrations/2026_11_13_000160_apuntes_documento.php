<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nº de documento de cada apunte y justificante (factura o nómina) adjunto al asiento
        Schema::table('apuntes', function (Blueprint $table) {
            $table->string('documento', 40)->nullable()->after('concepto');
        });
        Schema::table('asientos', function (Blueprint $table) {
            $table->foreignId('documento_id')->nullable()->after('origen_id')->constrained('documentos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apuntes', fn (Blueprint $t) => $t->dropColumn('documento'));
        Schema::table('asientos', fn (Blueprint $t) => $t->dropConstrainedForeignId('documento_id'));
    }
};
