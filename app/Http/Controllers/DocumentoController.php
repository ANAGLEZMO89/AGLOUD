<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Documento;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\Parametros;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Gestor documental (despacho y portal del cliente).
 * Los archivos viven cifrados en storage/app/privado y SOLO se sirven a
 * través de este controlador, tras comprobar permisos. Cada acceso queda en
 * el registro de auditoría.
 */
class DocumentoController extends Controller
{
    private const ORIG = ['Todos', 'Por revisar', 'Del cliente', 'Compartidos', 'Caducan pronto'];

    public function __construct(private AlmacenDocumentos $almacen) {}

    private function ctx(Request $request): ?Cliente
    {
        return $request->attributes->get('ctx');
    }

    private function esPortal(Request $request): bool
    {
        return $request->user()->esPortal();
    }

    private function rutaIndex(Request $request, array $q = []): string
    {
        return $this->esPortal($request) ? route('portal.documentos', $q) : route('documentos.index', $q);
    }

    // ------------------------------------------------------------------ listado
    public function index(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/documentos'])->with('aviso', 'Elige primero con quién vas a trabajar.');
        }
        $portal = $this->esPortal($request);
        $fold = (string) $request->query('f', 'all');
        $yr = (string) $request->query('y', 'Todos');
        $oc = max(0, min(4, (int) $request->query('o', 0)));
        $q = trim((string) $request->query('q', ''));
        $par = Parametros::get('documentos');
        $diasCad = (int) $par['aviso_caducidad'];
        $limite60 = now()->addDays($diasCad)->toDateString();

        $base = Documento::where('cliente_id', $c->id)->gestor();
        $vivos = (clone $base)->vivos();
        $todosVivos = (clone $vivos)->get(['id', 'carpeta', 'fecha', 'tamano', 'por_revisar', 'caduca', 'tipo', 'compartido']);
        $nTrash = (clone $base)->papelera()->count();

        $lista = ($fold === 'trash' ? (clone $base)->papelera() : (clone $vivos))
            ->when($fold !== 'all' && $fold !== 'trash', fn ($w) => str_contains($fold, '/') ? $w->where('carpeta', $fold) : $w->where('carpeta', 'like', $fold.'/%'))
            ->when($yr !== 'Todos', fn ($w) => $w->whereYear('fecha', (int) $yr))
            ->when($oc === 1, fn ($w) => $w->where('por_revisar', true))
            ->when($oc === 2, fn ($w) => $w->where('origen', 'Portal del cliente'))
            ->when($oc === 3, fn ($w) => $w->where('compartido', true))
            ->when($oc === 4, fn ($w) => $w->whereNotNull('caduca')->where('caduca', '<=', $limite60))
            ->when($q !== '', function ($w) use ($q) {
                $ql = mb_strtolower($q);
                $carpetas = collect(ClasificadorDocumentos::carpetasValidas())->filter(fn ($l) => str_contains(mb_strtolower($l), $ql))->keys()->all();
                $tipos = collect(config('agcloud.tipos_documento'))->filter(fn ($l) => str_contains(mb_strtolower($l), $ql))->keys()->all();
                $w->where(fn ($x) => $x->where('nombre', 'like', "%$q%")->orWhereIn('carpeta', $carpetas ?: ['-'])->orWhereIn('tipo', $tipos ?: ['-']));
            })
            ->with('autor')->orderByDesc('fecha')->orderBy('nombre')->limit(500)->get();

        $cnt = fn (string $p) => $todosVivos->filter(fn ($d) => str_contains($p, '/') ? $d->carpeta === $p : str_starts_with($d->carpeta, $p.'/'))->count();
        $grupos = collect(config('agcloud.carpetas'))
            ->reject(fn ($g, $id) => $c->tipo === 'Particular' && in_array($id, ['mercantil', 'laboral'], true))
            ->map(fn ($g, $id) => ['id' => $id, 'l' => $g[0], 'n' => $cnt($id), 'subs' => collect($g[1])->map(fn ($l, $s) => ['id' => $id.'/'.$s, 'l' => $l, 'n' => $cnt($id.'/'.$s)])->values()]);

