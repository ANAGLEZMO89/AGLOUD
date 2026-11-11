<?php

namespace App\Http\Controllers;

use App\Models\BandejaDocumento;
use App\Models\Documento;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Validacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Bandeja de facturas: las emitidas y recibidas pendientes y los documentos subidos.
 * Cada una se abre en una ventana de revisión (documento + datos + lectura OCR) y se contabiliza.
 */
class BandejaController extends ContabilidadController
{
    public function bandeja(Request $request)
    {
        $b = $this->base($request, 'bandeja');
        if (! is_array($b)) {
            return $b;
        }
        $c = $b['c'];
        $rev = null;
        $k = (string) $request->query('revisar', '');
        if (preg_match('/^([erd])(\d+)$/', $k, $m)) {
            $id = (int) $m[2];
            $rev = match ($m[1]) {
                'e' => ($f = Factura::where('cliente_id', $c->id)->whereNull('asiento_id')->where('estado', 'Emitida')->find($id)) ? ['k' => $k, 'clase' => 'Emitida', 'fija' => true, 'f' => $f,
                    'docUrl' => route('facturacion.documento', $f), 'esPdf' => true, 'nif' => $f->receptor_nif, 'nombre' => $f->receptor_nombre, 'num' => $f->num, 'fecha' => $f->fecha->toDateString(),
                    'base' => (float) $f->base, 'iva' => (float) $f->cuota, 'ret' => (float) $f->retencion, 'total' => (float) $f->total, 'cuenta' => Contabilidad::cuentaPar('ventas')] : null,
                'r' => ($f = FacturaRecibida::with(['tercero', 'documento'])->where('cliente_id', $c->id)->whereNull('asiento_id')->find($id)) ? ['k' => $k, 'clase' => 'Recibida', 'fija' => false, 'f' => $f,
                    'docUrl' => $f->documento_id ? route('documentos.ver', $f->documento_id) : null, 'esPdf' => $f->documento?->esPdf() ?? true, 'tercero_id' => $f->tercero_id,
                    'nif' => $f->tercero?->nif, 'nombre' => $f->tercero?->nombre, 'num' => $f->num, 'fecha' => $f->fecha->toDateString(), 'concepto' => $f->concepto, 'base' => (float) $f->base,
                    'iva_pct' => (float) $f->iva_pct, 'ret_pct' => (float) $f->ret_pct, 'deducible' => $f->deducible, 'cuenta' => $this->conta->cuentaGasto($f)] : null,
                default => ($d = BandejaDocumento::with('documento')->where('cliente_id', $c->id)->find($id)) ? ['k' => $k, 'clase' => 'Recibida', 'fija' => false, 'nuevo' => true, 'd' => $d,
                    'docUrl' => route('documentos.ver', $d->documento_id), 'esPdf' => $d->documento?->esPdf() ?? true, 'nif' => '', 'nombre' => '', 'num' => '', 'fecha' => '', 'concepto' => '',
                    'base' => 0, 'iva_pct' => 21, 'ret_pct' => 0, 'deducible' => true, 'cuenta' => Contabilidad::cuentaPar('gasto_servicios')] : null,
            };
        }
        $par = Parametros::get('contabilidad');

        return view('contabilidad.bandeja', $b + ['lista' => $this->pendientes($c, $b['ej']), 'propias' => Contabilidad::propias($c), 'rev' => $rev,
            'terceros' => Tercero::where('cliente_id', $c->id)->orderBy('nombre')->get(), 'catalogo' => $this->catalogo($c),
            'ctaDefecto' => ['Recibida' => Contabilidad::cuentaPar('gasto_servicios'), 'Emitida' => Contabilidad::cuentaPar('ventas'), 'compra' => Contabilidad::cuentaPar('compras')]]);
    }

    /** Sube facturas o tiques (varios a la vez) a la bandeja */
    public function subir(Request $request, AlmacenDocumentos $almacen): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $request->validate(['archivos' => ['required', 'array', 'max:20'], 'archivos.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('agcloud.max_upload_kb')]],
            ['archivos.required' => 'Elige al menos un archivo.', 'archivos.*.mimes' => 'Solo PDF o imágenes (JPG, PNG).']);
        $primero = null;
        foreach ($request->file('archivos') as $file) {
            $g = $almacen->guardar($file, $c->id);
            $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
            $doc = Documento::create([
                'cliente_id' => $c->id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => 'contable/recibidas', 'tipo' => 'fra',
                'nombre' => ClasificadorDocumentos::nombreSeguro(now()->format('Ymd').'_fra_'.pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$ext),
                'nombre_original' => ClasificadorDocumentos::nombreSeguro($file->getClientOriginalName()), 'fecha' => now()->toDateString(),
                'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'], 'origen' => 'Despacho', 'subido_por' => $request->user()->id,
            ]);
            $bd = BandejaDocumento::create(['cliente_id' => $c->id, 'documento_id' => $doc->id, 'origen' => 'Subida manual', 'created_by' => $request->user()->id]);
            $primero ??= $bd;
        }
        $n = count($request->file('archivos'));
        Auditoria::registrar('contabilidad.bandeja', $n.' documento(s) subidos a la bandeja', null, $c->id);

