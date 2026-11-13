<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturacion_config', function (Blueprint $table) {
            // normal = PDF · electronica = además Facturae 3.2.2 · verifactu = acogida a VERI*FACTU
            $table->string('modo', 12)->default('normal')->after('cliente_id');
        });
        Schema::table('facturas', function (Blueprint $table) {
            // Datos para la factura electrónica: dirección desglosada del receptor y códigos DIR3
            $table->json('datos_fe')->nullable()->after('receptor_dir');
            $table->string('modo', 12)->default('normal')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', fn (Blueprint $t) => $t->dropColumn(['datos_fe', 'modo']));
        Schema::table('facturacion_config', fn (Blueprint $t) => $t->dropColumn('modo'));
    }
};
