<?php

namespace App\Http\Controllers;

use App\Models\Asiento;
use App\Models\JornadaAusencia;
use App\Models\LaboralHora;
use App\Models\LaboralMes;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Services\Auditoria;
use App\Services\Calendario;
use App\Services\Nomina;
use App\Support\Formato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Calendario e incidencias del trabajador en el Integral de nóminas (fase 5): ausencias confirmadas (vacaciones, permisos, IT…),
 * revisión de las que llegan del control horario, horas por cantidad y propuesta de horas desde los fichajes.
 * Nada se cambia en un mes con la nómina emitida o contabilizada.
 */
class LaboralCalendarioController extends LaboralController
{
    private function volver(Request $r, Trabajador $t): RedirectResponse
    {
        return redirect()->route('laboral.integral', ['anio' => (int) $r->input('anio'), 'mes' => (int) $r->input('mes'), 't' => $t->id, 'vista' => 'calendario']);
    }

    /** Meses bloqueados (nómina emitida o mes contabilizado) entre dos fechas: texto del error o null */
    private function bloqueo(Trabajador $t, string $desde, string $hasta): ?string
    {
        for ($d = Carbon::parse($desde)->startOfMonth(); $d->toDateString() <= $hasta; $d->addMonth()) {
            $n = NominaMes::where('trabajador_id', $t->id)->where('anio', $d->year)->where('mes', $d->month)->where('recibo', 'Nómina')->first();
            if ($n?->emitida()) {
                return 'La nómina de '.mb_strtolower(Nomina::MESES[$d->month - 1]).' de '.$d->year.' está emitida: reábrela antes de cambiar su calendario.';
            }
            $m = LaboralMes::where('cliente_id', $t->cliente_id)->where('anio', $d->year)->where('mes', $d->month)->first();
            if ($m?->asiento_id && Asiento::find($m->asiento_id)) {
                return 'Las nóminas de '.mb_strtolower(Nomina::MESES[$d->month - 1]).' de '.$d->year.' están contabilizadas.';
            }
        }

        return null;
    }

    private function propio(Request $r, Trabajador $t): void
    {
        $c = $this->ctx($r);
        abort_unless($c && $t->cliente_id === $c->id, 404);
    }

