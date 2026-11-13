<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('ref', 20)->unique();                 // EXP-2026-0001
            $table->string('tramite', 120);                       // plantilla («Constitución de SL»…)
            $table->string('categoria', 40)->index();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('Abierto')->index();
            $table->date('apertura');
            $table->date('plazo')->nullable()->index();           // fecha límite
            $table->string('plazo_lbl', 160)->nullable();         // «Respuesta al requerimiento»
            $table->json('pasos');                                // [{l, ok}]
            $table->json('docs');                                 // [{l, ok}]
            $table->decimal('honorarios', 10, 2)->default(0);     // sin IVA
            $table->boolean('portal')->default(true);             // visible en el portal del cliente
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expediente_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expediente_id')->constrained('expedientes')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('nota')->default(false);              // true = nota interna escrita a mano
            $table->text('texto');                                // cifrado
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expediente_eventos');
        Schema::dropIfExists('expedientes');
    }
};
