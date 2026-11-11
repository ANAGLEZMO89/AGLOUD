<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\Formato;
use App\Support\Plazos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Cobros (facturas emitidas) y pagos (facturas de proveedores y acreedores) de la empresa elegida.
 * Un clic marca cobrado/pagado; otro lo envía a contabilidad por banco (572) o caja (570).
 */
class CobrosPagosController extends Controller
{
    public const CHIPS = ['Pendientes', 'Sin contabilizar', 'Contabilizados', 'Todos'];

    public function __construct(private Contabilidad $conta) {}

    private function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    private static function medioDe(?string $formaPago): string
    {
        return $formaPago === 'Efectivo' ? 'caja' : 'banco';
    }

    // ------------------------------------------------------------ listados
    public function cobros(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/clientes/cobros'])->with('aviso', 'Elige primero la empresa con la que vas a trabajar.');
        }
        $todas = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->orderByDesc('fecha')->orderByDesc('id')->get();
        $filas = $todas->map(fn (Factura $f) => [
            'id' => $f->id, 'num' => $f->num, 'fecha' => $f->fecha, 'ter' => $f->receptor_nombre ?: 'Factura simplificada', 'nif' => (string) $f->receptor_nif,
            'venc' => $f->vencimiento() ?? $f->fecha, 'total' => (float) $f->total, 'hecho' => (bool) $f->cobrada, 'hecho_at' => $f->cobrada_at,
            'asiento' => $f->cobro_asiento_id, 'medio' => $f->cobro_medio ?: self::medioDe($f->forma_pago), 'conta' => (bool) $f->asiento_id,
            'marcar' => route('cobros.marcar', $f), 'contabilizar' => route('cobros.contabilizar', $f), 'url' => route('facturacion.show', $f),
        ]);

