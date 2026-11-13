<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proveedores (400) y acreedores (410) de cada empresa
        Schema::create('terceros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('clase', 10)->index();          // proveedor | acreedor
            $table->string('cuenta', 9);                   // 400000001 / 410000001
            $table->string('nombre');
            $table->string('nif', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('cp', 10)->nullable();
            $table->string('municipio', 80)->nullable();
            $table->string('provincia', 40)->nullable();
            $table->string('tipo', 20)->nullable();        // acreedor: Servicios | Profesional | Arrendador
            $table->decimal('retencion', 5, 2)->default(0);
            $table->string('forma_pago', 40)->nullable();
            $table->unsignedSmallInteger('pago_dias')->default(30);
            $table->text('iban')->nullable();              // cifrado
            $table->timestamps();
            $table->unique(['cliente_id', 'cuenta']);
        });

        // Facturas recibidas: de proveedores (compras) y de acreedores (gastos)
        Schema::create('facturas_recibidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('tercero_id')->constrained('terceros')->restrictOnDelete();
            $table->string('clase', 10)->index();          // compra | gasto
            $table->string('num', 60);                     // número de la factura del emisor
            $table->date('fecha');
            $table->string('concepto', 255);
            $table->decimal('base', 12, 2);
            $table->decimal('iva_pct', 5, 2)->default(21);
            $table->decimal('cuota', 12, 2)->default(0);
            $table->decimal('ret_pct', 5, 2)->default(0);
            $table->decimal('retencion', 12, 2)->default(0);
            $table->decimal('total', 12, 2);               // a pagar
            $table->boolean('deducible')->default(true);   // IVA deducible
            $table->string('forma_pago', 40)->nullable();
            $table->date('vencimiento')->nullable()->index();
            $table->date('pagada_at')->nullable();
            $table->foreignId('documento_id')->nullable()->constrained('documentos')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'tercero_id', 'num']);
        });

        // Calendario: citas y eventos internos del despacho
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->index();
            $table->string('hora', 5)->nullable();
            $table->string('tipo', 10)->default('cita');   // cita | interno
            $table->string('titulo', 160);
            $table->string('detalle', 255)->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Tareas del equipo
        Schema::create('tareas', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 200);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha')->nullable()->index();    // fecha límite
            $table->string('prioridad', 6)->default('Media');
            $table->string('repetir', 12)->nullable();     // semanal | mensual | trimestral
            $table->boolean('hecha')->default(false)->index();
            $table->timestamp('hecha_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Registro de jornada (art. 34.9 ET): marcas que no se modifican
        Schema::create('fichajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 10);                    // entrada | pausa | reanuda | salida
            $table->timestamp('momento')->index();
            $table->string('ip', 45)->nullable();
            $table->string('nota', 255)->nullable();       // correcciones aprobadas
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 30);                    // Vacaciones | Asuntos propios | Corrección de fichaje
            $table->date('desde');
            $table->date('hasta');
            $table->string('motivo', 255)->nullable();
            $table->string('estado', 10)->default('Pendiente');
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['solicitudes', 'fichajes', 'tareas', 'eventos', 'facturas_recibidas', 'terceros'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
