<?php

namespace App\Http\Controllers;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Tercero;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\Formato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listados contables (cuenta de explotación, listado diario, listado mayor) y Plan General Contable
 * de la empresa (cuentas a 9 dígitos, ligadas a clientes, proveedores, acreedores y bancos).
 */
class ContabilidadListadosController extends ContabilidadController
{
    // ------------------------------------------------------- cuenta de explotación
    public function pyg(Request $request)
    {
        $b = $this->base($request, 'pyg');
        if (! is_array($b)) {
            return $b;
        }
        // Saldos por trimestre de los grupos 6 y 7 (sin regularización ni cierre)
        $movs = Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('asientos.ejercicio_id', $b['ej']->id)
            ->whereNotIn('asientos.tipo', ['Regularización', 'Cierre'])->where(fn ($q) => $q->where('apuntes.cuenta', 'like', '6%')->orWhere('apuntes.cuenta', 'like', '7%'))
            ->get(['asientos.fecha', 'apuntes.cuenta', 'apuntes.debe', 'apuntes.haber']);
        $q = fn ($m) => intdiv((int) substr((string) $m->fecha, 5, 2) - 1, 3);
        $LIN = [
            ['Importe neto de la cifra de negocios', ['70'], true], ['Variación de existencias', ['71'], true], ['Trabajos realizados para el activo', ['73'], true],
            ['Aprovisionamientos', ['60', '61'], false], ['Otros ingresos de explotación', ['74', '75'], true], ['Gastos de personal', ['64'], false],
            ['Otros gastos de explotación', ['62', '631', '634', '636', '639', '65', '694', '695'], false], ['Amortización del inmovilizado', ['68'], false],
            ['Otros resultados', ['746', '77', '79'], true], ['Pérdidas y deterioros', ['67', '690', '691', '693'], false],
            ['Ingresos financieros', ['76'], true], ['Gastos financieros', ['66'], false], ['Impuesto sobre beneficios', ['630', '633', '638'], false],
        ];
        $filas = [];
        $tot = [0, 0, 0, 0];
        foreach ($LIN as [$l, $pre, $ingreso]) {
            $v = [0.0, 0.0, 0.0, 0.0];
            foreach ($movs as $m) {
                $c = (string) $m->cuenta;
                if (collect($pre)->contains(fn ($p) => str_starts_with($c, $p))) {
                    $v[$q($m)] += $ingreso ? $m->haber - $m->debe : -($m->debe - $m->haber);
                }
            }
            $v = array_map(fn ($x) => round($x, 2), $v);
            if (array_sum(array_map('abs', $v)) > 0.004) {
                $filas[] = [$l, $v, round(array_sum($v), 2)];
                foreach ($v as $i => $x) {
                    $tot[$i] += $x;
                }
            }
        }
        $tot = array_map(fn ($x) => round($x, 2), $tot);
        if ($request->query('exportar') === 'csv') {
            return $this->csv('cuenta-explotacion-'.$b['c']->nif.'-'.$b['ej']->anio.'.csv', ['Concepto', '1T', '2T', '3T', '4T', 'Total'],
                array_merge(array_map(fn ($f) => [$f[0], ...array_map(fn ($x) => Formato::num($x), $f[1]), Formato::num($f[2])], $filas), [['Resultado', ...array_map(fn ($x) => Formato::num($x), $tot), Formato::num(array_sum($tot))]]));
        }

        return view('contabilidad.pyg', $b + ['filas' => $filas, 'tot' => $tot]);
    }

    // ------------------------------------------------------- listado diario
    public function ldiario(Request $request)
    {
        $b = $this->base($request, 'ldiario');
        if (! is_array($b)) {
            return $b;
        }
        $desde = $request->query('desde') ?: $b['ej']->anio.'-01-01';
        $hasta = $request->query('hasta') ?: $b['ej']->anio.'-12-31';
        $lista = Asiento::with('apuntes')->where('ejercicio_id', $b['ej']->id)->whereBetween('fecha', [$desde, $hasta])->orderBy('numero')->get();
        $propias = Contabilidad::propias($b['c']);
        if ($request->query('exportar') === 'csv') {
            return $this->csv('listado-diario-'.$b['c']->nif.'-'.$b['ej']->anio.'.csv', ['Asiento', 'Fecha', 'Cuenta', 'Nombre', 'Concepto', 'Debe', 'Haber'],
                $lista->flatMap(fn ($a) => $a->apuntes->map(fn ($p) => [$a->numero, Formato::fecha($a->fecha), $p->cuenta, Contabilidad::nombre($p->cuenta, $propias), $p->concepto ?: $a->concepto, Formato::num($p->debe), Formato::num($p->haber)]))->all());
        }

        return view('contabilidad.ldiario', $b + ['lista' => $lista, 'desde' => $desde, 'hasta' => $hasta, 'propias' => $propias]);
    }

