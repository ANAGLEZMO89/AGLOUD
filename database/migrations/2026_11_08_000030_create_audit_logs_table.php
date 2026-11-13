<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de accesos y acciones (RGPD). Solo se inserta: el modelo
        // impide modificar o borrar filas desde la aplicación.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_email')->nullable();        // se conserva aunque se borre el usuario
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('accion', 60)->index();           // login, documento.descarga…
            $table->string('descripcion', 500);
            $table->string('objeto_tipo', 60)->nullable();
            $table->unsignedBigInteger('objeto_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
