<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resultado de la verificación del NIF en la AEAT (servicio VNifV2)
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nif_aeat', 30)->nullable()->after('nif');
            $table->string('nif_aeat_nombre', 200)->nullable()->after('nif_aeat');
            $table->timestamp('nif_aeat_at')->nullable()->after('nif_aeat_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['nif_aeat', 'nif_aeat_nombre', 'nif_aeat_at']);
        });
    }
};