        return redirect()->route('contabilidad.bandeja', ['revisar' => 'd'.$primero->id])->with('ok', $n.' '.($n === 1 ? 'documento subido' : 'documentos subidos').'. Revisa los datos que lee el OCR y contabiliza.');
    }

    public function descartar(Request $request, BandejaDocumento $documento): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && $documento->cliente_id === $c->id, 404);
        $documento->delete();

        return redirect()->route('contabilidad.bandeja')->with('ok', 'Documento quitado de la bandeja. Sigue guardado en Documentos › Contable › Facturas recibidas.');
    }

    /** Guarda lo revisado y contabiliza */
    public function contabilizarRevision(Request $request, string $k): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && preg_match('/^([erd])(\d+)$/', $k, $m), 404);
        $id = (int) $m[2];
        $uid = $request->user()->id;
        try {
            if ($m[1] === 'e') {
                $f = Factura::where('cliente_id', $c->id)->findOrFail($id);
                $a = $this->conta->contabilizarEmitida($f, $uid);
                $msg = 'Factura '.$f->num.' contabilizada (asiento '.$a->numero.').';
            } else {
                $d = $this->datos($request);
                $a = DB::transaction(function () use ($c, $m, $id, $d, $uid, $request) {
                    if ($m[1] === 'r') {
                        $f = FacturaRecibida::where('cliente_id', $c->id)->whereNull('asiento_id')->findOrFail($id);
                        $t = Tercero::where('cliente_id', $c->id)->findOrFail((int) $d['tercero_id']);

                        return $this->guardarRecibida($c, $f, $t, $d, $uid);
                    }
                    $bd = BandejaDocumento::where('cliente_id', $c->id)->findOrFail($id);
                    if ($d['clase'] === 'Emitida') {
                        $a = $this->asientoEmitida($c, $d, $bd->documento_id, $uid);
                    } else {
                        $t = ! empty($d['tercero_id']) ? Tercero::where('cliente_id', $c->id)->findOrFail((int) $d['tercero_id']) : $this->nuevoTercero($c, $d);
                        $a = $this->guardarRecibida($c, new FacturaRecibida(['cliente_id' => $c->id, 'documento_id' => $bd->documento_id, 'created_by' => $uid]), $t, $d, $uid);
                    }
                    $bd->delete();

                    return $a;
                });
                $msg = 'Factura '.$d['num'].' contabilizada (asiento '.$a->numero.').';
            }
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['rev' => $e->getMessage()]);
        }
        Auditoria::registrar('contabilidad.bandeja', $msg, null, $c->id);
        $sig = $this->pendientes($c, $this->conta->ejercicio($c, $a->fecha->year))->first();

        return redirect()->route('contabilidad.bandeja', $sig ? ['revisar' => $sig['k']] : [])->with('ok', $msg.($sig ? ' Siguiente de la bandeja abierta.' : ' Bandeja al día.'));
    }

    private function datos(Request $r): array
    {
        $d = $r->validate([
            'clase' => ['required', Rule::in(['Recibida', 'Emitida'])], 'tercero_id' => ['nullable', 'integer'], 'nombre' => ['nullable', 'string', 'max:255'], 'nif' => ['nullable', 'string', 'max:20'],
            'tipo_ter' => ['nullable', Rule::in(['proveedor', 'acreedor'])], 'num' => ['required', 'string', 'max:60'], 'fecha' => ['required', 'date', 'before_or_equal:today'],
            'concepto' => ['nullable', 'string', 'max:255'], 'base' => ['required', 'string', 'max:20'], 'iva_pct' => ['required', Rule::in(['21', '10', '5', '4', '0'])],
            'ret_pct' => ['nullable', Rule::in(['0', '1', '7', '15', '19'])], 'deducible' => ['nullable', 'boolean'], 'cuenta' => ['required', 'regex:/^\d{3,9}$/'],
        ], ['num.required' => 'Indica el número de la factura.', 'fecha.required' => 'Indica la fecha de la factura.', 'base.required' => 'Indica la base imponible.', 'cuenta.required' => 'Indica la cuenta de gasto o ingreso.']);
        $d['base'] = Formato::parseNum($d['base']);
        if ($d['base'] == 0) {
            throw ValidationException::withMessages(['rev' => 'La base imponible no puede ser cero.']);
        }
        $d['nif'] = strtoupper(preg_replace('/[\s.-]/', '', (string) ($d['nif'] ?? '')));
        if ($d['nif'] !== '' && ! Validacion::nifOk($d['nif'])) {
            throw ValidationException::withMessages(['rev' => 'El NIF '.$d['nif'].' no es válido. Revísalo (el OCR puede haberlo leído mal).']);
        }
        $cta = Contabilidad::a9($d['cuenta']);
        if ($d['clase'] === 'Recibida' && ! in_array($cta[0], ['6', '2'], true)) {
            throw ValidationException::withMessages(['rev' => 'La cuenta de gasto debe ser del grupo 6 (o 2 si es inmovilizado).']);
        }
        if ($d['clase'] === 'Emitida' && $cta[0] !== '7') {
            throw ValidationException::withMessages(['rev' => 'La cuenta de ingreso debe ser del grupo 7.']);
        }
        if ($d['clase'] === 'Recibida' && empty($d['tercero_id']) && trim((string) ($d['nombre'] ?? '')) === '') {
            throw ValidationException::withMessages(['rev' => 'Elige el proveedor o acreedor, o escribe su nombre para darlo de alta.']);
        }
        $d['cuenta'] = $cta;
        $d['ret_pct'] = (float) ($d['ret_pct'] ?? 0);
        $d['deducible'] = $r->boolean('deducible');

        return $d;
    }

    private function nuevoTercero($c, array $d): Tercero
    {
        $clase = ($d['tipo_ter'] ?? '') === 'proveedor' ? 'proveedor' : 'acreedor';
        if ($d['nif'] !== '' && ($t = Tercero::where('cliente_id', $c->id)->where('nif', $d['nif'])->first())) {
            return $t;
        }
        $p = Parametros::get($clase === 'proveedor' ? 'proveedores' : 'acreedores');

        return Tercero::create(['cliente_id' => $c->id, 'clase' => $clase, 'cuenta' => Tercero::siguienteCuenta($c->id, $clase), 'nombre' => trim((string) $d['nombre']), 'nif' => $d['nif'] ?: null,
            'tipo' => $clase === 'acreedor' ? ($d['ret_pct'] > 0 ? 'Profesional' : 'Servicios') : null, 'retencion' => $clase === 'acreedor' ? $d['ret_pct'] : 0,
            'forma_pago' => $p['forma_pago'] ?? 'Transferencia', 'pago_dias' => (int) ($p['pago_dias'] ?? 0)]);
    }

    private function guardarRecibida($c, FacturaRecibida $f, Tercero $t, array $d, int $uid)
    {
        $ret = $t->clase === 'acreedor' ? $d['ret_pct'] : 0;
        $f->fill(['cliente_id' => $c->id, 'tercero_id' => $t->id, 'clase' => Tercero::CLASES[$t->clase][3], 'num' => trim($d['num']), 'fecha' => $d['fecha'],
            'concepto' => trim((string) ($d['concepto'] ?? '')) ?: 'Factura '.trim($d['num']), 'base' => $d['base'], 'iva_pct' => (float) $d['iva_pct'], 'ret_pct' => $ret,
            'deducible' => $t->clase === 'proveedor' ? true : $d['deducible'], 'forma_pago' => $f->forma_pago ?: $t->forma_pago,
            'vencimiento' => $f->vencimiento ?? \Carbon\CarbonImmutable::parse($d['fecha'])->addDays((int) $t->pago_dias)->toDateString()] + FacturaRecibida::importes($d['base'], (float) $d['iva_pct'], $ret));
        $f->save();

        return $this->conta->contabilizarRecibida($f->fresh('tercero'), $uid, $d['cuenta']);
    }

    /** Factura emitida fuera de AG Cloud (subida como documento): asiento directo con su cliente 430… */
    private function asientoEmitida($c, array $d, ?int $docId, int $uid)
    {
        $cli = $this->conta->cuentaCliente($c, $d['nif'] ?: null, trim((string) ($d['nombre'] ?? '')) ?: null);
        $iva = round($d['base'] * (float) $d['iva_pct'] / 100, 2);
        $ret = round($d['base'] * $d['ret_pct'] / 100, 2);
        $tot = round($d['base'] + $iva - $ret, 2);
        $ls = [[$cli, $tot, 0, $d['nombre'] ?? null, $d['num']]];
        if ($ret) {
            $ls[] = [Contabilidad::cuentaPar('ret_soportada'), $ret, 0, null, $d['num']];
        }
        $ls[] = [$d['cuenta'], 0, $d['base'], null, $d['num']];
        if ($iva) {
            $ls[] = [Contabilidad::cuentaPar('iva_repercutido'), 0, $iva, null, $d['num']];
        }
        $a = $this->conta->asentar($c, $d['fecha'], 'Fra. emitida '.$d['num'].' · '.(trim((string) ($d['nombre'] ?? '')) ?: 'cliente'), $ls, 'Factura emitida', null, null, $uid);
        $a->update(['documento_id' => $docId]);

        return $a;
    }
}