    // ------------------------------------------------------- listado mayor
    public function lmayor(Request $request)
    {
        $b = $this->base($request, 'lmayor');
        if (! is_array($b)) {
            return $b;
        }
        $de = Contabilidad::a9((string) ($request->query('de') ?: '1'));
        $a = preg_replace('/\D/', '', (string) ($request->query('a') ?: '799999999'));
        $a = str_pad(substr($a, 0, 9), 9, '9');
        $movs = Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('asientos.ejercicio_id', $b['ej']->id)
            ->whereBetween('apuntes.cuenta', [$de, $a])->orderBy('apuntes.cuenta')->orderBy('asientos.fecha')->orderBy('asientos.numero')
            ->get(['asientos.numero', 'asientos.fecha', 'asientos.concepto as aconcepto', 'apuntes.concepto', 'apuntes.cuenta', 'apuntes.debe', 'apuntes.haber']);
        $propias = Contabilidad::propias($b['c']);
        $grupos = $movs->groupBy(fn ($m) => (string) $m->cuenta)->map(function ($g, $cta) use ($propias) {
            $saldo = 0.0;
            $rows = $g->map(function ($m) use (&$saldo) {
                $saldo = round($saldo + $m->debe - $m->haber, 2);

                return ['n' => $m->numero, 'fecha' => substr((string) $m->fecha, 0, 10), 'concepto' => $m->concepto ?: $m->aconcepto, 'd' => (float) $m->debe, 'h' => (float) $m->haber, 'saldo' => $saldo];
            });

            return ['cuenta' => (string) $cta, 'nombre' => Contabilidad::nombre((string) $cta, $propias) ?? '', 'rows' => $rows, 'd' => round($g->sum('debe'), 2), 'h' => round($g->sum('haber'), 2), 'saldo' => $saldo];
        })->values();
        if ($request->query('exportar') === 'csv') {
            return $this->csv('listado-mayor-'.$b['c']->nif.'-'.$b['ej']->anio.'.csv', ['Cuenta', 'Nombre', 'Asiento', 'Fecha', 'Concepto', 'Debe', 'Haber', 'Saldo'],
                $grupos->flatMap(fn ($g) => $g['rows']->map(fn ($r) => [$g['cuenta'], $g['nombre'], $r['n'], Formato::fecha($r['fecha']), $r['concepto'], Formato::num($r['d']), Formato::num($r['h']), Formato::num($r['saldo'])]))->all());
        }

        return view('contabilidad.lmayor', $b + ['grupos' => $grupos, 'de' => $request->query('de', ''), 'a' => $request->query('a', ''), 'catalogo' => $this->catalogo($b['c'])]);
    }

