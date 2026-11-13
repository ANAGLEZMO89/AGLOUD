<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Control horario (art. 34.9 ET): fichajes diarios, correcciones trazadas y ausencias de los trabajadores. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jornada_dias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->date('fecha');
            $t->json('tramos'); // [{in:"08:00", out:"14:00"|null, p:bool}]
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['trabajador_id', 'fecha']);
            $t->index(['cliente_id', 'fecha']);
        });

        Schema::create('jornada_correcciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('antes', 255)->nullable();
            $t->string('despues', 255)->nullable();
            $t->string('motivo', 255);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['trabajador_id', 'fecha']);
        });

        Schema::create('jornada_ausencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->string('tipo', 40);
            $t->date('desde');
            $t->date('hasta');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['cliente_id', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jornada_ausencias');
        Schema::dropIfExists('jornada_correcciones');
        Schema::dropIfExists('jornada_dias');
    }
};
