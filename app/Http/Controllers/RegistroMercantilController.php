<?php

namespace App\Http\Controllers;

use App\Models\RmEstado;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registro Mercantil del ejercicio: plantillas e informes (certificado, acta, aplicación del resultado…),
 * preparación de los libros para legalizarlos con Legalia y documentación del depósito de cuentas (D2).
 * No se presenta nada: los ficheros oficiales se generan con los programas del Colegio de Registradores.
 */
class RegistroMercantilController extends ContabilidadController
{
    public const PLANTILLAS = [
        'cert' => 'Certificado de aprobación de las cuentas anuales', 'acta' => 'Acta de la junta general ordinaria', 'aplic' => 'Propuesta de aplicación del resultado',
        'titular' => 'Declaración de titularidad real', 'instancia' => 'Instancia de presentación en el Registro Mercantil', 'gestion' => 'Informe de gestión (solo cuentas normales)',
    ];

    public const LIBROS = ['Libro diario', 'Libro de inventarios y cuentas anuales', 'Libro de actas', 'Libro registro de socios'];

    public const D2 = ['Balance', 'Cuenta de pérdidas y ganancias', 'Memoria abreviada', 'Certificado de aprobación de cuentas', 'Propuesta de aplicación del resultado', 'Hoja de identificación de la sociedad', 'Declaración de titularidad real'];

    private function estado($c, int $anio): RmEstado
    {
        return RmEstado::firstOrNew(['cliente_id' => $c->id, 'anio' => $anio], ['datos' => []]);
    }

    public function seccion(Request $request, string $sec)
    {
        $b = $this->base($request, 'rm-'.$sec);
        if (! is_array($b)) {
            return $b;
        }
        $y = $b['ej']->anio;
        $e = $this->estado($b['c'], $y);

        return view('contabilidad.rm', $b + ['sec' => $sec, 'd' => $e->datos ?? [], 'soc' => $b['c']->tipo === 'Sociedad', 'res' => $this->conta->resultado($b['ej']),
            'plazos' => ['legalia' => CarbonImmutable::create($y + 1, 4, 30), 'aprob' => CarbonImmutable::create($y + 1, 6, 30), 'deposito' => CarbonImmutable::create($y + 1, 7, 30)]]);
    }

    public function guardar(Request $request, string $sec): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $y = (int) $request->input('anio', Plazos::hoy()->year);
        $e = $this->estado($c, $y);
        $d = $e->datos ?? [];
        $hoy = Plazos::hoy()->toDateString();
        $msg = 'Guardado.';
        if ($sec === 'plantillas') {
            $v = $request->validate(['junta' => ['nullable', 'date'], 'tipo_junta' => ['nullable', 'in:universal,convocada'], 'acuerdo' => ['nullable', 'in:unanimidad,mayoría'],
                'reserva_legal' => ['nullable', 'string', 'max:20'], 'voluntarias' => ['nullable', 'string', 'max:20'], 'dividendos' => ['nullable', 'string', 'max:20']]);
            $d['junta'] = $v['junta'] ?? null;
            $d['tipo_junta'] = $v['tipo_junta'] ?? 'universal';
            $d['acuerdo'] = $v['acuerdo'] ?? 'unanimidad';
            foreach (['reserva_legal', 'voluntarias', 'dividendos'] as $k) {
                $d['aplic'][$k] = round(Formato::parseNum((string) ($v[$k] ?? '')), 2);
            }
            $msg = 'Datos de la junta y de la aplicación del resultado guardados.';
        } elseif ($sec === 'legalia') {
            $d['libros'] = array_map(fn ($i) => in_array((string) $i, (array) $request->input('libros', []), true), array_keys(self::LIBROS));
            $acc = (string) $request->input('accion');
            if ($acc === 'preparado') {
                $d['leg'] = ['estado' => 'preparado', 'fecha' => $hoy];
                $msg = 'Libros marcados como preparados. Cárgalos en Legalia 2 y preséntalos telemáticamente.';
            } elseif ($acc === 'presentado') {
                $d['leg'] = ['estado' => 'presentado', 'fecha' => $request->input('fecha') ?: $hoy];
                $msg = 'Legalización marcada como presentada en el Registro Mercantil.';
            } elseif ($acc === 'reabrir') {
                $d['leg'] = ['estado' => 'pendiente'];
                $msg = 'Legalización vuelta a pendiente.';
            }
        } else {
            $d['d2docs'] = array_map(fn ($i) => in_array((string) $i, (array) $request->input('docs', []), true), array_keys(self::D2));
            $acc = (string) $request->input('accion');
            if ($acc === 'depositado') {
                if (in_array(false, $d['d2docs'], true)) {
                    return back()->with('aviso', 'Marca antes todos los documentos del depósito.');
                }
                $d['d2'] = ['estado' => 'depositado', 'fecha' => $request->input('fecha') ?: $hoy];
                $msg = 'Cuentas marcadas como depositadas en el Registro Mercantil.';
            } elseif ($acc === 'reabrir') {
                $d['d2'] = ['estado' => 'preparacion'];
                $msg = 'Depósito vuelto a preparación.';
            }
        }
        $e->datos = $d;
        $e->save();
        Auditoria::registrar('contabilidad.rm', 'Registro Mercantil '.$y.' · '.$sec.': '.$msg, null, $c->id);

        return redirect()->route('contabilidad.rm', ['sec' => $sec, 'ej' => $y])->with('ok', $msg);
    }

    /** Documento rellenado con los datos de la empresa, listo para imprimir o guardar en PDF */
    public function documento(Request $request, string $doc)
    {
        $b = $this->base($request, 'rm-plantillas');
        if (! is_array($b)) {
            return $b;
        }
        $c = $b['c'];
        $y = $b['ej']->anio;
        $e = $this->estado($c, $y);
        $d = $e->datos ?? [];
        $d['gen'][$doc] = Plazos::hoy()->toDateString();
        $e->datos = $d;
        $e->save();
        $reps = collect($c->seccion('reps'));
        $admin = $reps->first(fn ($r) => ! empty($r['admin'])) ?? $reps->first() ?? [];
        $reg = collect($c->seccion('regs'))->first(fn ($r) => ($r['tomo'] ?? '') !== '') ?? (collect($c->seccion('regs'))->first() ?? []);
        $s = $this->conta->sumas($b['ej'], null, ['Regularización', 'Cierre']);
        $capital = round($s->filter(fn ($v, $k) => str_starts_with((string) $k, '100'))->sum(fn ($v) => $v['h'] - $v['d']), 2);

        return view('contabilidad.rm-doc', ['c' => $c, 'doc' => $doc, 'titulo' => self::PLANTILLAS[$doc], 'anio' => $y, 'd' => $d, 'admin' => $admin, 'reg' => $reg, 'capital' => $capital,
            'socios' => $reps->filter(fn ($r) => ! empty($r['socio']))->values(), 'res' => $this->conta->resultado($b['ej'])['resultado']]);
    }
}