        return $this->vista($request, $c, 'cobro', 'clientes', $filas);
    }

    public function pagosProveedores(Request $request)
    {
        return $this->pagos($request, 'proveedor');
    }

    public function pagosAcreedores(Request $request)
    {
        return $this->pagos($request, 'acreedor');
    }

    private function pagos(Request $request, string $clase)
    {
        $mod = $clase === 'proveedor' ? 'proveedores' : 'acreedores';
        $c = $this->ctx($request);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/'.$mod.'/pagos'])->with('aviso', 'Elige primero la empresa con la que vas a trabajar.');
        }
        $todas = FacturaRecibida::with('tercero')->where('cliente_id', $c->id)->where('clase', Tercero::CLASES[$clase][3])->orderByDesc('fecha')->orderByDesc('id')->get();
        $filas = $todas->map(fn (FacturaRecibida $f) => [
            'id' => $f->id, 'num' => $f->num, 'fecha' => $f->fecha, 'ter' => (string) $f->tercero?->nombre, 'nif' => (string) $f->tercero?->nif,
            'venc' => $f->vencimiento ?? $f->fecha, 'total' => (float) $f->total, 'hecho' => (bool) $f->pagada_at, 'hecho_at' => $f->pagada_at,
            'asiento' => $f->pago_asiento_id, 'medio' => $f->pago_medio ?: self::medioDe($f->forma_pago), 'conta' => (bool) $f->asiento_id,
            'marcar' => route($mod.'.pagos.marcar', $f), 'contabilizar' => route($mod.'.pagos.contabilizar', $f), 'url' => route($mod.'.facturas', ['editar' => $f->id]),
        ]);

        return $this->vista($request, $c, 'pago', $mod, $filas);
    }

    private function vista(Request $request, Cliente $c, string $tipo, string $mod, Collection $filas)
    {
        $hoy = Plazos::hoy();
        $chip = max(0, min(3, (int) $request->query('chip', 0)));
        $ter = trim((string) $request->query('t', ''));
        $porTercero = $filas->groupBy('ter')->map(fn ($g, $k) => ['ter' => $k, 'nif' => $g->first()['nif'], 'pend' => $g->where('hecho', false)->sum('total'),
            'n' => $g->where('hecho', false)->count(), 'hecho' => $g->where('hecho', true)->sum('total')])->sortByDesc('pend')->values();
        $filtro = [fn ($f) => ! $f['hecho'], fn ($f) => $f['hecho'] && ! $f['asiento'], fn ($f) => (bool) $f['asiento'], fn ($f) => true][$chip];
        $lista = $filas->filter($filtro)->filter(fn ($f) => $ter === '' || $f['ter'] === $ter)->values();
        $pend = $filas->where('hecho', false);
        $sinConta = $filas->filter(fn ($f) => $f['hecho'] && ! $f['asiento']);
        $verbo = $tipo === 'cobro' ? 'cobrado' : 'pagado';
        $kpis = [
            ['Pendiente de '.($tipo === 'cobro' ? 'cobro' : 'pago'), Formato::eur($pend->sum('total')), Formato::entero($pend->count()).' facturas · '.Formato::entero($pend->filter(fn ($f) => $f['venc']->lt($hoy))->count()).' vencidas'],
            [ucfirst($verbo).' sin contabilizar', Formato::eur($sinConta->sum('total')), Formato::entero($sinConta->count()).' facturas'],
            [ucfirst($verbo).' y contabilizado', Formato::eur($filas->filter(fn ($f) => (bool) $f['asiento'])->sum('total')), 'Con su asiento de '.$tipo],
        ];

        return view('gestion.cobros', ['titulo' => $tipo === 'cobro' ? 'Cobros' : 'Pagos', 'activo' => $mod, 'c' => $c, 'tipo' => $tipo, 'mod' => $mod, 'chip' => $chip, 'ter' => $ter,
            'lista' => $lista, 'porTercero' => $porTercero, 'kpis' => $kpis, 'hoy' => $hoy, 'nSinConta' => $sinConta->count(),
            'rutaLista' => $tipo === 'cobro' ? 'clientes.cobros' : $mod.'.pagos', 'rutaTodos' => $tipo === 'cobro' ? route('cobros.contabilizarTodos') : route($mod.'.pagos.contabilizarTodos')]);
    }

    // ------------------------------------------------------------ cobros
    private function facturaPropia(Request $r, Factura $f): Cliente
    {
        $c = $this->ctx($r);
        abort_unless($c && $f->cliente_id === $c->id, 404);

        return $c;
    }

    public function marcarCobro(Request $request, Factura $factura): RedirectResponse
    {
        $c = $this->facturaPropia($request, $factura);
        abort_if($factura->estado === 'Anulada', 422);
        if ($factura->cobro_asiento_id) {
            return back()->with('aviso', 'El cobro de '.$factura->num.' ya está contabilizado. Para deshacerlo, borra antes su asiento en Contabilidad › Libro diario.');
        }
        $fecha = $this->fecha($request);
        $factura->cobrada = ! $factura->cobrada;
        $factura->cobrada_at = $factura->cobrada ? $fecha : null;
        $factura->save();
        Auditoria::registrar('factura.cobro', $factura->num.($factura->cobrada ? ' cobrada' : ': cobro anulado'), $factura, $c->id);

        return back()->with('ok', 'Factura '.$factura->num.($factura->cobrada ? ' cobrada el '.Formato::fecha($fecha).'.' : ': cobro anulado.'));
    }

    public function contabilizarCobro(Request $request, Factura $factura): RedirectResponse
    {
        $c = $this->facturaPropia($request, $factura);
        try {
            $a = $this->conta->contabilizarCobro($factura, $this->medio($request), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('contabilidad.cobro', 'Cobro de '.$factura->num.' contabilizado (asiento '.$a->numero.')', $a, $c->id);

        return back()->with('ok', 'Cobro de '.$factura->num.' contabilizado: asiento nº '.$a->numero.' por '.Contabilidad::MEDIOS[$factura->fresh()->cobro_medio].'.');
    }

    public function contabilizarCobros(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 409);
        $ok = 0;
        $err = [];
        foreach (Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->where('cobrada', true)->whereNull('cobro_asiento_id')->get() as $f) {
            try {
                $this->conta->contabilizarCobro($f, $f->cobro_medio ?: self::medioDe($f->forma_pago), $request->user()->id);
                $ok++;
            } catch (InvalidArgumentException $e) {
                $err[] = $f->num.': '.$e->getMessage();
            }
        }

        return $this->resultadoLote($ok, $err, 'cobros', $c);
    }

    // ------------------------------------------------------------ pagos
    private function recibidaPropia(Request $r, FacturaRecibida $f, string $clase): Cliente
    {
        $c = $this->ctx($r);
        abort_unless($c && $f->cliente_id === $c->id && $f->clase === Tercero::CLASES[$clase][3], 404);

        return $c;
    }

    public function marcarPagoProveedor(Request $r, FacturaRecibida $factura): RedirectResponse
    {
        return $this->marcarPago($r, $factura, 'proveedor');
    }

    public function marcarPagoAcreedor(Request $r, FacturaRecibida $factura): RedirectResponse
    {
        return $this->marcarPago($r, $factura, 'acreedor');
    }

    private function marcarPago(Request $request, FacturaRecibida $factura, string $clase): RedirectResponse
    {
        $c = $this->recibidaPropia($request, $factura, $clase);
        if ($factura->pago_asiento_id) {
            return back()->with('aviso', 'El pago de '.$factura->num.' ya está contabilizado. Para deshacerlo, borra antes su asiento en Contabilidad › Libro diario.');
        }
        $fecha = $this->fecha($request);
        $factura->update(['pagada_at' => $factura->pagada_at ? null : $fecha]);
        Auditoria::registrar('gestion.pago', $factura->num.($factura->pagada_at ? ' pagada' : ': pago anulado'), $factura, $c->id);

        return back()->with('ok', 'Factura '.$factura->num.($factura->pagada_at ? ' pagada el '.Formato::fecha($fecha).'.' : ': pago anulado.'));
    }

    public function contabilizarPagoProveedor(Request $r, FacturaRecibida $factura): RedirectResponse
    {
        return $this->contabilizarPago($r, $factura, 'proveedor');
    }

    public function contabilizarPagoAcreedor(Request $r, FacturaRecibida $factura): RedirectResponse
    {
        return $this->contabilizarPago($r, $factura, 'acreedor');
    }

    private function contabilizarPago(Request $request, FacturaRecibida $factura, string $clase): RedirectResponse
    {
        $c = $this->recibidaPropia($request, $factura, $clase);
        try {
            $a = $this->conta->contabilizarPago($factura, $this->medio($request), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('contabilidad.pago', 'Pago de '.$factura->num.' contabilizado (asiento '.$a->numero.')', $a, $c->id);

        return back()->with('ok', 'Pago de '.$factura->num.' contabilizado: asiento nº '.$a->numero.' por '.Contabilidad::MEDIOS[$factura->fresh()->pago_medio].'.');
    }

    public function contabilizarPagosProveedores(Request $r): RedirectResponse
    {
        return $this->contabilizarPagos($r, 'proveedor');
    }

    public function contabilizarPagosAcreedores(Request $r): RedirectResponse
    {
        return $this->contabilizarPagos($r, 'acreedor');
    }

    private function contabilizarPagos(Request $request, string $clase): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 409);
        $ok = 0;
        $err = [];
        foreach (FacturaRecibida::with('tercero')->where('cliente_id', $c->id)->where('clase', Tercero::CLASES[$clase][3])->whereNotNull('pagada_at')->whereNull('pago_asiento_id')->get() as $f) {
            try {
                $this->conta->contabilizarPago($f, $f->pago_medio ?: self::medioDe($f->forma_pago), $request->user()->id);
                $ok++;
            } catch (InvalidArgumentException $e) {
                $err[] = $f->num.': '.$e->getMessage();
            }
        }

        return $this->resultadoLote($ok, $err, 'pagos', $c);
    }

    // ------------------------------------------------------------ comunes
    private function fecha(Request $r): string
    {
        $f = (string) $r->input('fecha', '');
        $hoy = Plazos::hoy()->toDateString();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && $f <= $hoy ? $f : $hoy;
    }

    private function medio(Request $r): string
    {
        return $r->input('medio') === 'caja' ? 'caja' : 'banco';
    }

    private function resultadoLote(int $ok, array $err, string $que, Cliente $c): RedirectResponse
    {
        if ($ok) {
            Auditoria::registrar('contabilidad.'.$que, $ok.' '.$que.' contabilizados', null, $c->id);
        }
        $r = back()->with('ok', $ok ? $ok.' '.$que.' contabilizados.' : 'No había '.$que.' marcados pendientes de contabilizar.');

        return $err ? $r->with('aviso', 'No se pudieron contabilizar: '.implode(' · ', array_slice($err, 0, 5))) : $r;
    }
}
