<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\FacturacionConfig;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\Parametros;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Productos y servicios de la empresa elegida. Se guardan en su configuración de facturación
 * y aparecen al escribir una línea de factura o de presupuesto (rellenan precio e IVA).
 */
class ProductoController extends Controller
{
    public const TIPOS = ['Producto', 'Servicio'];

    public const IVAS = ['21', '10', '5', '4', '0'];

    private function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    public function index(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/productos'])->with('aviso', 'Elige primero la empresa cuyos productos vas a gestionar.');
        }
        $cfg = FacturacionConfig::de($c);
        $cat = array_values((array) ($cfg->catalogo ?? []));
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $tipo = in_array($request->query('tipo'), self::TIPOS, true) ? $request->query('tipo') : null;
        $lista = collect($cat)->map(fn ($p, $i) => $p + ['i' => $i, 'tipo' => $p['tipo'] ?? 'Servicio', 'ref' => $p['ref'] ?? '', 'desc' => $p['desc'] ?? ''])
            ->filter(fn ($p) => (! $tipo || $p['tipo'] === $tipo) && ($q === '' || str_contains(mb_strtolower($p['ref'].' '.$p['nombre'].' '.$p['desc']), $q)))
            ->sortBy(fn ($p) => mb_strtolower($p['nombre']))->values();
        $editar = $request->query('editar') !== null && isset($cat[(int) $request->query('editar')]) ? ['i' => (int) $request->query('editar')] + $cat[(int) $request->query('editar')] : null;

        return view('gestion.productos', ['titulo' => 'Productos', 'activo' => 'productos', 'c' => $c, 'lista' => $lista, 'total' => count($cat), 'q' => (string) $request->query('q', ''),
            'tipo' => $tipo, 'editar' => $editar, 'form' => $request->boolean('nuevo') || $editar, 'par' => Parametros::get('productos'),
            'nProd' => collect($cat)->where('tipo', 'Producto')->count(), 'nServ' => collect($cat)->filter(fn ($p) => ($p['tipo'] ?? 'Servicio') === 'Servicio')->count()]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 409);
        $d = $request->validate([
            'i' => ['nullable', 'integer', 'min:0'], 'ref' => ['nullable', 'string', 'max:40'], 'tipo' => ['required', Rule::in(self::TIPOS)],
            'nombre' => ['required', 'string', 'max:120'], 'desc' => ['nullable', 'string', 'max:300'], 'precio' => ['required', 'string', 'max:20'], 'iva' => ['required', Rule::in(self::IVAS)],
        ], ['nombre.required' => 'Escribe el nombre del producto o servicio.', 'precio.required' => 'Indica el precio sin IVA.']);
        $precio = Formato::parseNum($d['precio']);
        if ($precio < 0 || $precio > 9999999) {
            throw ValidationException::withMessages(['form' => 'El precio no es válido.']);
        }
        $cfg = FacturacionConfig::de($c);
        $cat = array_values((array) ($cfg->catalogo ?? []));
        $i = isset($d['i']) && isset($cat[(int) $d['i']]) ? (int) $d['i'] : null;
        $nombre = trim($d['nombre']);
        foreach ($cat as $k => $p) {
            if ($k !== $i && mb_strtolower($p['nombre']) === mb_strtolower($nombre)) {
                throw ValidationException::withMessages(['form' => 'Ya hay un producto o servicio llamado «'.$nombre.'».']);
            }
        }
        $item = ['ref' => trim((string) ($d['ref'] ?? '')), 'tipo' => $d['tipo'], 'nombre' => $nombre, 'desc' => trim((string) ($d['desc'] ?? '')), 'precio' => round($precio, 2), 'iva' => (int) $d['iva']];
        if ($i === null) {
            $cat[] = $item;
        } else {
            $cat[$i] = $item;
        }
        $cfg->catalogo = array_values($cat);
        $cfg->save();
        Auditoria::registrar('gestion.producto', ($i === null ? 'Alta: ' : 'Modificado: ').$nombre.' · '.Formato::eur($precio), null, $c->id);

        return redirect()->route('productos.index')->with('ok', $d['tipo'].' «'.$nombre.'» guardado. Ya aparece al facturar.');
    }

    public function borrar(Request $request, int $indice): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 409);
        $cfg = FacturacionConfig::de($c);
        $cat = array_values((array) ($cfg->catalogo ?? []));
        abort_unless(isset($cat[$indice]), 404);
        $nombre = $cat[$indice]['nombre'];
        unset($cat[$indice]);
        $cfg->catalogo = array_values($cat);
        $cfg->save();
        Auditoria::registrar('gestion.producto.baja', 'Eliminado: '.$nombre, null, $c->id);

        return redirect()->route('productos.index')->with('ok', '«'.$nombre.'» eliminado. Las facturas ya emitidas no cambian.');
    }
}