    // ------------------------------------------------------- Plan General Contable
    public function pgc(Request $request)
    {
        $b = $this->base($request, 'pgc');
        if (! is_array($b)) {
            return $b;
        }
        $c = $b['c'];
        Contabilidad::sincronizarBancos($c);
        $propias = Cuenta::where('cliente_id', $c->id)->get()->keyBy(fn ($x) => (string) $x->codigo);
        $terceros = Tercero::where('cliente_id', $c->id)->get()->keyBy(fn ($x) => (string) $x->cuenta);
        $usadas = Apunte::where('cliente_id', $c->id)->distinct()->pluck('cuenta')->map(fn ($x) => (string) $x)->flip();
        $filas = [];
        foreach (config('pgc') as $k => $n) {
            $k9 = Contabilidad::a9((string) $k);
            $o = $propias->get($k9);
            $filas[$k9] = ['c' => $k9, 'n' => $o && ! $o->oculta ? $o->nombre : $n, 'tipo' => 'PGC', 'origen' => $o && ! $o->oculta && $o->nombre !== $n ? 'Plan General Contable · nombre cambiado' : '', 'oculta' => (bool) $o?->oculta, 'usada' => isset($usadas[$k9]), 'propia' => false, 'base' => true];
        }
        foreach ($propias as $k => $o) {
            if (isset($filas[$k]) && ! empty($filas[$k]['base'])) {
                continue;
            }
            $origen = match (true) {
                (bool) $o->iban => 'Banco · '.Formato::iban($o->iban),
                str_starts_with($k, '430') => 'Cliente'.($o->nif ? ' · '.$o->nif : ''),
                default => 'Subcuenta propia',
            };
            $filas[$k] = ['c' => $k, 'n' => $o->nombre, 'tipo' => 'Subcuenta', 'origen' => $origen, 'oculta' => (bool) $o->oculta, 'usada' => isset($usadas[$k]), 'propia' => true, 'base' => false];
        }
        foreach ($terceros as $k => $t) {
            $filas[$k] = ['c' => $k, 'n' => $t->nombre, 'tipo' => 'Subcuenta', 'origen' => (['proveedor' => 'Proveedor', 'acreedor' => 'Acreedor', 'cliente' => 'Cliente'][$t->clase] ?? 'Tercero').($t->nif ? ' · '.$t->nif : ''),
                'oculta' => (bool) $propias->get($k)?->oculta, 'usada' => isset($usadas[$k]), 'propia' => false, 'base' => false, 'tercero' => $t->clase];
        }
        ksort($filas, SORT_STRING);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $grupo = preg_match('/^[1-7]$/', (string) $request->query('g')) ? (string) $request->query('g') : '';
        $ver = in_array($request->query('ver'), ['propias', 'bajas'], true) ? $request->query('ver') : 'todas';
        $lista = collect($filas)->map(fn ($f) => ['c' => (string) $f['c']] + $f)->filter(fn ($f) => ($grupo === '' || $f['c'][0] === $grupo)
            && ($q === '' || str_contains($f['c'], preg_replace('/\D/', '', $q) ?: '#') || str_contains(mb_strtolower($f['n'].' '.$f['origen']), $q))
            && ($ver === 'todas' ? ! $f['oculta'] : ($ver === 'bajas' ? $f['oculta'] : $f['tipo'] === 'Subcuenta' && ! $f['oculta'])))->values();
        if ($request->query('exportar') === 'csv') {
            return $this->csv('pgc-'.$c->nif.'.csv', ['Cuenta', 'Nombre', 'Tipo', 'Origen', 'Con movimientos'], $lista->map(fn ($f) => [$f['c'], $f['n'], $f['tipo'], $f['origen'], $f['usada'] ? 'Sí' : 'No'])->all());
        }

        return view('contabilidad.pgc', $b + ['lista' => $lista, 'q' => (string) $request->query('q', ''), 'grupo' => $grupo, 'ver' => $ver, 'total' => count($filas),
            'nProp' => collect($filas)->where('tipo', 'Subcuenta')->count(), 'nBajas' => collect($filas)->where('oculta', true)->count(), 'nuevo' => $request->boolean('nuevo'),
            'editarPgc' => preg_match('/^\d{9}$/', (string) $request->query('editar')) ? (string) $request->query('editar') : null]);
    }

    public function guardarPgc(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $d = $request->validate(['codigo' => ['required', 'string', 'max:12'], 'nombre' => ['required', 'string', 'max:255']],
            ['codigo.required' => 'Indica el código de la cuenta.', 'nombre.required' => 'Indica el nombre de la cuenta.']);
        $cod = $this->normaliza((string) $d['codigo']);
        if (! preg_match('/^\d{9}$/', $cod)) {
            return back()->withInput()->withErrors(['pgc' => 'El código tiene que tener 9 dígitos (puedes escribir 572.1 para 572000001).']);
        }
        if (! Contabilidad::existeEnPgc($cod)) {
            return back()->withInput()->withErrors(['pgc' => 'La cuenta '.$cod.' no cuelga de ninguna cuenta del Plan General Contable (revisa las 3 primeras cifras).']);
        }
        if (isset(Contabilidad::propias($c)[$cod]) || (in_array($cod, array_map(fn ($k) => Contabilidad::a9((string) $k), array_keys(config('pgc'))), true) && ! in_array($cod, Contabilidad::ocultas($c), true))) {
            return back()->withInput()->withErrors(['pgc' => 'La cuenta '.$cod.' ya existe.']);
        }
        if (Tercero::where('cliente_id', $c->id)->where('cuenta', $cod)->exists()) {
            return back()->withInput()->withErrors(['pgc' => 'La cuenta '.$cod.' ya es de un proveedor o acreedor.']);
        }
        Cuenta::updateOrCreate(['cliente_id' => $c->id, 'codigo' => $cod], ['nombre' => trim($d['nombre']), 'oculta' => false]);
        Auditoria::registrar('contabilidad.pgc', 'Cuenta '.$cod.' añadida: '.trim($d['nombre']), null, $c->id);

        return redirect()->route('contabilidad.pgc', ['q' => substr($cod, 0, 3)])->with('ok', 'Cuenta '.$cod.' · '.trim($d['nombre']).' añadida al PGC de '.$c->nombre.'.');
    }