        $soon = $todosVivos->filter(fn ($d) => $d->caduca && $d->caduca->toDateString() <= $limite60);
        $kb = $todosVivos->sum('tamano') / 1024;
        $kpis = [
            ['Documentos', Formato::entero($todosVivos->count()), Formato::num($kb / 1024, 1).' MB de '.Formato::num($par['cupo_mb'] / 1024, $par['cupo_mb'] % 1024 ? 1 : 0).' GB'],
            ['Por revisar', Formato::entero($todosVivos->where('por_revisar', true)->count()), 'Subidos por el cliente'],
            ['Caducan en '.$diasCad.' días', Formato::entero($soon->count()), $soon->count() ? $soon->take(2)->map(fn ($d) => config('agcloud.tipos_documento.'.$d->tipo, 'Documento'))->implode(' · ') : 'Nada a la vista'],
            ['Compartidos en el portal', Formato::entero($todosVivos->where('compartido', true)->count()), 'Visibles para el cliente'],
        ];
        if ($portal) {
            unset($kpis[1], $kpis[3]);
        }

        $years = $todosVivos->map(fn ($d) => $d->fecha->format('Y'))->unique()->sortDesc()->values();
        $borradores = Documento::where('cliente_id', $c->id)->where('borrador', true)->where('subido_por', $request->user()->id)->orderBy('id')->get();
        $sel = $request->query('sel') ? Documento::where('cliente_id', $c->id)->gestor()->find((int) $request->query('sel')) : null;
        if ($sel && ! Gate::allows('view', $sel)) {
            $sel = null;
        }

        $foldTitle = match (true) {
            $fold === 'all' => 'Todos los documentos',
            $fold === 'trash' => 'Papelera',
            str_contains($fold, '/') => Formato::carpeta($fold),
            default => config('agcloud.carpetas.'.$fold.'.0', 'Documentos'),
        };

