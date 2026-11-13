<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Límite estructurado de cotización / IRPF de un concepto (p. ej. dietas exentas hasta X € por día) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_conceptos', function (Blueprint $t) {
            $t->decimal('limite_importe', 12, 4)->nullable()->after('limite');
            $t->string('limite_unidad', 4)->nullable()->after('limite_importe'); // dia · mes
        });
    }

    public function down(): void
    {
        Schema::table('convenio_conceptos', function (Blueprint $t) {
            $t->dropColumn(['limite_importe', 'limite_unidad']);
        });
    }
};
