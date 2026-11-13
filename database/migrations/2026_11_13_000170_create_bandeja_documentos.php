<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facturas y tiques subidos a la bandeja de Contabilidad pendientes de revisar (OCR) y contabilizar
        Schema::create('bandeja_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $table->string('origen', 30)->default('Subida manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandeja_documentos');
    }
};
