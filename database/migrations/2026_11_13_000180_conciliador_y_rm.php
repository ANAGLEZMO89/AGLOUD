<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Movimientos de los extractos bancarios importados y su conciliación con la contabilidad
        Schema::create('movimientos_banco', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->char('cuenta', 9);                       // subcuenta 572… del banco
            $table->date('fecha');
            $table->string('concepto', 255);
            $table->decimal('importe', 14, 2);               // + ingreso / − cargo
            $table->decimal('saldo', 14, 2)->nullable();
            $table->char('huella', 40);                      // evita importar dos veces el mismo movimiento
            $table->foreignId('apunte_id')->nullable()->constrained('apuntes')->nullOnDelete();
            $table->string('como', 20)->nullable();          // automatica | manual | asiento | cobro | pago
            $table->string('importacion', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'huella']);
            $table->index(['cliente_id', 'cuenta', 'fecha']);
        });

        // Registro Mercantil: estado de plantillas, legalización de libros y depósito de cuentas por ejercicio
        Schema::create('rm_estados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->json('datos')->nullable();
            $table->timestamps();
            $table->unique(['cliente_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rm_estados');
        Schema::dropIfExists('movimientos_banco');
    }
};
