<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nómina de un trabajador y mes editada en el Integral de nóminas (borrador o emitida). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nominas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->unsignedTinyInteger('mes');
            $t->string('recibo', 20)->default('Nómina');
            $t->string('estado', 12)->default('Borrador'); // Borrador · Emitida
            $t->date('fecha_pago')->nullable();
            $t->text('datos'); // conceptos, deducciones y tipos (cifrado)
            $t->timestamp('emitida_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['trabajador_id', 'anio', 'mes', 'recibo']);
            $t->index(['cliente_id', 'anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nominas');
    }
};
