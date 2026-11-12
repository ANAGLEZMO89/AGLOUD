<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cuota del cliente: cuenta del despacho donde el cliente ingresa por transferencia (la de cargo SEPA sigue en cuota_cuenta) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->string('cuota_cuenta_despacho', 40)->nullable()->after('cuota_cuenta');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn('cuota_cuenta_despacho');
        });
    }
};
