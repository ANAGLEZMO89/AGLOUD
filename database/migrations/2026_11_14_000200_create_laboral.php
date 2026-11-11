<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Laboral y Seguridad Social: plantilla, movimientos de afiliación, nóminas por mes y parámetros por empresa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajadores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->string('estado', 10)->default('Alta');
            $t->date('alta');
            $t->date('baja')->nullable();
            $t->string('contrato', 3);
            $t->text('datos'); // ficha completa cifrada (datos personales, NIF, NAF, IBAN, 145…)
            $t->timestamps();
            $t->index(['cliente_id', 'estado']);
        });

        Schema::create('laboral_movimientos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('tipo', 12); // Alta · Baja · Variación
            $t->string('detalle')->nullable();
            $t->string('estado', 12)->default('Pendiente'); // Pendiente · Comunicado
            $t->timestamp('comunicado_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['cliente_id', 'estado']);
        });

        Schema::create('laboral_meses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->text('incidencias')->nullable(); // variables del mes por trabajador (cifradas)
            $t->timestamp('ss_confirmada_at')->nullable();
            $t->foreignId('asiento_id')->nullable()->constrained('asientos')->nullOnDelete();
            $t->timestamps();
            $t->unique(['cliente_id', 'anio', 'mes']);
        });

        Schema::create('laboral_config', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();
            $t->text('datos');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboral_config');
        Schema::dropIfExists('laboral_meses');
        Schema::dropIfExists('laboral_movimientos');
        Schema::dropIfExists('trabajadores');
    }
};
