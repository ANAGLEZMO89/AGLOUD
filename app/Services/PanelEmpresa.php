<?php

namespace App\Services;

use App\Models\BandejaDocumento;
use App\Models\Cliente;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\FiscalModelo;
use App\Models\NominaMes;
use App\Models\Notificacion;
use App\Models\Oportunidad;
use App\Models\Presupuesto;
use App\Models\Tarea;
use App\Models\Tercero;
use App\Models\Trabajador;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Throwable;

/**
 * Panel del escritorio de la empresa elegida: cuadros con lo que ya hay metido
 * (modelos del trimestre, facturas, compras, nóminas, CRM, clientes y proveedores, documentos y avisos).
 * Con el despacho elegido, notificaciones, expedientes y tareas suman las de todos los clientes.
 */
class PanelEmpresa
{
    public function __construct(private Fiscal $fiscal, private Nomina $nomina) {}

    public function datos(Cliente $c): array
    {
        $hoy = Plazos::hoy();
        $anio = $hoy->year;
        $tri = (int) ceil($hoy->month / 3);
        [$ti, $tf] = [$hoy->copy()->month(($tri - 1) * 3 + 1)->startOfMonth()->toDateString(), $hoy->copy()->month($tri * 3)->endOfMonth()->toDateString()];
        $E = fn ($v) => Formato::eur((float) $v);
        $cards = [];

        // ---------------------------------------------------------------- ventas
        $fv = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida');
        $vAnio = (clone $fv)->whereYear('fecha', $anio);
        $nV = (clone $vAnio)->count();
        $pend = (clone $fv)->where('cobrada', false)->get(['fecha', 'pago_dias', 'total']);
        $venc = $pend->filter(fn ($f) => $f->fecha->addDays((int) $f->pago_dias)->lt($hoy));
        $cards['ventas'] = ['t' => 'Facturas emitidas', 'v' => $E((clone $vAnio)->sum('total')), 'vs' => $nV ? $nV.' '.($nV === 1 ? 'factura' : 'facturas').' en '.$anio : 'Sin facturas en '.$anio,
            'filas' => [['Base del trimestre '.$tri.'T', $E((clone $fv)->whereBetween('fecha', [$ti, $tf])->sum('base'))], ['Pendiente de cobro', $E($pend->sum('total')).' · '.$pend->count()],
                ['Vencidas sin cobrar', $venc->count() ? $E($venc->sum('total')).' · '.$venc->count() : 'Ninguna', $venc->count() ? 'mal' : 'bien']],
            'url' => route('facturacion.index'), 'cta' => $nV ? 'Ver facturas' : 'Emitir la primera factura', 'ctaUrl' => $nV ? null : route('facturacion.create')];

        // ---------------------------------------------------------------- compras y gastos
        $fr = FacturaRecibida::where('cliente_id', $c->id);
        $rAnio = (clone $fr)->whereYear('fecha', $anio);
        $nR = (clone $rAnio)->count();
        $sinPagar = (clone $fr)->whereNull('pagada_at');
        $vencR = (clone $sinPagar)->whereNotNull('vencimiento')->whereDate('vencimiento', '<', $hoy->toDateString());
        $cards['compras'] = ['t' => 'Compras y gastos', 'v' => $E((clone $rAnio)->sum('total')), 'vs' => $nR ? $nR.' '.($nR === 1 ? 'factura recibida' : 'facturas recibidas').' en '.$anio : 'Sin facturas recibidas en '.$anio,
            'filas' => [['Base del trimestre '.$tri.'T', $E((clone $fr)->whereBetween('fecha', [$ti, $tf])->sum('base'))], ['Pendiente de pago', $E((clone $sinPagar)->sum('total')).' · '.(clone $sinPagar)->count()],
                ['Vencidas sin pagar', ($n = (clone $vencR)->count()) ? $E((clone $vencR)->sum('total')).' · '.$n : 'Ninguna', $n ? 'mal' : 'bien']],
            'url' => route('proveedores.facturas')];

        // ---------------------------------------------------------------- resultado e IVA estimados
        $ingresos = (float) (clone $vAnio)->sum('base');
        $gastos = (float) (clone $rAnio)->sum('base');
        $ivaRep = (float) (clone $fv)->whereBetween('fecha', [$ti, $tf])->sum('cuota');
        $ivaSop = (float) (clone $fr)->whereBetween('fecha', [$ti, $tf])->where('deducible', true)->sum('cuota');
        $cards['resultado'] = ['t' => 'Resultado estimado '.$anio, 'v' => $E($ingresos - $gastos), 'vs' => 'Ingresos menos compras y gastos (bases)', 'tono' => $ingresos - $gastos < 0 ? 'mal' : null,
            'filas' => [['Ingresos', $E($ingresos)], ['Compras y gastos', $E($gastos)], ['IVA del '.$tri.'T (repercutido − soportado)', $E($ivaRep - $ivaSop), $ivaRep - $ivaSop > 0 ? 'aviso' : null]],
            'url' => route('contabilidad.pyg'), 'nota' => 'Estimación con las facturas registradas; el resultado oficial sale de la contabilidad.'];

        // ---------------------------------------------------------------- modelos fiscales
        $mods = [];
        try {
            $P = $this->fiscal->parametros($c);
            $R = Fiscal::detectar($c, $P);
            $pres = FiscalModelo::where('cliente_id', $c->id)->whereNotNull('presentado_at')->get()->mapWithKeys(fn ($m) => [$m->modelo.'|'.$m->anio.'|'.$m->periodo => true])->all();
            $aviso = (int) Parametros::get('fiscal')['aviso_dias'];
            foreach (array_keys($R) as $code) {
                $code = (string) $code;
                if (Fiscal::per($code) === 'E') {
                    continue;
                }
                foreach (Fiscal::candidatos($code, $anio) as [$y, $p, $plazo]) {
                    if (isset($pres[$code.'|'.$y.'|'.$p]) || ! $plazo || $plazo->lt($hoy)) {
                        continue;
                    }
                    $dias = (int) $hoy->diffInDays($plazo);
                    $mods[] = ['code' => $code, 'per' => Fiscal::etiquetaPeriodo($code, $y, $p), 'plazo' => $plazo, 'dias' => $dias, 'cerca' => $dias <= $aviso,
                        'url' => route('fiscal.modelo', ['codigo' => $code, 'anio' => $y, 'per' => $p])];
                    break;
                }
            }
        } catch (Throwable) {
            $mods = [];
        }
        usort($mods, fn ($a, $b) => $a['plazo'] <=> $b['plazo']);
        $cards['fiscal'] = ['t' => 'Modelos fiscales', 'v' => count($mods) ? count($mods).' '.(count($mods) === 1 ? 'pendiente' : 'pendientes') : 'Al día', 'vs' => count($mods) ? 'Próximo plazo: '.Formato::fecha($mods[0]['plazo']) : 'Sin modelos pendientes de presentar',
            'tono' => collect($mods)->contains('cerca', true) ? 'mal' : null,
            'mods' => array_slice($mods, 0, 5), 'url' => route('fiscal.index')];

        // ---------------------------------------------------------------- nóminas
        $plant = Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->count();
        $lab = ['t' => 'Nóminas · '.mb_strtolower(Nomina::MESES[$hoy->month - 1]), 'url' => route('laboral.integral')];
        if ($plant) {
            try {
                $noms = $this->nomina->mes($c, $anio, $hoy->month);
                $sum = fn ($k) => array_sum(array_map(fn ($x) => (float) ($x['t'][$k] ?? 0), $noms));
                $emit = NominaMes::where('cliente_id', $c->id)->where('anio', $anio)->where('mes', $hoy->month)->where('estado', 'Emitida')->count();
                $lab += ['v' => $E($sum('dev') + $sum('ssE')), 'vs' => 'Coste empresa del mes · '.$plant.' '.($plant === 1 ? 'trabajador' : 'trabajadores'),
                    'filas' => [['Líquido a pagar', $E($sum('liq'))], ['Seguridad Social total', $E($sum('ssT') + $sum('ssE'))], ['Retenciones IRPF', $E($sum('irpf'))], ['Nóminas emitidas', $emit.' de '.count($noms), $emit < count($noms) ? 'aviso' : 'bien']]];
            } catch (Throwable) {
                $lab += ['v' => $plant.' en alta', 'vs' => 'Trabajadores en plantilla'];
            }
        } else {
            $lab += ['v' => 'Sin plantilla', 'vs' => 'No hay trabajadores dados de alta', 'cta' => 'Dar de alta un trabajador', 'ctaUrl' => route('laboral.ficha', 'nuevo')];
        }
        $cards['laboral'] = $lab;

        // ---------------------------------------------------------------- CRM
        $ops = Oportunidad::where('cliente_id', $c->id)->whereNotIn('etapa', ['Ganado', 'Perdido'])->get(['etapa', 'importe']);
        $pres = Presupuesto::where('cliente_id', $c->id)->where('estado', 'Enviado');
        $ganadas = Oportunidad::where('cliente_id', $c->id)->where('etapa', 'Ganado')->whereYear('updated_at', $anio)->sum('importe');
        $cards['crm'] = ['t' => 'CRM', 'v' => $ops->count().' '.($ops->count() === 1 ? 'oportunidad abierta' : 'oportunidades abiertas'), 'vs' => 'Previsión ponderada '.$E($ops->sum(fn ($o) => (float) $o->importe * Oportunidad::probabilidad($o->etapa) / 100)),
            'filas' => [['En juego', $E($ops->sum('importe'))], ['Presupuestos enviados sin respuesta', $E((clone $pres)->sum('total')).' · '.(clone $pres)->count()], ['Ganado en '.$anio, $E($ganadas), 'bien']],
            'url' => route('crm.index')];

        // ---------------------------------------------------------------- clientes y proveedores
        $ter = Tercero::where('cliente_id', $c->id)->selectRaw('clase, count(*) n')->groupBy('clase')->pluck('n', 'clase');
        $filasT = [];
        if ($c->es_despacho) {
            $filasT[] = ['Clientes del despacho', Formato::entero(Cliente::clientes()->where('estado', 'Activo')->count())];
        }
        $filasT[] = ['Clientes', Formato::entero((int) ($ter['cliente'] ?? 0))];
        $filasT[] = ['Proveedores', Formato::entero((int) ($ter['proveedor'] ?? 0))];
        $filasT[] = ['Acreedores', Formato::entero((int) ($ter['acreedor'] ?? 0))];
        $cards['terceros'] = ['t' => 'Clientes y proveedores', 'v' => Formato::entero(array_sum($ter->all()) + ($c->es_despacho ? Cliente::clientes()->where('estado', 'Activo')->count() : 0)), 'vs' => 'Fichas dadas de alta',
            'filas' => $filasT, 'url' => $c->es_despacho ? route('clientes.index') : route('ventas.index')];

        // ---------------------------------------------------------------- documentos
        $docs = $c->documentos()->gestor()->vivos();
        $rev = (clone $docs)->where('por_revisar', true)->count();
        $band = BandejaDocumento::where('cliente_id', $c->id)->count();
        $cards['docs'] = ['t' => 'Documentos', 'v' => Formato::entero((clone $docs)->count()), 'vs' => 'En el gestor documental',
            'filas' => [['Por revisar', Formato::entero($rev), $rev ? 'aviso' : 'bien'], ['Facturas en la bandeja de contabilidad', Formato::entero($band), $band ? 'aviso' : 'bien'], ['Subidos este mes', Formato::entero((clone $docs)->whereYear('created_at', $anio)->whereMonth('created_at', $hoy->month)->count())]],
            'url' => route('documentos.index')];

        // ---------------------------------------------------------------- avisos: notificaciones, expedientes y tareas
        $scope = fn ($q) => $c->es_despacho ? $q : $q->where('cliente_id', $c->id);
        $notif = $scope(Notificacion::query())->where('estado', 'Pendiente')->where('archivada', false);
        $nVence = (clone $notif)->whereNotNull('vence')->whereDate('vence', '<=', $hoy->copy()->addDays(7)->toDateString())->count();
        $exp = $scope(Expediente::query())->whereNotIn('estado', ['Presentado', 'Cerrado']);
        $tar = $scope(Tarea::query())->where('hecha', false);
        $tAtras = (clone $tar)->whereDate('fecha', '<', $hoy->toDateString())->count();
        $cards['avisos'] = ['t' => 'Avisos y trámites', 'v' => Formato::entero((clone $notif)->count()), 'vs' => 'Notificaciones pendientes'.($c->es_despacho ? ' de todos los clientes' : ''), 'tono' => $nVence ? 'mal' : null,
            'filas' => [['Notificaciones que vencen en 7 días', Formato::entero($nVence), $nVence ? 'mal' : 'bien'], ['Expedientes abiertos', Formato::entero((clone $exp)->count())], ['Tareas pendientes', Formato::entero((clone $tar)->count()).($tAtras ? ' · '.$tAtras.' atrasadas' : ''), $tAtras ? 'mal' : null]],
            'url' => route('notificaciones.index')];

        // ---------------------------------------------------------------- evolución mensual del año
        $meses = [];
        foreach (range(1, 12) as $m) {
            $meses[$m] = ['v' => 0.0, 'g' => 0.0];
        }
        foreach ((clone $vAnio)->get(['fecha', 'base']) as $f) {
            $meses[$f->fecha->month]['v'] += (float) $f->base;
        }
        foreach ((clone $rAnio)->get(['fecha', 'base']) as $f) {
            $meses[$f->fecha->month]['g'] += (float) $f->base;
        }

        return ['cards' => $cards, 'meses' => $meses, 'anio' => $anio, 'max' => max(1, ...array_map(fn ($x) => max($x['v'], $x['g']), $meses)), 'mesActual' => $hoy->month];
    }
}
