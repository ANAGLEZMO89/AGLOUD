<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            // 'gestor' = visible en el gestor documental · 'ficha' = logo/firma de la ficha
            $table->string('ambito', 10)->default('gestor')->index();
            // borrador = subido pero pendiente de «Guardar en el gestor»
            $table->boolean('borrador')->default(false)->index();
            $table->string('carpeta', 40)->index();          // p. ej. fiscal/modelos
            $table->string('tipo', 10)->default('docu');      // fra, nom, mod…
            $table->string('nombre');                        // nombre con la nomenclatura del despacho
            $table->string('nombre_original');
            $table->date('fecha');                           // fecha del documento
            $table->date('caduca')->nullable();              // certificados, DNI…
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano');            // bytes del original
            $table->string('ruta');                          // ruta interna cifrada (fuera de public)
            $table->char('sha256', 64);                      // huella del original
            $table->string('origen', 30)->default('Despacho'); // Despacho | Portal del cliente
            $table->boolean('compartido')->default(false);   // visible en el portal
            $table->boolean('por_revisar')->default(false);  // subido por el cliente
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('eliminado_at')->nullable()->index(); // papelera
            $table->foreignId('eliminado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cliente_id', 'carpeta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};
