<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tarifa del cliente: nombre, conceptos que se facturan cada periodo y servicios incluidos
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('cuota_tarifa', 120)->nullable()->after('cuota_importe');
            $table->json('cuota_lineas')->nullable()->after('cuota_tarifa');
            $table->text('cuota_incluye')->nullable()->after('cuota_lineas');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['cuota_tarifa', 'cuota_lineas', 'cuota_incluye']);
        });
    }
};
