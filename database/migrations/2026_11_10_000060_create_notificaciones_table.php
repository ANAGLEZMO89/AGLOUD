<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('organismo', 10)->index();             // AEAT, TGSS, DGT…
            $table->string('canal', 30);                          // Sede AEAT, DEHú…
            $table->string('tipo', 160);                          // Requerimiento de información…
            $table->string('asunto', 255)->nullable();
            $table->string('referencia', 80)->nullable();         // CSV / n.º de notificación
            $table->date('puesta');                               // puesta a disposición
            $table->string('estado', 12)->default('Pendiente')->index(); // Pendiente | Aceptada | Rechazada
            $table->boolean('caducada')->default(false);          // rechazada por los 10 días (art. 43.2 Ley 39/2015)
            $table->date('acceso')->nullable();                   // fecha de apertura o rechazo
            $table->unsignedSmallInteger('plazo_n')->default(0);
            $table->char('plazo_tipo', 1)->default('n');          // h = hábiles · n = naturales · m = meses
            $table->string('accion', 120)->nullable();            // Alegaciones, Recurso…
            $table->date('vence')->nullable()->index();           // fin del plazo para actuar
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('nota')->nullable();                     // cifrada
            $table->boolean('archivada')->default(false)->index();
            $table->foreignId('documento_id')->nullable()->constrained('documentos')->nullOnDelete();
            $table->foreignId('expediente_id')->nullable()->constrained('expedientes')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
