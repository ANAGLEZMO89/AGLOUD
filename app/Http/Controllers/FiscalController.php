<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\FiscalModelo;
use App\Services\Auditoria;
use App\Services\Fiscal;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Modelos fiscales de la empresa elegida: detección, borradores y marca de presentado. */
class FiscalController extends Controller
{
    public function __construct(private Fiscal $fiscal) {}

    private function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    private function sinEmpresa(): RedirectResponse
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/fiscal'])->with('aviso', 'Elige primero la empresa cuyos modelos fiscales vas a preparar.');
    }

    /** Estado de un modelo en la lista: [texto, fondo, color, siguiente periodo] */
    private function estado(Cliente $c, string $code, bool $aplica, array $regs): array
    {
        $per = Fiscal::per($code);
        if (! $aplica) {
            return ['No aplica', '#ECECE7', '#6A6A65', null];
        }
        if ($per === 'E') {
            return ['Si hay cambios', '#ECECE7', '#3C3C3C', null];
        }
        $hoy = Plazos::hoy();
        $aviso = (int) Parametros::get('fiscal')['aviso_dias'];
        foreach (Fiscal::candidatos($code, $hoy->year) as [$y, $p, $plazo]) {
            if (isset($regs[$code.'|'.$y.'|'.$p]) || ! $plazo || $plazo->lt($hoy)) {
                continue;
            }
            $lbl = Fiscal::etiquetaPeriodo($code, $y, $p);
            $cerca = $hoy->diffInDays($plazo) <= $aviso;

            return ['Pendiente '.$lbl, $cerca ? '#FDE7E7' : '#FCEBCB', $cerca ? '#8A1C1C' : '#6E4300', [$y, $p, $plazo]];
        }

        return ['Al día', '#E6F6A8', '#2E3A00', null];
    }

    public function index(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $P = $this->fiscal->parametros($c);
        $R = Fiscal::detectar($c, $P);
        $presentados = FiscalModelo::where('cliente_id', $c->id)->whereNotNull('presentado_at')->get()
            ->mapWithKeys(fn ($m) => [$m->modelo.'|'.$m->anio.'|'.$m->periodo => true])->all();
        $todos = $request->query('ver') === 'todos';
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $grupos = [];
        foreach (Fiscal::CATS as $cat) {
            $mods = [];
            foreach (Fiscal::MODELOS as $code => [$nombre, $cMod, $per]) {
                if ($cMod !== $cat || (! $todos && ! isset($R[$code])) || ($q !== '' && ! str_contains(mb_strtolower($code.' '.$nombre), $q))) {
                    continue;
                }
                $aplica = isset($R[$code]);
                [$st, $bg, $fg, $sig] = $this->estado($c, (string) $code, $aplica, $presentados);
                $sub = ($aplica ? $R[$code].' · ' : '').Fiscal::PERTXT[$per].($aplica && $sig ? ' · Plazo '.Formato::fecha($sig[2]) : '');
                $mods[] = ['code' => (string) $code, 'name' => $nombre, 'sub' => $sub, 'st' => $st, 'bg' => $bg, 'fg' => $fg,
                    'url' => route('fiscal.modelo', array_filter(['codigo' => $code, 'anio' => $sig[0] ?? null, 'per' => $sig[1] ?? null]))];
            }
            if ($mods) {
                $grupos[] = ['cat' => $cat, 'mods' => $mods];
            }
        }
        $det = array_keys(array_filter($R, fn ($k) => $k !== '036', ARRAY_FILTER_USE_KEY));

        return view('fiscal.index', ['titulo' => 'Fiscal', 'activo' => 'fiscal', 'c' => $c, 'P' => $P, 'R' => $R, 'grupos' => $grupos, 'det' => $det,
            'todos' => $todos, 'q' => (string) $request->query('q', ''), 'abrirParam' => $request->boolean('parametros') || $request->session()->has('errors')]);
    }

    public function guardarParametros(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate([
            'nif' => ['nullable', 'string', 'max:15'], 'razon' => ['nullable', 'string', 'max:120'], 'nombre' => ['nullable', 'string', 'max:60'],
            'tel' => ['nullable', 'string', 'max:30'], 'contacto' => ['nullable', 'string', 'max:80'],
            'ivaReg' => ['nullable', 'in:General,Simplificado,Recargo de equivalencia,Exento'],
            'edMet' => ['nullable', 'in:Estimación directa simplificada,Estimación directa normal,Estimación objetiva'],
            'tipoIS' => ['nullable', 'in:25,23,15'], 'modal202' => ['nullable', 'in:Artículo 40.2,Artículo 40.3'],
            'comuneros' => ['nullable', 'integer', 'min:2', 'max:99'], 'trab' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'iban' => ['nullable', 'string', 'max:40'], 'formaPago' => ['nullable', 'in:Ingreso,Domiciliación'],
        ], ['comuneros.min' => 'Una comunidad de bienes tiene al menos 2 comuneros.']);
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $request->input('iban')));
        if ($iban !== '' && ! \App\Support\Validacion::iban($iban)) {
            return back()->withErrors(['iban' => 'El IBAN no es válido. Revisa los dígitos de control.'])->withInput()->with('abrir', 1);
        }
        $this->fiscal->guardarParametros($c, $request->all(), $request->user()->id);
        Auditoria::registrar('fiscal', 'Parámetros fiscales guardados', null, $c->id);

        return redirect()->route('fiscal.index')->with('ok', 'Parámetros fiscales de '.$c->nombre.' guardados. Modelos actualizados.');
    }

    /** Modelo, año y periodo pedidos (o el siguiente pendiente) */
    private function periodo(Request $r, Cliente $c, string $code): array
    {
        $per = Fiscal::per($code);
        $hoy = Plazos::hoy();
        $anio = (int) $r->input('anio', 0);
        $p = (string) $r->input('per', '');
        if ($anio < 2000 || $anio > $hoy->year + 1 || ! in_array($p, Fiscal::PERIODOS[$per], true)) {
            $pres = FiscalModelo::where('cliente_id', $c->id)->where('modelo', $code)->whereNotNull('presentado_at')->get()
                ->mapWithKeys(fn ($m) => [$m->anio.'|'.$m->periodo => true])->all();
            $sig = null;
            foreach (Fiscal::candidatos($code, $hoy->year) as [$y, $pp, $plazo]) {
                if (! isset($pres[$y.'|'.$pp]) && (! $plazo || $plazo->gte($hoy))) {
                    $sig = [$y, $pp];
                    break;
                }
            }
            $lista = Fiscal::PERIODOS[$per];
            [$anio, $p] = $sig ?? [$per === 'A' ? $hoy->year - 1 : $hoy->year, $lista[count($lista) - 1]];
            if ($per === 'E') {
                [$anio, $p] = [$hoy->year, 'E'];
            }
        }

        return [$anio, $p];
    }

    public function modelo(Request $request, string $codigo)
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        $calc = $this->fiscal->calcular($c, $codigo, $anio, $p);
        $per = Fiscal::per($codigo);
        $hoy = Plazos::hoy();
        $plazo = Fiscal::plazo($codigo, $anio, $p);
        $reg = $calc['reg'];
        $R = Fiscal::detectar($c, $calc['P']);
        // Chips de periodo: trimestres o pagos del año elegido; años en los anuales
        $chips = $per === 'A' ? collect([$hoy->year - 1, $hoy->year])->map(fn ($y) => [(string) $y, $y, '0A'])->all()
            : ($per === 'E' ? [['Alta o modificación', $hoy->year, 'E']] : collect(Fiscal::PERIODOS[$per])->map(fn ($x) => [$x.' '.$anio, $anio, $x])->all());
        $presentado = $reg?->presentado_at !== null;
        $estado = $presentado ? ['Presentado el '.Formato::fecha($reg->presentado_at), '#E6F6A8', '#2E3A00']
            : ($plazo && $plazo->lt($hoy) ? ['Plazo vencido · sin marcar como presentado', '#FDE7E7', '#8A1C1C']
            : ($reg?->borrador_at ? ['Borrador guardado', '#FCEBCB', '#6E4300'] : ['Pendiente', '#FCEBCB', '#6E4300']));
        $origen = $codigo === '036' ? 'Datos tomados de la ficha y de los parámetros del cliente.'
            : ($c->tipo === 'Particular' ? 'Este cliente no lleva contabilidad en la plataforma. Introduce los importes en las casillas editables.'
            : 'Calculado solo con el libro diario de '.$c->nombre.': '.$calc['n'].' asientos del periodo. Si algo no cuadra, corrige la casilla y guarda el borrador.');

        return view('fiscal.modelo', ['titulo' => 'Modelo '.$codigo, 'activo' => 'fiscal', 'c' => $c, 'code' => $codigo, 'nombre' => Fiscal::nombre($codigo),
            'anio' => $anio, 'p' => $p, 'perLbl' => Fiscal::etiquetaPeriodo($codigo, $anio, $p), 'plazo' => $plazo, 'chips' => $chips, 'calc' => $calc,
            'estado' => $estado, 'presentado' => $presentado, 'aplica' => isset($R[$codigo]), 'origen' => $origen, 'reg' => $reg,
            'url' => Fiscal::URL[$codigo] ?? Fiscal::URL_TODOS, 'aniosT' => array_values(array_unique([$hoy->year - 1, $hoy->year, $anio]))]);
    }

    /** Recalcula con las casillas del formulario sin guardar (para ver los totales al momento) */
    public function calcular(Request $request, string $codigo): JsonResponse
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        abort_unless($c, 409);
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        $calc = $this->fiscal->calcular($c, $codigo, $anio, $p, $this->casillasForm($request), (string) $request->input('tipo_decl', ''));

        return response()->json([
            'casillas' => collect($calc['filas'])->filter(fn ($f) => isset($f['id']) && ! $f['ed'])->mapWithKeys(fn ($f) => [$f['id'] => $f['txt']])->all(),
            'res' => $calc['res'] ? Formato::eur($calc['resV']) : '', 'resLbl' => $calc['resLbl'], 'tipoOpts' => $calc['tipoOpts'], 'tipo' => $calc['tipo'],
        ]);
    }

    /** Casillas editables que el usuario ha cambiado respecto a lo calculado */
    private function casillasForm(Request $r): array
    {
        $in = (array) $r->input('cas', []);
        $orig = (array) $r->input('orig', []);
        $out = [];
        foreach ($in as $id => $val) {
            $val = trim((string) $val);
            if (mb_strlen($val) > 120) {
                $val = mb_substr($val, 0, 120);
            }
            if (! array_key_exists($id, $orig) || $val !== trim((string) $orig[$id])) {
                $out[(string) $id] = $val;
            }
        }

        return $out;
    }

    private function registro(Cliente $c, string $code, int $anio, string $p): FiscalModelo
    {
        return FiscalModelo::firstOrNew(['cliente_id' => $c->id, 'modelo' => $code, 'anio' => $anio, 'periodo' => $p]);
    }

    public function borrador(Request $request, string $codigo): RedirectResponse
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        $m = $this->registro($c, $codigo, $anio, $p);
        $m->fill(['casillas' => $this->casillasForm($request) ?: null, 'tipo_decl' => substr((string) $request->input('tipo_decl', ''), 0, 1) ?: null,
            'borrador_at' => now(), 'updated_by' => $request->user()->id])->save();
        $lbl = Fiscal::etiquetaPeriodo($codigo, $anio, $p);
        Auditoria::registrar('fiscal', 'Borrador del modelo '.$codigo.' · '.$lbl.' guardado', $m, $c->id);

        return redirect()->route('fiscal.modelo', ['codigo' => $codigo, 'anio' => $anio, 'per' => $p])->with('ok', 'Borrador del modelo '.$codigo.' · '.$lbl.' guardado.');
    }

    public function recalcular(Request $request, string $codigo): RedirectResponse
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        FiscalModelo::where('cliente_id', $c->id)->where('modelo', $codigo)->where('anio', $anio)->where('periodo', $p)->update(['casillas' => null, 'updated_by' => $request->user()->id]);

        return redirect()->route('fiscal.modelo', ['codigo' => $codigo, 'anio' => $anio, 'per' => $p])->with('ok', 'Casillas recalculadas desde la contabilidad.');
    }

    public function presentado(Request $request, string $codigo): RedirectResponse
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        $m = $this->registro($c, $codigo, $anio, $p);
        $lbl = Fiscal::etiquetaPeriodo($codigo, $anio, $p);
        if ($m->presentado_at) {
            $m->fill(['presentado_at' => null, 'presentado_by' => null, 'updated_by' => $request->user()->id])->save();
            $msg = 'Marca de presentado retirada.';
        } else {
            $fecha = (string) $request->input('fecha', '');
            $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : Plazos::hoy()->toDateString();
            if ($f > Plazos::hoy()->toDateString()) {
                return back()->withErrors(['fecha' => 'La fecha de presentación no puede ser futura.']);
            }
            $m->fill(['presentado_at' => $f, 'presentado_by' => $request->user()->id, 'updated_by' => $request->user()->id])->save();
            $msg = 'Modelo '.$codigo.' · '.$lbl.' marcado como presentado.';
        }
        Auditoria::registrar('fiscal', $msg.' ('.$codigo.' · '.$lbl.')', $m, $c->id);

        return redirect()->route('fiscal.modelo', ['codigo' => $codigo, 'anio' => $anio, 'per' => $p])->with('ok', $msg);
    }

    /** Exportación de las casillas: CSV (Excel) o vista para imprimir / guardar como PDF */
    public function exportar(Request $request, string $codigo)
    {
        abort_unless(isset(Fiscal::MODELOS[$codigo]), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $p] = $this->periodo($request, $c, $codigo);
        $calc = $this->fiscal->calcular($c, $codigo, $anio, $p);
        $lbl = Fiscal::etiquetaPeriodo($codigo, $anio, $p);
        $filas = collect($calc['filas'])->filter(fn ($f) => isset($f['id']))->map(fn ($f) => [$f['n'], $f['l'], $f['txt']])->values()->all();
        foreach ($calc['terc'] as $t) {
            $filas[] = ['', $t[1].' · '.$t[0].' · '.$t[2], Formato::eur($t[3])];
        }
        if ($request->query('formato') === 'pdf') {
            return view('fiscal.imprimir', ['c' => $c, 'code' => $codigo, 'nombre' => Fiscal::nombre($codigo), 'perLbl' => $lbl, 'calc' => $calc, 'plazo' => Fiscal::plazo($codigo, $anio, $p)]);
        }
        $nombre = 'modelo'.$codigo.'-'.preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $c->nif)).'-'.str_replace(' ', '', $lbl).'.csv';

        return response()->streamDownload(function () use ($filas, $codigo, $lbl) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Modelo '.$codigo.' · '.$lbl, '', ''], ';');
            fputcsv($o, ['Casilla', 'Concepto', 'Importe'], ';');
            foreach ($filas as $f) {
                fputcsv($o, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $f), ';');
            }
            fclose($o);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
