<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración de facturación de cada empresa emisora (cliente o despacho)
        Schema::create('facturacion_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();
            $table->string('pie', 500)->nullable();
            $table->json('series');      // [{cod, nombre, tf}]
            $table->json('estilo');      // diseño de la factura
            $table->json('catalogo');    // [{nombre, precio, iva}]
            $table->timestamps();
        });

        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete(); // emisor
            $table->string('serie', 3);
            $table->unsignedInteger('numero');
            $table->string('num', 30);                    // A-2026-0001
            $table->string('tipo', 2);                    // F1, F2, R1
            $table->date('fecha');                        // fecha de expedición
            $table->string('receptor_nombre')->nullable();
            $table->string('receptor_nif', 20)->nullable();
            $table->string('receptor_dir')->nullable();
            $table->json('lineas');                       // [{d, q, p, iva, sup}]
            $table->json('ivas');                         // desglose por tipo
            $table->decimal('ret_pct', 5, 2)->default(0);
            $table->decimal('base', 12, 2);
            $table->decimal('cuota', 12, 2);
            $table->decimal('retencion', 12, 2)->default(0);
            $table->decimal('suplidos', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('forma_pago', 40)->nullable();
            $table->unsignedSmallInteger('pago_dias')->default(0);
            $table->text('iban')->nullable();             // cifrado
            $table->text('iban_emisor')->nullable();      // cifrado
            $table->string('observaciones', 500)->nullable();
            $table->string('estado', 12)->default('Emitida')->index(); // Emitida | Anulada
            $table->boolean('cobrada')->default(false);
            $table->date('cobrada_at')->nullable();
            $table->foreignId('rectifica_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->unsignedBigInteger('presupuesto_id')->nullable()->index();
            $table->char('huella', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'num']);
            $table->index(['cliente_id', 'serie', 'fecha']);
        });

        // Registros de facturación (RD 1007/2023 · Orden HAC/1177/2024): solo se añaden, nunca se modifican
        Schema::create('registros_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->string('tipo', 10);                   // Alta | Anulación
            $table->string('nif_emisor', 20);
            $table->string('num', 30);
            $table->date('fecha');                        // fecha de expedición de la factura
            $table->string('tipo_factura', 2);
            $table->decimal('cuota', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('fecha_hora_huso', 30);        // 2026-11-11T10:20:30+01:00
            $table->char('huella_anterior', 64)->nullable();
            $table->char('huella', 64);
            $table->string('estado', 20)->default('Conservado'); // sin envío a la AEAT
            $table->timestamp('created_at')->useCurrent();
            $table->index(['cliente_id', 'id']);
        });

        // CRM
        Schema::create('crm_oportunidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete(); // empresa que vende
            $table->string('nombre');                     // cliente potencial
            $table->string('nif', 20)->nullable();
            $table->string('titulo');                     // qué se le ofrece
            $table->decimal('importe', 12, 2)->default(0);
            $table->string('etapa', 30)->default('Nuevo')->index();
            $table->date('fecha');                        // cierre previsto / real
            $table->string('origen', 30)->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('presupuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('oportunidad_id')->nullable()->constrained('crm_oportunidades')->nullOnDelete();
            $table->string('num', 30);                    // P-2026-0001
            $table->string('receptor_nombre');
            $table->string('receptor_nif', 20)->nullable();
            $table->date('fecha');
            $table->unsignedSmallInteger('validez')->default(30);
            $table->string('estado', 12)->default('Borrador')->index();
            $table->json('lineas');
            $table->decimal('base', 12, 2);
            $table->decimal('cuota', 12, 2);
            $table->decimal('total', 12, 2);
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cliente_id', 'num']);
        });

        Schema::create('crm_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('oportunidad_id')->nullable()->constrained('crm_oportunidades')->nullOnDelete();
            $table->string('tipo', 20);
            $table->string('texto', 255);
            $table->date('fecha');
            $table->string('hora', 5)->nullable();
            $table->boolean('hecha')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_actividades');
        Schema::dropIfExists('presupuestos');
        Schema::dropIfExists('crm_oportunidades');
        Schema::dropIfExists('registros_facturacion');
        Schema::dropIfExists('facturas');
        Schema::dropIfExists('facturacion_config');
    }
};