        return view('documentos.index', [
            'titulo' => 'Documentos', 'activo' => 'documentos', 'c' => $c, 'portal' => $portal,
            'fold' => $fold, 'yr' => $yr, 'oc' => $oc, 'q' => $q, 'orig' => self::ORIG, 'years' => $years,
            'lista' => $lista, 'grupos' => $grupos, 'nVivos' => $todosVivos->count(), 'nTrash' => $nTrash,
            'kpis' => $kpis, 'borradores' => $borradores, 'sel' => $sel, 'foldTitle' => $foldTitle,
            'carpetas' => ClasificadorDocumentos::carpetasValidas(), 'rutaIndex' => fn ($x = []) => $this->rutaIndex($request, array_filter(array_merge(['f' => $fold === 'all' ? null : $fold, 'y' => $yr === 'Todos' ? null : $yr, 'o' => $oc ?: null, 'q' => $q ?: null], $x), fn ($v) => $v !== null && $v !== '')),
            'nRevisar' => $portal ? 0 : EscritorioController::nRevisar(),
        ]);
    }

    // ------------------------------------------------------------------ subida
    public function subir(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $request->validate([
            'archivos' => ['required', 'array', 'max:20'],
            'archivos.*' => ['file', 'max:'.config('agcloud.max_upload_kb')],
        ], [
            'archivos.*.max' => 'Cada archivo puede ocupar como máximo '.Formato::entero(config('agcloud.max_upload_kb') / 1024).' MB.',
            'archivos.max' => 'Sube como máximo 20 archivos a la vez.',
        ]);
        $n = 0;
        foreach ($request->file('archivos') as $f) {
            $orig = ClasificadorDocumentos::nombreSeguro($f->getClientOriginalName());
            if (preg_match('/\.(php\d?|phtml|phar|exe|bat|cmd|sh|js|html?|svg)$/i', $orig)) {
                return back()->with('aviso', 'Tipo de archivo no permitido: '.$orig);
            }
            $p = ClasificadorDocumentos::proponer($orig, $c->nombre);
            $g = $this->almacen->guardar($f, $c->id);
            Documento::create([
                'cliente_id' => $c->id, 'ambito' => 'gestor', 'borrador' => true, 'carpeta' => $p['carpeta'], 'tipo' => $p['tipo'],
                'nombre' => ClasificadorDocumentos::nombreSeguro($p['nombre']), 'nombre_original' => $orig, 'fecha' => $p['fecha'],
                'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'],
                'origen' => $this->esPortal($request) ? 'Portal del cliente' : 'Despacho', 'subido_por' => $request->user()->id,
            ]);
            $n++;
        }

        return redirect($this->rutaIndex($request));
    }

    public function confirmar(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $carpetas = array_keys(ClasificadorDocumentos::carpetasValidas());
        $request->validate([
            'docs' => ['required', 'array'],
            'docs.*.nombre' => ['required', 'string', 'max:180'],
            'docs.*.carpeta' => ['required', Rule::in($carpetas)],
        ]);
        $portal = $this->esPortal($request);
        $items = Documento::where('cliente_id', $c->id)->where('borrador', true)->where('subido_por', $request->user()->id)->get()->keyBy('id');
        $n = 0;
        foreach ((array) $request->input('docs') as $id => $x) {
            $d = $items->get((int) $id);
            if (! $d) {
                continue;
            }
            $d->fill([
                'nombre' => ClasificadorDocumentos::nombreSeguro($x['nombre']), 'carpeta' => $x['carpeta'], 'borrador' => false,
                'compartido' => $portal, 'por_revisar' => $portal,
            ])->save();
            $n++;
        }
        // Los que el usuario quitó de la lista se descartan
        foreach ($items->except(array_map('intval', array_keys((array) $request->input('docs')))) as $d) {
            $this->almacen->borrar($d->ruta);
            $d->delete();
        }
        Auditoria::registrar('documento.subida', $n.' documento(s) subido(s) a '.$c->nombre, null, $c->id);

        $msg = $portal
            ? $n.($n === 1 ? ' documento enviado' : ' documentos enviados').' a tu asesor. Gracias.'
            : $n.($n === 1 ? ' documento guardado y clasificado.' : ' documentos guardados y clasificados.');

        return redirect($this->rutaIndex($request))->with('ok', $msg);
    }

    public function cancelar(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        foreach (Documento::where('cliente_id', $c->id)->where('borrador', true)->where('subido_por', $request->user()->id)->get() as $d) {
            $this->almacen->borrar($d->ruta);
            $d->delete();
        }

        return redirect($this->rutaIndex($request));
    }

    // ------------------------------------------------------------------ acceso al archivo
    public function ver(Documento $documento)
    {
        $this->authorize('view', $documento);
        abort_unless($documento->esPdf() || $documento->esImagen(), 415);
        Auditoria::registrar('documento.vista', 'Vista previa de '.$documento->nombre, $documento);

        return $this->stream($documento, 'inline');
    }

    public function descargar(Documento $documento)
    {
        $this->authorize('view', $documento);
        Auditoria::registrar('documento.descarga', 'Descarga de '.$documento->nombre, $documento);

        return $this->stream($documento, 'attachment');
    }

    private function stream(Documento $d, string $disp)
    {
        abort_unless($this->almacen->existe($d->ruta), 404, 'El archivo no está en el servidor.');
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $d->nombre);
        $mime = $disp === 'inline' ? $d->mime : 'application/octet-stream';

        $h = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $d->tamano,
            'Content-Disposition' => HeaderUtils::makeDisposition($disp, $d->nombre, $ascii),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($disp === 'attachment') {
            $h['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        return response()->stream(fn () => $this->almacen->emitir($d->ruta), 200, $h);
    }

    // ------------------------------------------------------------------ edición
    public function renombrar(Request $request, Documento $documento)
    {
        $this->authorize('update', $documento);
        $v = $request->validate(['nombre' => ['required', 'string', 'max:180']]);
        $antes = $documento->nombre;
        $documento->update(['nombre' => ClasificadorDocumentos::nombreSeguro($v['nombre'])]);
        Auditoria::registrar('documento.renombre', 'Renombra '.$antes.' → '.$documento->nombre, $documento);

        return $this->volver($request, $documento, 'Documento renombrado.');
    }

    public function mover(Request $request, Documento $documento)
    {
        $this->authorize('update', $documento);
        $v = $request->validate(['carpeta' => ['required', Rule::in(array_keys(ClasificadorDocumentos::carpetasValidas()))]]);
        $documento->update(['carpeta' => $v['carpeta']]);
        Auditoria::registrar('documento.mover', 'Mueve '.$documento->nombre.' a '.Formato::carpeta($v['carpeta']), $documento);

        return $this->volver($request, $documento, 'Movido a '.Formato::carpeta($v['carpeta']).'.');
    }

    public function papelera(Request $request, Documento $documento)
    {
        $this->authorize('update', $documento);
        $documento->update(['eliminado_at' => now(), 'eliminado_por' => $request->user()->id]);
        Auditoria::registrar('documento.papelera', 'Envía a la papelera '.$documento->nombre, $documento);
        $keep = $documento->conservarHasta();
        $msg = 'Movido a la papelera.'.($keep && $keep[0]->isFuture() ? ' Ojo: la ley obliga a conservarlo hasta el '.Formato::fecha($keep[0]).' ('.$keep[1].').' : '');

        return redirect($this->rutaIndex($request, ['f' => $request->input('f')]))->with($keep && $keep[0]->isFuture() ? 'aviso' : 'ok', $msg);
    }

    public function restaurar(Request $request, Documento $documento)
    {
        $this->authorize('update', $documento);
        $documento->update(['eliminado_at' => null, 'eliminado_por' => null]);
        Auditoria::registrar('documento.restaurar', 'Restaura '.$documento->nombre, $documento);

        return redirect($this->rutaIndex($request, ['f' => 'trash']))->with('ok', 'Documento restaurado.');
    }

    public function compartir(Request $request, Documento $documento)
    {
        $this->authorize('gestionar', $documento);
        $documento->update(['compartido' => ! $documento->compartido]);
        Auditoria::registrar('documento.compartir', ($documento->compartido ? 'Comparte en el portal ' : 'Retira del portal ').$documento->nombre, $documento);

        return $this->volver($request, $documento, $documento->compartido ? 'Visible en el portal del cliente.' : 'Ya no se ve en el portal del cliente.');
    }

    public function revisado(Request $request, Documento $documento)
    {
        $this->authorize('gestionar', $documento);
        $documento->update(['por_revisar' => false]);
        Auditoria::registrar('documento.revisado', 'Marca como revisado '.$documento->nombre, $documento);

        return $this->volver($request, $documento, 'Documento revisado.');
    }

    public function vaciarPapelera(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c && $request->user()->esDespacho(), 403);
        $docs = Documento::where('cliente_id', $c->id)->gestor()->papelera()->get();
        foreach ($docs as $d) {
            $this->almacen->borrar($d->ruta);
            Auditoria::registrar('documento.borrado', 'Borrado definitivo de '.$d->nombre, $d);
            $d->delete();
        }

        return redirect($this->rutaIndex($request, ['f' => 'trash']))->with('ok', 'Papelera vaciada ('.$docs->count().' documentos).');
    }

    /** Índice de documentos (CSV para Excel) */
    public function indice(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $docs = Documento::where('cliente_id', $c->id)->gestor()->vivos()->orderByDesc('fecha')->get();
        Auditoria::registrar('documento.indice', 'Exporta el índice de documentos', null, $c->id);

        return response()->streamDownload(function () use ($docs) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Nombre', 'Carpeta', 'Tipo', 'Fecha', 'Origen', 'Tamaño (KB)', 'Portal', 'Conservar hasta'], ';');
            foreach ($docs as $d) {
                $k = $d->conservarHasta();
                fputcsv($o, [$d->nombre, $d->carpetaTexto(), $d->tipoTexto(), Formato::fecha($d->fecha), $d->origen, Formato::entero(max(1, round($d->tamano / 1024))), $d->compartido ? 'Sí' : 'No', $k ? Formato::fecha($k[0]) : ''], ';');
            }
            fclose($o);
        }, 'indice-documentos-'.ClasificadorDocumentos::slug($c->nombre).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function volver(Request $request, Documento $d, string $msg)
    {
        return redirect($this->rutaIndex($request, ['f' => $request->input('f'), 'sel' => $d->id]))->with('ok', $msg);
    }
}
