<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PGC por empresa: una cuenta del plan se puede dar de baja (oculta) y las subcuentas bancarias se ligan a su IBAN
        Schema::table('cuentas', function (Blueprint $table) {
            $table->boolean('oculta')->default(false)->after('nif');
            $table->string('iban', 34)->nullable()->after('oculta');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropColumn(['oculta', 'iban']);
        });
    }
};
