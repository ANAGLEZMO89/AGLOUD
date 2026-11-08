<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            // Autónomo | Sociedad | Comunidad de bienes | Particular
            $table->string('tipo', 30)->index();
            // El propio despacho (GA AGOPYME) es un entorno más, marcado aquí
            $table->boolean('es_despacho')->default(false)->index();
            $table->string('nombre');
            $table->string('nif', 15)->nullable()->unique();
            $table->string('comercial')->nullable();
            $table->date('fecha_alta')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->foreignId('asesor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('domicilio')->nullable();
            $table->string('cp', 10)->nullable();
            $table->string('municipio')->nullable();
            $table->string('provincia', 60)->nullable();
            $table->string('actividad')->nullable();
            // Activo | Alta en curso | Baja
            $table->string('estado', 20)->default('Alta en curso')->index();

            // Cuota (honorarios del despacho)
            $table->decimal('cuota_importe', 10, 2)->nullable();
            $table->string('cuota_periodicidad', 20)->default('Mensual');
            $table->unsignedTinyInteger('cuota_iva')->default(21);
            $table->date('cuota_inicio')->nullable();
            $table->string('cuota_forma_cobro', 30)->default('Domiciliación SEPA');
            $table->string('cuota_cuenta', 40)->nullable();
            $table->string('cuota_concepto')->default('Honorarios de asesoría');
            $table->text('cuota_observaciones')->nullable();

            // Secciones de alta (bancos, centros, actividades, representantes,
            // datos registrales, certificados). Se guardan CIFRADAS (IBAN,
            // NIF de socios, contraseñas de certificados…).
            $table->longText('datos')->nullable();

            $table->foreignId('logo_documento_id')->nullable();
            $table->foreignId('firma_documento_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
        });
        Schema::dropIfExists('clientes');
    }
};