    private function esBase(string $codigo): bool
    {
        return in_array($codigo, array_map(fn ($k) => Contabilidad::a9((string) $k), array_keys(config('pgc'))), true);
    }

    /** Modificar una cuenta: el nombre siempre; el código, en las subcuentas de la empresa (sus apuntes pasan a la nueva) */
    public function editarPgc(Request $request, string $codigo): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && preg_match('/^\d{9}$/', $codigo), 404);
        $d = $request->validate(['nombre' => ['required', 'string', 'max:255'], 'codigo' => ['nullable', 'string', 'max:12']], ['nombre.required' => 'Indica el nombre de la cuenta.']);
        $nombre = trim($d['nombre']);
        $nuevo = ! empty($d['codigo']) ? $this->normaliza((string) $d['codigo']) : $codigo;
        $base = $this->esBase($codigo);
        $t = Tercero::where('cliente_id', $c->id)->where('cuenta', $codigo)->first();
        $cta = Cuenta::where('cliente_id', $c->id)->where('codigo', $codigo)->first();
        $volver = fn () => redirect()->route('contabilidad.pgc', ['q' => substr($codigo, 0, 3), 'editar' => $codigo])->withInput();
        if (! $base && ! $t && ! $cta) {
            abort(404);
        }
        if ($nuevo !== $codigo) {
            if ($base) {
                return $volver()->withErrors(['pgcEd' => 'Las cuentas del Plan General Contable mantienen su código; solo se les puede cambiar el nombre.']);
            }
            if (! preg_match('/^\d{9}$/', $nuevo) || ! Contabilidad::existeEnPgc($nuevo)) {
                return $volver()->withErrors(['pgcEd' => 'El código nuevo tiene que tener 9 dígitos y colgar de una cuenta del Plan General Contable.']);
            }
            if ($this->esBase($nuevo) || Cuenta::where('cliente_id', $c->id)->where('codigo', $nuevo)->exists() || Tercero::where('cliente_id', $c->id)->where('cuenta', $nuevo)->exists() || Apunte::where('cliente_id', $c->id)->where('cuenta', $nuevo)->exists()) {
                return $volver()->withErrors(['pgcEd' => 'La cuenta '.$nuevo.' ya existe o tiene movimientos.']);
            }
            if ($t && substr($nuevo, 0, 3) !== substr($codigo, 0, 3)) {
                return $volver()->withErrors(['pgcEd' => 'La cuenta de un '.mb_strtolower(Tercero::CLASES[$t->clase][1] ?? 'tercero').' tiene que seguir empezando por '.substr($codigo, 0, 3).'.']);
            }
            $cerrado = Apunte::where('apuntes.cliente_id', $c->id)->where('apuntes.cuenta', $codigo)->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->join('ejercicios', 'ejercicios.id', '=', 'asientos.ejercicio_id')
                ->where('ejercicios.estado', 'Cerrado')->exists();
            if ($cerrado) {
                return $volver()->withErrors(['pgcEd' => 'La cuenta '.$codigo.' tiene apuntes en un ejercicio cerrado: no se puede cambiar su código. Reabre el ejercicio o cambia solo el nombre.']);
            }
        }
        DB::transaction(function () use ($c, $codigo, $nuevo, $nombre, $base, $t, $cta) {
            if ($nuevo !== $codigo) {
                Apunte::where('cliente_id', $c->id)->where('cuenta', $codigo)->update(['cuenta' => $nuevo]);
            }
            if ($t) {
                $t->update(['cuenta' => $nuevo, 'nombre' => $nombre]);
            }
            if ($cta) {
                $cta->update(['codigo' => $nuevo, 'nombre' => $nombre]);
            } elseif ($base) {
                if ($nombre !== Contabilidad::nombre($codigo)) {
                    Cuenta::create(['cliente_id' => $c->id, 'codigo' => $codigo, 'nombre' => $nombre, 'oculta' => false]);
                }
            }
            // Cuenta del PGC con el nombre original: no hace falta guardar nada
            if ($base && $cta && $nombre === Contabilidad::nombre($codigo) && ! $cta->iban && ! $cta->nif && ! $cta->oculta) {
                $cta->delete();
            }
        });
        Auditoria::registrar('contabilidad.pgc', 'Cuenta '.$codigo.($nuevo !== $codigo ? ' → '.$nuevo : '').' modificada: '.$nombre, null, $c->id);

        return redirect()->route('contabilidad.pgc', ['q' => substr($nuevo, 0, 3)])->with('ok', 'Cuenta '.$nuevo.' · '.$nombre.' guardada'.($nuevo !== $codigo ? ' (antes '.$codigo.'; sus apuntes se han pasado a la nueva)' : '').'.');
    }

    /**
     * Eliminar: solo sin apuntes ni facturas (las del PGC se dan de baja). Con movimientos hay que cambiarla antes
     * con «Modificar»: sus apuntes pasan a la cuenta nueva y se actualiza su cliente o proveedor.
     */
    public function borrarPgc(Request $request, string $codigo): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && preg_match('/^\d{9}$/', $codigo), 404);
        $usada = Apunte::where('cliente_id', $c->id)->where('cuenta', $codigo)->exists();
        $t = Tercero::where('cliente_id', $c->id)->where('cuenta', $codigo)->first();
        $base = $this->esBase($codigo);
        $cta = Cuenta::where('cliente_id', $c->id)->where('codigo', $codigo)->first();
        $nombre = $t?->nombre ?? $cta?->nombre ?? Contabilidad::nombre($codigo) ?? $codigo;
        // Con apuntes o facturas no se elimina: primero hay que cambiarla (Modificar) para que sus apuntes pasen a otra cuenta
        if ($usada) {
            return back()->with('aviso', 'La cuenta '.$codigo.' · '.$nombre.' tiene facturas o apuntes contabilizados: no se puede eliminar. Cámbiala antes con «Modificar» (sus apuntes pasan a la cuenta nueva y se actualiza su cliente o proveedor).');
        }
        if ($t && ($t->facturas()->exists() || ($t->clase === 'cliente' && $t->nif && Factura::where('cliente_id', $c->id)->where('receptor_nif', $t->nif)->exists()))) {
            return back()->with('aviso', 'La cuenta '.$codigo.' es de '.$t->nombre.', que tiene facturas registradas: no se puede eliminar.');
        }
        if ($base) {
            Cuenta::updateOrCreate(['cliente_id' => $c->id, 'codigo' => $codigo], ['nombre' => $nombre, 'oculta' => true]);
            $msg = 'Cuenta '.$codigo.' · '.$nombre.' dada de baja en el PGC de '.$c->nombre.'. Puedes reactivarla en «Dadas de baja».';
        } else {
            DB::transaction(function () use ($c, $codigo, $t) {
                $t?->delete();
                Cuenta::where('cliente_id', $c->id)->where('codigo', $codigo)->delete();
            });
            $msg = 'Cuenta '.$codigo.' · '.$nombre.' eliminada'.($t ? ' (y '.mb_strtolower(Tercero::CLASES[$t->clase][1] ?? 'tercero').' de Gestión)' : '').'.';
        }
        Auditoria::registrar('contabilidad.pgc', $msg, null, $c->id);

        return back()->with('ok', $msg);
    }

    public function reactivarPgc(Request $request, string $codigo): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && preg_match('/^\d{9}$/', $codigo), 404);
        $cta = Cuenta::where('cliente_id', $c->id)->where('codigo', $codigo)->where('oculta', true)->first();
        if ($cta) {
            $limpia = $this->esBase($codigo) && $cta->nombre === Contabilidad::nombre($codigo) && ! $cta->iban && ! $cta->nif;
            $limpia ? $cta->delete() : $cta->update(['oculta' => false]);
        }
        Auditoria::registrar('contabilidad.pgc', 'Cuenta '.$codigo.' reactivada', null, $c->id);

        return back()->with('ok', 'Cuenta '.$codigo.' reactivada.');
    }

    /** 572.1 → 572000001 · 4751 → 475100000 */
    private function normaliza(string $v): string
    {
        $v = preg_replace('/[^\d.]/', '', $v);
        if (str_contains($v, '.')) {
            [$a, $b] = array_pad(explode('.', $v, 2), 2, '');
            $b = str_replace('.', '', $b);

            return strlen($a) + strlen($b) <= 9 ? $a.str_repeat('0', 9 - strlen($a) - strlen($b)).$b : substr($a.$b, 0, 9);
        }

        return strlen($v) >= 9 ? substr($v, 0, 9) : str_pad($v, 9, '0');
    }
}
