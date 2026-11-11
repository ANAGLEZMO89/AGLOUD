<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Al guardar el alta el cliente ya queda activo: las «altas en curso» pasan a «Activo»
        DB::table('clientes')->where('estado', 'Alta en curso')->update(['estado' => 'Activo']);
    }

    public function down(): void {}
};
