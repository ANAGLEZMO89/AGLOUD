<?php

namespace App\Http\Controllers;

use App\Models\Oportunidad;
use App\Models\Tarea;
use App\Models\Tercero;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\Formato;
use App\Support\Parametros;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pestaña «Parámetros» de cada módulo. Solo la administración los cambia; el resto los consulta. */
class ParametrosController extends Controller
{
    public function show(Request $request, string $modulo)
    {
        abort_unless(isset(Parametros::MODULOS[$modulo]), 404);
        [$nombre, $grupo, $ruta] = Parametros::MODULOS[$modulo];

        return view('parametros.show', [
            'titulo' => 'Parámetros · '.$nombre, 'activo' => $modulo, 'modulo' => $modulo, 'nombre' => $nombre, 'grupo' => $grupo, 'ruta' => $ruta,
            'p' => Parametros::get($modulo), 'puede' => $request->user()->esAdmin(),
        ]);
    }

    public function guardar(Request $request, string $modulo)
    {
        abort_unless(isset(Parametros::MODULOS[$modulo]), 404);
        abort_unless($request->user()->esAdmin(), 403, 'Solo la administración del despacho puede cambiar los parámetros.');
        $v = $this->{'datos'.ucfirst($modulo)}($request);
        Parametros::put($modulo, $v);
        Auditoria::registrar('parametros', 'Parámetros guardados: '.Parametros::MODULOS[$modulo][0]);

        return redirect()->route('parametros', $modulo)->with('ok', 'Parámetros de '.Parametros::MODULOS[$modulo][0].' guardados.');
    }

