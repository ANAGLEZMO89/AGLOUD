<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Laboral: datos AFI del mes (jornadas agrarias y planes de pensiones) y fecha de la última remesa de nóminas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboral_meses', function (Blueprint $t) {
            $t->text('afi')->nullable()->after('incidencias');
            $t->timestamp('remesa_at')->nullable()->after('ss_confirmada_at');
        });
    }

    public function down(): void
    {
        Schema::table('laboral_meses', function (Blueprint $t) {
            $t->dropColumn(['afi', 'remesa_at']);
        });
    }
};
