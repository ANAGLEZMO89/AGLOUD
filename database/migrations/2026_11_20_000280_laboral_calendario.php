<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 laboral: condiciones del contrato con fecha de efecto (histórico), ausencias del calendario revisadas para la nómina
 * (las del control horario entran como propuesta) y horas por cantidad (extra, complementarias o de un concepto del convenio).
 * Las ausencias que ya existían quedan como propuesta: no cambian ninguna nómina hasta que se revisan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajador_condiciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->date('efectos_desde');
            $t->text('datos'); // cifrado: salario, jornada, categoría, grupo, contrato, pagas, días de trabajo
            $t->string('motivo', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['trabajador_id', 'efectos_desde']);
        });

        Schema::table('jornada_ausencias', function (Blueprint $t) {
            $t->string('estado', 12)->default('propuesta')->after('hasta'); // propuesta | confirmada | descartada
            $t->string('origen', 20)->default('control_horario')->after('estado'); // control_horario | nomina
            $t->string('contingencia', 4)->nullable()->after('origen'); // IT: cc (común) | at (profesional)
            $t->boolean('hospitalizacion')->default(false)->after('contingencia');
            $t->decimal('base_reguladora', 10, 4)->nullable()->after('hospitalizacion'); // diaria; vacía = se toma de la nómina anterior
            $t->string('nota', 255)->nullable()->after('base_reguladora');
            $t->foreignId('revisada_por')->nullable()->after('nota')->constrained('users')->nullOnDelete();
            $t->timestamp('revisada_at')->nullable()->after('revisada_por');
        });

        Schema::create('laboral_horas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('trabajador_id')->constrained('trabajadores')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('tipo', 20); // extra | complementaria | concepto
            $t->decimal('horas', 6, 2);
            $t->string('concepto', 150)->nullable(); // nombre del concepto del convenio (por hora) cuando tipo = concepto
            $t->string('origen', 20)->default('manual'); // manual | control_horario
            $t->string('nota', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['trabajador_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboral_horas');
        Schema::table('jornada_ausencias', function (Blueprint $t) {
            $t->dropConstrainedForeignId('revisada_por');
            $t->dropColumn(['estado', 'origen', 'contingencia', 'hospitalizacion', 'base_reguladora', 'nota', 'revisada_at']);
        });
        Schema::dropIfExists('trabajador_condiciones');
    }
};
