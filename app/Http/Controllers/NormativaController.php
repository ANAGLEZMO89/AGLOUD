<?php

namespace App\Http\Controllers;

use App\Models\ParametroNormativo;
use App\Services\Auditoria;
use App\Services\Nomina;
use App\Services\Normativa;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Parámetros normativos de nómina (comunes a todo el despacho): consulta, nuevo periodo, corrección y verificación en el BOE.
 * Solo la administración del despacho los cambia; cada cambio queda auditado y vuelve a «pendiente de verificar» salvo que se marque verificado.
 */
class NormativaController extends LaboralController
{
    public function lista(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }

        return view('laboral.normativa', $this->base($request, $c, 'g-norm') + [
            'params' => ParametroNormativo::with('editor')->orderBy('clave')->orderByDesc('desde')->get()->groupBy('clave'),
            'admin' => $request->user()->esAdmin(),
            'edit' => (int) $request->query('editar'),
        ]);
    }

    /** Nuevo periodo copiando el más reciente (p. ej. al publicarse la Orden de cotización del año siguiente) */
    public function nuevo(Request $request)
    {
        abort_unless($request->user()->esAdmin(), 403);
        $d = $request->validate(['clave' => ['required', 'in:'.implode(',', array_keys(ParametroNormativo::CLAVES))], 'desde' => ['required', 'date_format:Y-m-d']],
            ['desde.*' => 'Indica desde qué fecha tienen efecto.']);
        if (ParametroNormativo::where('clave', $d['clave'])->whereDate('desde', $d['desde'])->exists()) {
            return back()->withErrors(['norm' => 'Ya hay un periodo de «'.ParametroNormativo::CLAVES[$d['clave']].'» desde esa fecha.']);
        }
        $ult = ParametroNormativo::where('clave', $d['clave'])->whereDate('desde', '<', $d['desde'])->orderByDesc('desde')->first();
        abort_unless($ult, 422);
        if (! $ult->hasta || $ult->hasta->toDateString() >= $d['desde']) {
            $ult->update(['hasta' => Carbon::parse($d['desde'])->subDay()->toDateString()]);
        }
        $n = ParametroNormativo::create(['clave' => $d['clave'], 'desde' => $d['desde'], 'valor' => $ult->valor, 'fuente' => 'PENDIENTE: indica la norma (BOE) que fija estos valores',
            'estado' => 'pendiente', 'notas' => 'Copiado del periodo anterior: actualiza las cifras con la norma del nuevo periodo.', 'updated_by' => $request->user()->id]);
        Normativa::olvidar();
        Auditoria::registrar('laboral.normativa', 'Nuevo periodo de '.ParametroNormativo::CLAVES[$d['clave']].' desde '.Formato::fecha($d['desde']).' (copia del anterior, pendiente de actualizar)');

        return redirect()->route('laboral.normativa', ['editar' => $n->id])->with('ok', 'Periodo creado con las cifras del anterior: actualízalas con la norma nueva.');
    }

    public function guardarParametro(Request $request, ParametroNormativo $parametro)
    {
        abort_unless($request->user()->esAdmin(), 403);
        $num = fn ($v) => trim((string) $v) === '' ? null : round(Formato::parseNum((string) $v), 6);
        $pct = fn ($v) => $num($v) === null ? null : round($num($v) / 100, 6);
        $in = $request->input('v', []);
        $err = [];
        $req = function ($v, $campo) use (&$err) {
            if ($v === null || $v < 0) {
                $err["v.$campo"] = 'Dato obligatorio y no negativo (no se pone a cero por defecto).';
            }

            return $v;
        };
        $valor = match ($parametro->clave) {
            'cotizacion' => [
                'base_max' => $req($num($in['base_max'] ?? null), 'base_max'),
                'base_min' => collect(range(1, 7))->mapWithKeys(fn ($g) => [(string) $g => $req($num($in['base_min'][$g] ?? null), "base_min.$g")])->all(),
                'base_min_dia' => collect(range(8, 11))->mapWithKeys(fn ($g) => [(string) $g => $req($num($in['base_min_dia'][$g] ?? null), "base_min_dia.$g")])->all(),
                'tipos' => collect(['cc', 'mei', 'des_ind', 'des_tmp', 'fp', 'fog'])->mapWithKeys(fn ($k) => [$k => [$req($pct($in['tipos'][$k][0] ?? null), "tipos.$k.0"), $req($pct($in['tipos'][$k][1] ?? null), "tipos.$k.1")]])->all(),
                'solidaridad' => [[0.10, $req($num($in['sol'][0] ?? null), 'sol.0')], [0.50, $req($num($in['sol'][1] ?? null), 'sol.1')], [null, $req($num($in['sol'][2] ?? null), 'sol.2')]],
            ],
            'smi' => ['anual' => $req($num($in['anual'] ?? null), 'anual'), 'mensual' => $req($num($in['mensual'] ?? null), 'mensual'), 'diario' => $req($num($in['diario'] ?? null), 'diario')],
            'irpf' => [
                'escala' => collect(range(0, 5))->map(fn ($i) => [$i < 5 ? $req($num($in['escala'][$i][0] ?? null), "escala.$i.0") : null, $req($num($in['escala'][$i][1] ?? null), "escala.$i.1")])->all(),
                'limites' => collect(['1', '2', '3'])->mapWithKeys(fn ($s) => [$s => collect(range(0, 2))->map(fn ($h) => $req($num($in['limites'][$s][$h] ?? null), "limites.$s.$h"))->all()])->all(),
                'reduccion' => collect(['max', 'r1', 'f1', 'r2', 'b2', 'f2', 'r3'])->mapWithKeys(fn ($k) => [$k => $req($num($in['reduccion'][$k] ?? null), "reduccion.$k")])->all(),
                'tipo_min_temporal' => $req($num($in['tipo_min_temporal'] ?? null), 'tipo_min_temporal'),
            ],
            'it' => [
                'cc' => collect(range(0, 2))->map(fn ($i) => [(int) $req($num($in['cc'][$i][0] ?? null), "cc.$i.0"), ($h = $num($in['cc'][$i][1] ?? null)) === null ? null : (int) $h,
                    $req($num($in['cc'][$i][2] ?? null), "cc.$i.2"), ($in['cc'][$i][3] ?? '') === 'empresa' ? 'empresa' : 'delegado'])->all(),
                'at' => $req($num($in['at'] ?? null), 'at'),
            ],
        };
        $fuente = trim((string) $request->input('fuente'));
        if (mb_strlen($fuente) < 10 || str_starts_with($fuente, 'PENDIENTE')) {
            $err['fuente'] = 'Indica la norma que fija estos valores (BOE, número y fecha).';
        }
        if ($err) {
            return redirect()->route('laboral.normativa', ['editar' => $parametro->id])->withInput()->withErrors($err + ['norm' => 'No se ha guardado: revisa los campos marcados.']);
        }
        $verif = $request->boolean('verificado');
        $antes = $parametro->valor;
        $parametro->update(['valor' => $valor, 'fuente' => mb_substr($fuente, 0, 500), 'notas' => mb_substr((string) $request->input('notas'), 0, 2000) ?: null,
            'estado' => $verif ? 'verificado' : ($antes === $valor && $parametro->estado !== 'pendiente' ? $parametro->estado : 'pendiente'),
            'verificado_at' => $verif ? now() : ($antes === $valor ? $parametro->verificado_at : null), 'updated_by' => $request->user()->id]);
        Normativa::olvidar();
        Nomina::olvidarGuardadas();
        Auditoria::registrar('laboral.normativa', ParametroNormativo::CLAVES[$parametro->clave].' desde '.Formato::fecha($parametro->desde).' '.($antes === $valor ? 'sin cambios en las cifras' : 'modificado')
            .($verif ? ' y marcado como verificado en el BOE por '.$request->user()->name : ''));

        return redirect()->route('laboral.normativa')->with('ok', 'Parámetros guardados'.($verif ? ' y marcados como verificados en el BOE.' : '. Las nóminas emitidas no cambian; las no emitidas se recalculan con estos valores.'));
    }
}