    private function datosAusencia(Request $r): array
    {
        $v = validator($r->all(), [
            'contingencia' => ['nullable', Rule::in(array_keys(Calendario::CONTINGENCIAS))],
            'base_reguladora' => ['nullable', 'string', 'max:20'],
            'nota' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $br = trim((string) ($v['base_reguladora'] ?? ''));

        return ['contingencia' => $v['contingencia'] ?? null, 'hospitalizacion' => $r->boolean('hospitalizacion'),
            'base_reguladora' => $br === '' ? null : round(Formato::parseNum($br), 4), 'nota' => $v['nota'] ?? null];
    }

    /** Ausencia registrada desde la nómina: queda confirmada */
    public function ausencia(Request $request, Trabajador $trabajador)
    {
        $this->propio($request, $trabajador);
        $v = validator($request->all(), [
            'tipo' => ['required', Rule::in(array_keys(Calendario::AUSENCIAS))],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], ['tipo.*' => 'Elige el tipo de ausencia.', 'desde.*' => 'Indica la fecha inicial.', 'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.', 'hasta.*' => 'Indica la fecha final.']);
        if ($v->fails()) {
            return $this->volver($request, $trabajador)->withErrors($v)->withInput();
        }
        $d = $v->validated() + $this->datosAusencia($request);
        if ($d['tipo'] === 'Incapacidad temporal' && ! $d['contingencia']) {
            return $this->volver($request, $trabajador)->withErrors(['contingencia' => 'Indica la contingencia de la IT (común o profesional).'])->withInput();
        }
        if ($d['base_reguladora'] !== null && $d['base_reguladora'] <= 0) {
            return $this->volver($request, $trabajador)->withErrors(['base_reguladora' => 'La base reguladora debe ser mayor que cero (o déjala vacía para tomarla de la nómina anterior).'])->withInput();
        }
        if ($e = $this->bloqueo($trabajador, $d['desde'], $d['hasta'])) {
            return $this->volver($request, $trabajador)->withErrors(['cal' => $e])->withInput();
        }
        if (JornadaAusencia::where('trabajador_id', $trabajador->id)->where('estado', '!=', 'descartada')->whereDate('desde', '<=', $d['hasta'])->whereDate('hasta', '>=', $d['desde'])->exists()) {
            return $this->volver($request, $trabajador)->withErrors(['cal' => 'Ya hay otra ausencia en esas fechas (confirmada o pendiente de revisar).'])->withInput();
        }
        JornadaAusencia::create($d + ['cliente_id' => $trabajador->cliente_id, 'trabajador_id' => $trabajador->id, 'estado' => 'confirmada', 'origen' => 'nomina',
            'created_by' => $request->user()->id, 'revisada_por' => $request->user()->id, 'revisada_at' => now()]);
        Auditoria::registrar('laboral.calendario', $d['tipo'].' de '.($trabajador->datos['nombre'] ?? '').' del '.Formato::fecha($d['desde']).' al '.Formato::fecha($d['hasta']).' registrada en la nómina', $trabajador, $trabajador->cliente_id);

        return $this->volver($request, $trabajador)->with('ok', $d['tipo'].' registrada del '.Formato::fecha($d['desde']).' al '.Formato::fecha($d['hasta']).'.');
    }

    /** Revisión de una ausencia: confirmar (con los datos de la IT), guardar sus datos o descartar */
    public function revisar(Request $request, JornadaAusencia $ausencia)
    {
        $t = $ausencia->trabajador;
        $this->propio($request, $t);
        $acc = (string) $request->input('accion');
        abort_unless(in_array($acc, ['confirmar', 'descartar', 'guardar'], true), 422);
        if ($e = $this->bloqueo($t, $ausencia->desde->toDateString(), $ausencia->hasta->toDateString())) {
            return $this->volver($request, $t)->withErrors(['cal' => $e]);
        }
        $d = $acc === 'descartar' ? [] : $this->datosAusencia($request);
        if ($acc !== 'descartar' && $ausencia->tipo === 'Incapacidad temporal' && ! $d['contingencia']) {
            return $this->volver($request, $t)->withErrors(['cal' => 'Indica la contingencia de la IT antes de confirmarla.']);
        }
        $ausencia->update($d + ['estado' => $acc === 'descartar' ? 'descartada' : 'confirmada', 'revisada_por' => $request->user()->id, 'revisada_at' => now()]);
        Auditoria::registrar('laboral.calendario', $ausencia->tipo.' del '.Formato::fecha($ausencia->desde).' al '.Formato::fecha($ausencia->hasta).' '.['confirmar' => 'confirmada para la nómina', 'descartar' => 'descartada para la nómina', 'guardar' => 'actualizada'][$acc], $t, $t->cliente_id);

        return $this->volver($request, $t)->with('ok', $acc === 'descartar' ? 'Ausencia descartada: no cuenta en la nómina (sigue en el control horario).' : ($acc === 'confirmar' ? 'Ausencia confirmada: ya cuenta en la nómina.' : 'Datos de la ausencia guardados.'));
    }

    public function borrarAusencia(Request $request, JornadaAusencia $ausencia)
    {
        $t = $ausencia->trabajador;
        $this->propio($request, $t);
        abort_unless($ausencia->origen === 'nomina', 403);
        if ($e = $this->bloqueo($t, $ausencia->desde->toDateString(), $ausencia->hasta->toDateString())) {
            return $this->volver($request, $t)->withErrors(['cal' => $e]);
        }
        Auditoria::registrar('laboral.calendario', $ausencia->tipo.' del '.Formato::fecha($ausencia->desde).' al '.Formato::fecha($ausencia->hasta).' eliminada', $t, $t->cliente_id);
        $ausencia->delete();

        return $this->volver($request, $t)->with('ok', 'Ausencia eliminada.');
    }

    public function horas(Request $request, Trabajador $trabajador)
    {
        $this->propio($request, $trabajador);
        $v = validator($request->all(), [
            'fecha' => ['required', 'date_format:Y-m-d'], 'tipo' => ['required', Rule::in(array_keys(LaboralHora::TIPOS))],
            'horas' => ['required', 'string', 'max:10'], 'concepto' => ['nullable', 'string', 'max:150', 'required_if:tipo,concepto'], 'nota' => ['nullable', 'string', 'max:255'],
        ], ['fecha.*' => 'Indica el día.', 'tipo.*' => 'Elige el tipo de horas.', 'horas.*' => 'Indica las horas.', 'concepto.required_if' => 'Elige el concepto del convenio.']);
        if ($v->fails()) {
            return $this->volver($request, $trabajador)->withErrors($v)->withInput();
        }
        $d = $v->validated();
        $h = round(Formato::parseNum($d['horas']), 2);
        if ($h <= 0 || $h > 24) {
            return $this->volver($request, $trabajador)->withErrors(['horas' => 'Las horas de un día van de 0,25 a 24.'])->withInput();
        }
        $w = Nomina::rellenar($trabajador->ficha(), $trabajador->cliente);
        if ($d['fecha'] < (string) $w['alta'] || (! empty($w['baja']) && $d['fecha'] > $w['baja'])) {
            return $this->volver($request, $trabajador)->withErrors(['fecha' => 'Ese día no está en alta.'])->withInput();
        }
        if ($e = $this->bloqueo($trabajador, $d['fecha'], $d['fecha'])) {
            return $this->volver($request, $trabajador)->withErrors(['cal' => $e])->withInput();
        }
        LaboralHora::create(['cliente_id' => $trabajador->cliente_id, 'trabajador_id' => $trabajador->id, 'fecha' => $d['fecha'], 'tipo' => $d['tipo'], 'horas' => $h,
            'concepto' => $d['tipo'] === 'concepto' ? $d['concepto'] : null, 'origen' => 'manual', 'nota' => $d['nota'] ?? null, 'created_by' => $request->user()->id]);
        Auditoria::registrar('laboral.calendario', Formato::num($h).' h ('.LaboralHora::TIPOS[$d['tipo']].($d['tipo'] === 'concepto' ? ': '.$d['concepto'] : '').') el '.Formato::fecha($d['fecha']), $trabajador, $trabajador->cliente_id);

        return $this->volver($request, $trabajador)->with('ok', 'Horas añadidas al '.Formato::fecha($d['fecha']).'.');
    }

    public function borrarHoras(Request $request, LaboralHora $hora)
    {
        $t = $hora->trabajador;
        $this->propio($request, $t);
        if ($e = $this->bloqueo($t, $hora->fecha->toDateString(), $hora->fecha->toDateString())) {
            return $this->volver($request, $t)->withErrors(['cal' => $e]);
        }
        $hora->delete();

        return $this->volver($request, $t)->with('ok', 'Horas eliminadas.');
    }

    /** Lleva a la nómina, revisado, el exceso de jornada de los días marcados según los fichajes */
    public function fichajes(Request $request, Trabajador $trabajador)
    {
        $this->propio($request, $trabajador);
        $tipo = $request->input('tipo') === 'complementaria' ? 'complementaria' : 'extra';
        $sel = array_values(array_filter((array) $request->input('dias', []), fn ($x) => is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)));
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        if (! $sel) {
            return $this->volver($request, $trabajador)->withErrors(['cal' => 'Marca los días que quieres llevar a la nómina.']);
        }
        if ($e = $this->bloqueo($trabajador, min($sel), max($sel))) {
            return $this->volver($request, $trabajador)->withErrors(['cal' => $e]);
        }
        $w = Nomina::paraPeriodo(Nomina::rellenar($trabajador->ficha(), $trabajador->cliente), $anio, $mes);
        $prop = collect(Calendario::excesoFichajes($w, Calendario::mes($w, $anio, $mes)))->keyBy('fecha');
        $n = 0;
        foreach ($sel as $f) {
            if ($p = $prop->get($f)) {
                LaboralHora::create(['cliente_id' => $trabajador->cliente_id, 'trabajador_id' => $trabajador->id, 'fecha' => $f, 'tipo' => $tipo, 'horas' => $p['exceso'],
                    'origen' => 'control_horario', 'nota' => 'Fichado '.intdiv($p['mins'], 60).' h '.($p['mins'] % 60).' min; jornada del día '.intdiv($p['teo'], 60).' h '.($p['teo'] % 60).' min', 'created_by' => $request->user()->id]);
                $n++;
            }
        }
        Auditoria::registrar('laboral.calendario', $n.' días de exceso de jornada llevados a la nómina como '.LaboralHora::TIPOS[$tipo], $trabajador, $trabajador->cliente_id);

        return $this->volver($request, $trabajador)->with('ok', $n.' '.($n === 1 ? 'día llevado' : 'días llevados').' a la nómina como '.mb_strtolower(LaboralHora::TIPOS[$tipo]).'.');
    }
}
