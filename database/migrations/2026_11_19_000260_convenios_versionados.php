<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convenios colectivos con versiones, tablas por categoría y conceptos retributivos (fase 2 de laboral).
 *
 * Un convenio es del despacho (se introduce una sola vez) y se asigna a las empresas con fecha de efecto.
 * Los datos que había en laboral_config.datos.conv se copian aquí; el JSON antiguo NO se borra, así que
 * `down()` vuelve exactamente a la situación anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 250);
            $t->string('codigo', 40)->nullable();             // código REGCON
            $t->string('sector', 160)->nullable();
            $t->string('ambito_territorial', 160)->nullable();
            $t->string('ambito_funcional', 500)->nullable();
            $t->string('ambito_personal', 500)->nullable();
            $t->string('publicacion', 250)->nullable();       // boletín y número
            $t->string('url', 500)->nullable();
            $t->date('fecha_publicacion')->nullable();
            $t->string('estado', 12)->default('borrador');    // borrador · revision · aprobado · sustituido
            $t->text('observaciones')->nullable();
            $t->text('no_soportado')->nullable();             // reglas que el motor todavía no interpreta
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('codigo');
        });

        Schema::create('convenio_versiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->string('nombre', 160);                        // p. ej. «Tablas 2026»
            $t->date('vigencia_desde')->nullable();           // vigencia del texto
            $t->date('vigencia_hasta')->nullable();
            $t->date('efectos_desde')->nullable();            // efectos económicos de las tablas
            $t->date('efectos_hasta')->nullable();
            $t->string('estado', 12)->default('borrador');
            $t->string('fuente', 12)->default('manual');      // manual · ia · migracion
            $t->text('reglas')->nullable();                   // JSON: pagas extra, antigüedad, jornada, vacaciones, IT, horas extra, contratación
            $t->text('referencias')->nullable();              // JSON: artículo y página por apartado
            $t->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('aprobada_at')->nullable();
            $t->timestamps();
            $t->index(['convenio_id', 'efectos_desde']);
        });

        Schema::create('convenio_categorias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('convenio_versiones')->cascadeOnDelete();
            $t->string('codigo', 30)->nullable();
            $t->string('nombre', 160);
            $t->string('grupo_profesional', 80)->nullable();  // grupo del convenio (no es el de cotización)
            $t->string('nivel', 40)->nullable();
            $t->unsignedTinyInteger('grupo_cotizacion')->nullable(); // 1 a 11
            $t->string('unidad', 8)->default('mes');         // mes · dia · hora · anio
            $t->decimal('salario_base', 12, 4)->nullable();   // null = no localizado (nunca cero por defecto)
            $t->string('referencia', 120)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
            $t->unique(['version_id', 'nombre']);
        });

        Schema::create('convenio_conceptos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->constrained('convenio_versiones')->cascadeOnDelete();
            $t->string('codigo', 20)->nullable();
            $t->string('nombre', 120);
            $t->string('descripcion', 500)->nullable();
            $t->string('cra', 10)->nullable();
            $t->string('naturaleza', 16)->default('salarial'); // salarial · extrasalarial · especie · mejora_it · deduccion
            $t->string('calculo', 12)->default('fijo');        // fijo · porcentaje · formula
            $t->string('unidad', 16)->default('mes');          // mes · dia_natural · dia_trabajado · dia_efectivo · hora · anio · pago
            $t->decimal('valor', 12, 4)->nullable();           // importe general (si no hay importe por categoría)
            $t->string('base', 30)->nullable();                // base del porcentaje: salario_base…
            $t->string('formula', 500)->nullable();
            $t->boolean('automatico')->default(false);         // sale solo cada mes
            $t->boolean('prop_jornada')->default(true);
            $t->boolean('prop_periodo')->default(true);
            $t->boolean('en_pagas')->default(false);
            $t->string('cotiza', 8)->default('si');            // si · no · limite
            $t->string('tributa', 8)->default('si');           // si · no · limite
            $t->string('limite', 250)->nullable();
            $t->string('redondeo', 12)->default('2d');
            $t->string('referencia', 120)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
            $t->unique(['version_id', 'nombre']);
        });

        Schema::create('convenio_concepto_importes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('concepto_id')->constrained('convenio_conceptos')->cascadeOnDelete();
            $t->foreignId('categoria_id')->constrained('convenio_categorias')->cascadeOnDelete();
            $t->decimal('importe', 12, 4);
            $t->unique(['concepto_id', 'categoria_id']);
        });

        Schema::create('convenio_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->foreignId('version_id')->nullable()->constrained('convenio_versiones')->nullOnDelete();
            $t->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $t->string('tipo', 12)->default('texto');          // texto · anexo · tablas · revision
            $t->string('descripcion', 250)->nullable();
            $t->timestamps();
        });

        Schema::create('empresa_convenios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->string('centro', 160)->nullable();             // null = toda la empresa
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->timestamps();
            $t->index(['cliente_id', 'desde']);
        });

        $this->copiarDatosExistentes();
    }

    /** Copia el convenio de cada empresa (JSON en laboral_config) al nuevo modelo, sin borrar el original */
    private function copiarDatosExistentes(): void
    {
        $num = fn ($v) => is_numeric($v) ? round((float) $v, 4) : null;
        foreach (DB::table('laboral_config')->get() as $fila) {
            try {
                $d = json_decode(decrypt($fila->datos, false), true) ?: [];
            } catch (Throwable) {
                continue;
            }
            $conv = $d['conv'] ?? [];
            $cats = array_values(array_filter((array) ($conv['cats'] ?? []), fn ($x) => is_array($x) && trim((string) ($x['cat'] ?? '')) !== ''));
            if (trim((string) ($conv['nombre'] ?? '')) === '' && ! $cats) {
                continue;
            }
            $ahora = now();
            $cid = DB::table('convenios')->insertGetId([
                'nombre' => mb_substr(trim((string) ($conv['nombre'] ?? '')) ?: 'Convenio sin nombre', 0, 250), 'codigo' => mb_substr((string) ($conv['codigo'] ?? ''), 0, 40) ?: null,
                'ambito_territorial' => mb_substr((string) ($conv['ambito'] ?? ''), 0, 160) ?: null,
                'estado' => $cats ? 'aprobado' : 'borrador', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $reglas = ['jornada' => $conv['jornada'] ?? '', 'vacaciones' => $conv['vacaciones'] ?? '', 'pagas' => $conv['pagas'] ?? '14', 'vigencia' => $conv['vigencia'] ?? '',
                'extras' => $conv['extras'] ?? [], 'antig' => $conv['antig'] ?? null, 'it' => $conv['it'] ?? null, 'hx' => $conv['hx'] ?? null, 'ia' => $conv['ia'] ?? null];
            $vid = DB::table('convenio_versiones')->insertGetId([
                'convenio_id' => $cid, 'nombre' => 'Versión inicial'.(! empty($conv['vigencia']) ? ' ('.$conv['vigencia'].')' : ''),
                'estado' => $cats ? 'aprobado' : 'borrador', 'fuente' => 'migracion', 'reglas' => json_encode($reglas, JSON_UNESCAPED_UNICODE), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $catIds = [];
            foreach ($cats as $i => $x) {
                $nom = mb_substr(trim((string) $x['cat']), 0, 160);
                if (isset($catIds[$nom])) {
                    continue;
                }
                $catIds[$nom] = DB::table('convenio_categorias')->insertGetId([
                    'version_id' => $vid, 'nombre' => $nom, 'grupo_cotizacion' => max(1, min(11, (int) ($x['grupo'] ?? 7))), 'unidad' => ($x['unidad'] ?? 'mes') === 'dia' ? 'dia' : 'mes',
                    'salario_base' => $num($x['base'] ?? null), 'referencia' => mb_substr((string) ($x['art'] ?? ''), 0, 120) ?: null, 'orden' => $i, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
            // El «plus» por categoría pasa a ser un concepto con importe por categoría
            $plus = array_filter($cats, fn ($x) => (float) ($x['plus'] ?? 0) != 0);
            if ($plus) {
                $pid = DB::table('convenio_conceptos')->insertGetId(['version_id' => $vid, 'codigo' => '02', 'nombre' => 'Plus de convenio', 'naturaleza' => 'salarial', 'calculo' => 'fijo',
                    'unidad' => 'mes', 'automatico' => true, 'orden' => 0, 'created_at' => $ahora, 'updated_at' => $ahora]);
                foreach ($plus as $x) {
                    $nom = mb_substr(trim((string) $x['cat']), 0, 160);
                    DB::table('convenio_concepto_importes')->insertOrIgnore(['concepto_id' => $pid, 'categoria_id' => $catIds[$nom], 'importe' => round((float) $x['plus'], 4)]);
                }
            }
            $vistos = ['Plus de convenio' => true];
            foreach (array_values((array) ($conv['pluses'] ?? [])) as $i => $p) {
                $nom = mb_substr(trim((string) ($p['nombre'] ?? '')), 0, 120);
                if ($nom === '' || isset($vistos[$nom])) {
                    continue;
                }
                $vistos[$nom] = true;
                DB::table('convenio_conceptos')->insert(['version_id' => $vid, 'codigo' => '06', 'nombre' => $nom,
                    'naturaleza' => ! empty($p['cotiza']) ? 'salarial' : 'extrasalarial', 'calculo' => ($p['tipo'] ?? '') === 'porcentaje_base' ? 'porcentaje' : 'fijo',
                    'unidad' => in_array($p['unidad'] ?? '', ['mes', 'dia_trabajado', 'dia_natural', 'hora'], true) ? $p['unidad'] : 'mes', 'valor' => $num($p['valor'] ?? null),
                    'base' => ($p['tipo'] ?? '') === 'porcentaje_base' ? 'salario_base' : null, 'automatico' => ! empty($p['auto']),
                    'cotiza' => ! empty($p['cotiza']) ? 'si' : 'no', 'tributa' => ! empty($p['tributa']) ? 'si' : 'no', 'referencia' => mb_substr((string) ($p['art'] ?? ''), 0, 120) ?: null,
                    'orden' => $i + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            DB::table('empresa_convenios')->insert(['cliente_id' => $fila->cliente_id, 'convenio_id' => $cid, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        foreach (['empresa_convenios', 'convenio_documentos', 'convenio_concepto_importes', 'convenio_conceptos', 'convenio_categorias', 'convenio_versiones', 'convenios'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