    private function lineas(?string $txt): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $txt)), fn ($x) => $x !== ''));
    }

    private function datosClientes(Request $r): array
    {
        $d = $r->validate([
            'periodicidad' => ['required', Rule::in(config('agcloud.cuota.periodicidad'))],
            'iva' => ['required', Rule::in(array_keys(config('agcloud.cuota.iva')))],
            'forma_cobro' => ['required', 'string', 'max:60'],
            'concepto' => ['required', 'string', 'max:120'],
        ]);
        $d['iva'] = (int) $d['iva'];

        return $d;
    }

    private function datosDocumentos(Request $r): array
    {
        $d = $r->validate(['cupo_mb' => ['required', 'integer', 'min:100', 'max:102400'], 'aviso_caducidad' => ['required', 'integer', 'min:7', 'max:365']]);

        return ['cupo_mb' => (int) $d['cupo_mb'], 'aviso_caducidad' => (int) $d['aviso_caducidad']];
    }

    private function datosNotificaciones(Request $r): array
    {
        $r->validate([
            'dias' => ['required', Rule::in(['1', '3', '5'])], 'comunidad' => ['nullable', Rule::in(['', 'AN'])],
            'festivos' => ['nullable', 'string', 'max:5000'],
            'tipos' => ['required', 'array', 'min:1', 'max:100'],
            'tipos.*.org' => ['required', Rule::in(array_keys(config('agcloud.organismos')))],
            'tipos.*.canal' => ['required', Rule::in(config('agcloud.canales'))],
            'tipos.*.tipo' => ['required', 'string', 'max:160'],
            'tipos.*.n' => ['required', 'integer', 'min:0', 'max:365'],
            'tipos.*.t' => ['required', Rule::in(['h', 'n', 'm'])],
            'tipos.*.accion' => ['nullable', 'string', 'max:120'],
        ], ['tipos.*.tipo.required' => 'Cada tipo de notificación necesita un nombre.']);
        $fest = [];
        foreach ($this->lineas($r->input('festivos')) as $l) {
            if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})\s+(.+)$#u', $l, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                throw ValidationException::withMessages(['festivos' => 'Festivo mal escrito: «'.$l.'». Formato: dd/mm/aaaa Nombre del festivo.']);
            }
            $fest[] = ['fecha' => sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), 'nombre' => mb_substr(trim($m[4]), 0, 80)];
        }
        usort($fest, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return [
            'email' => $r->boolean('email'), 'wa' => $r->boolean('wa'), 'dias' => (int) $r->input('dias'), 'comunidad' => (string) $r->input('comunidad', ''),
            'festivos' => $fest,
            'tipos' => collect($r->input('tipos'))->map(fn ($t) => [$t['org'], $t['canal'], trim($t['tipo']), (int) $t['n'], $t['t'], trim((string) ($t['accion'] ?? ''))])->values()->all(),
        ];
    }

    private function datosExpedientes(Request $r): array
    {
        $r->validate([
            'pl' => ['required', 'array', 'min:1', 'max:100'],
            'pl.*.nombre' => ['required', 'string', 'max:120'], 'pl.*.cat' => ['required', 'string', 'max:40'],
            'pl.*.hon' => ['nullable', 'string', 'max:20'], 'pl.*.pasos' => ['required', 'string', 'max:3000'], 'pl.*.docs' => ['nullable', 'string', 'max:3000'],
        ], ['pl.*.pasos.required' => 'Cada trámite necesita al menos un paso.', 'pl.*.nombre.required' => 'Cada trámite necesita un nombre.']);
        $pl = [];
        foreach ($r->input('pl') as $x) {
            $n = trim($x['nombre']);
            if (isset($pl[$n])) {
                throw ValidationException::withMessages(['pl' => 'Hay dos trámites con el mismo nombre: «'.$n.'».']);
            }
            $pl[$n] = ['cat' => trim($x['cat']), 'hon' => max(0, Formato::parseNum($x['hon'] ?? '0')), 'pasos' => $this->lineas($x['pasos']), 'docs' => $this->lineas($x['docs'] ?? '')];
        }

        return ['portal_defecto' => $r->boolean('portal_defecto'), 'plantillas' => $pl];
    }

    private function datosProveedores(Request $r): array
    {
        $d = $r->validate(['forma_pago' => ['required', Rule::in(TerceroController::PAGOS)], 'pago_dias' => ['required', Rule::in(['0', '15', '30', '45', '60', '90'])]]);

        return ['forma_pago' => $d['forma_pago'], 'pago_dias' => (int) $d['pago_dias']];
    }

    private function datosAcreedores(Request $r): array
    {
        $d = $r->validate(['forma_pago' => ['required', Rule::in(TerceroController::PAGOS)], 'pago_dias' => ['required', Rule::in(['0', '15', '30', '45', '60', '90'])],
            'tipo' => ['required', Rule::in(Tercero::TIPOS_ACREEDOR)], 'retencion' => ['required', Rule::in(['0', '7', '15', '19'])]]);

        return ['forma_pago' => $d['forma_pago'], 'pago_dias' => (int) $d['pago_dias'], 'tipo' => $d['tipo'], 'retencion' => (int) $d['retencion']];
    }

    private function datosCalendario(Request $r): array
    {
        $r->validate(['vista' => ['required', Rule::in(['mes', 'agenda'])]]);

        return ['vista' => $r->input('vista'), 'notificaciones' => $r->boolean('notificaciones'), 'expedientes' => $r->boolean('expedientes'), 'tareas' => $r->boolean('tareas'), 'festivos' => $r->boolean('festivos'), 'fiscal' => $r->boolean('fiscal')];
    }

    private function datosTareas(Request $r): array
    {
        $d = $r->validate(['jornada_semanal' => ['required', 'numeric', 'min:1', 'max:60'], 'jornada_diaria' => ['required', 'numeric', 'min:1', 'max:12'],
            'vacaciones' => ['required', 'integer', 'min:0', 'max:60'], 'prioridad' => ['required', Rule::in(array_keys(Tarea::PRIORIDADES))]]);

        return ['jornada_semanal' => (float) $d['jornada_semanal'], 'jornada_diaria' => (float) $d['jornada_diaria'], 'vacaciones' => (int) $d['vacaciones'], 'prioridad' => $d['prioridad']];
    }

    private function datosContabilidad(Request $r): array
    {
        $claves = array_keys(Parametros::defecto('contabilidad'));
        $r->validate(collect($claves)->mapWithKeys(fn ($k) => [$k => ['required', 'regex:/^\d{3,9}$/']])->all(), ['*.regex' => 'Las cuentas tienen de 3 a 9 cifras.']);
        $out = [];
        foreach ($claves as $k) {
            $v = (string) $r->input($k);
            if (! Contabilidad::existeEnPgc(Contabilidad::a9($v))) {
                throw ValidationException::withMessages([$k => 'La cuenta '.$v.' no existe en el plan de cuentas.']);
            }
            $out[$k] = $v;
        }

        return $out;
    }

    private function datosProductos(Request $r): array
    {
        $d = $r->validate(['tipo' => ['required', Rule::in(ProductoController::TIPOS)], 'iva' => ['required', Rule::in(ProductoController::IVAS)]]);

        return ['tipo' => $d['tipo'], 'iva' => (int) $d['iva']];
    }

    private function datosFiscal(Request $r): array
    {
        $d = $r->validate([
            'aviso_dias' => ['required', 'integer', 'min:0', 'max:60'],
            'umbral_347' => ['required', 'string', 'max:20'],
            'pct_130' => ['required', 'integer', 'min:1', 'max:100'],
            'pct_202_402' => ['required', 'integer', 'min:1', 'max:100'],
            'pct_202_403' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $umbral = Formato::parseNum($d['umbral_347']);
        if ($umbral <= 0) {
            throw ValidationException::withMessages(['umbral_347' => 'Indica el importe del umbral del modelo 347.']);
        }

        return ['aviso_dias' => (int) $d['aviso_dias'], 'umbral_347' => round($umbral, 2), 'pct_130' => (int) $d['pct_130'], 'pct_202_402' => (int) $d['pct_202_402'], 'pct_202_403' => (int) $d['pct_202_403']];
    }

    private function datosCrm(Request $r): array
    {
        $r->validate([
            'origenes' => ['required', 'string', 'max:2000'], 'validez' => ['required', Rule::in(['15', '30', '60', '90'])],
            'prob' => ['required', 'array'], 'prob.*' => ['required', 'integer', 'min:0', 'max:100'], 'pie_presupuesto' => ['nullable', 'string', 'max:500'],
        ]);
        $orig = array_slice($this->lineas($r->input('origenes')), 0, 30);
        if (! $orig) {
            throw ValidationException::withMessages(['origenes' => 'Indica al menos un origen.']);
        }

        return [
            'origenes' => $orig, 'validez' => (int) $r->input('validez'), 'pie_presupuesto' => trim((string) $r->input('pie_presupuesto')),
            'probabilidades' => collect(array_keys(Oportunidad::ETAPAS))->mapWithKeys(fn ($e) => [$e => (int) ($r->input('prob')[$e] ?? Oportunidad::ETAPAS[$e])])->all(),
        ];
    }
}
