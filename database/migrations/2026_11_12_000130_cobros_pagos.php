<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobro de las facturas emitidas y pago de las recibidas: medio (banco/caja) y su asiento contable
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('cobro_medio', 10)->nullable()->after('cobrada_at');
            $table->foreignId('cobro_asiento_id')->nullable()->after('cobro_medio')->constrained('asientos')->nullOnDelete();
        });
        Schema::table('facturas_recibidas', function (Blueprint $table) {
            $table->string('pago_medio', 10)->nullable()->after('pagada_at');
            $table->foreignId('pago_asiento_id')->nullable()->after('pago_medio')->constrained('asientos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cobro_asiento_id');
            $table->dropColumn('cobro_medio');
        });
        Schema::table('facturas_recibidas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pago_asiento_id');
            $table->dropColumn('pago_medio');
        });
    }
};
