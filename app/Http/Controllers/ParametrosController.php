<?php

namespace App\Http\Controllers;

use App\Models\Oportunidad;
use App\Services\Auditoria;
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
