<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ejercicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->string('estado', 10)->default('Abierto');      // Abierto | Cerrado
            $table->date('bloqueo')->nullable();                   // no se admiten asientos con fecha anterior
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['cliente_id', 'anio']);
        });

        // Subcuentas propias de cada empresa (9 dígitos): clientes 430…, y las que se creen a mano
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->char('codigo', 9);
            $table->string('nombre');
            $table->string('nif', 20)->nullable()->index();
            $table->timestamps();
            $table->unique(['cliente_id', 'codigo']);
        });

        Schema::create('asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('ejercicio_id')->constrained('ejercicios')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->date('fecha')->index();
            $table->string('concepto', 255);
            $table->string('tipo', 30)->default('Manual');        // Manual, Factura emitida, Factura recibida, Anulación, Apertura, Regularización, Cierre
            $table->string('origen', 30)->nullable();              // factura | recibida
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ejercicio_id', 'numero']);
            $table->index(['origen', 'origen_id']);
        });

        Schema::create('apuntes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asiento_id')->constrained('asientos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->char('cuenta', 9)->index();
            $table->string('concepto', 255)->nullable();
            $table->decimal('debe', 14, 2)->default(0);
            $table->decimal('haber', 14, 2)->default(0);
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->unsignedBigInteger('asiento_id')->nullable()->after('huella');
        });
        Schema::table('facturas_recibidas', function (Blueprint $table) {
            $table->unsignedBigInteger('asiento_id')->nullable()->after('documento_id');
        });
    }

    public function down(): void
    {
        Schema::table('facturas_recibidas', fn (Blueprint $t) => $t->dropColumn('asiento_id'));
        Schema::table('facturas', fn (Blueprint $t) => $t->dropColumn('asiento_id'));
        Schema::dropIfExists('apuntes');
        Schema::dropIfExists('asientos');
        Schema::dropIfExists('cuentas');
        Schema::dropIfExists('ejercicios');
    }
};
