<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parámetros fiscales de cada empresa (régimen de IVA, retenciones, IS…). Cifrados: llevan IBAN.
        Schema::create('fiscal_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();
            $table->text('datos');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Borradores de los modelos y marca de presentado (la presentación se hace en la Sede de la AEAT)
        Schema::create('fiscal_modelos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('modelo', 3);
            $table->unsignedSmallInteger('anio');
            $table->string('periodo', 4);                 // 1T…4T, 1P…3P, 0A (anual), E (sin periodo)
            $table->json('casillas')->nullable();         // importes corregidos a mano {casilla: valor}
            $table->string('tipo_decl', 1)->nullable();   // I, U, D, C, N
            $table->timestamp('borrador_at')->nullable();
            $table->date('presentado_at')->nullable();
            $table->foreignId('presentado_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'modelo', 'anio', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_modelos');
        Schema::dropIfExists('fiscal_config');
    }
};
