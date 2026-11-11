<?php

use App\Http\Controllers\AcreedorController;
use App\Http\Controllers\AeatController;
use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CopiasController;
use App\Http\Controllers\Admin\EquipoController;
use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\ClienteAccesoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteSeccionController;
use App\Http\Controllers\ContabilidadController;
use App\Http\Controllers\ContabilidadListadosController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\EscritorioController;
use App\Http\Controllers\ExpedienteController;
use App\Http\Controllers\BandejaController;
use App\Http\Controllers\CobrosPagosController;
use App\Http\Controllers\ConciliadorController;
use App\Http\Controllers\RegistroMercantilController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\FiscalController;
use App\Http\Controllers\LaboralController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\ParametrosController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\TareaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $u = auth()->user();
    if (! $u) {
        return redirect()->route('login');
    }

    return redirect()->route($u->esPortal() ? 'portal.inicio' : 'escritorio');
});

Route::middleware('auth')->group(function () {

    // Mi cuenta (todos los roles)
    // La página de seguridad pide la contraseña (como mucho cada 3 h) antes de tocar el 2FA
    Route::get('/cuenta', [CuentaController::class, 'show'])->middleware('password.confirm')->name('cuenta');
    Route::get('/cuenta/nueva-contrasena', [CuentaController::class, 'nueva'])->name('cuenta.nueva-contrasena');
    Route::post('/cuenta/nueva-contrasena', [CuentaController::class, 'guardarNueva'])->name('cuenta.nueva-contrasena.guardar');

    // Acciones sobre un documento: despacho y portal (la política decide)
    Route::prefix('documentos/{documento}')->name('documentos.')->whereNumber('documento')->group(function () {
        Route::get('/ver', [DocumentoController::class, 'ver'])->name('ver');
        Route::get('/descargar', [DocumentoController::class, 'descargar'])->name('descargar');
        Route::post('/renombrar', [DocumentoController::class, 'renombrar'])->name('renombrar');
        Route::post('/mover', [DocumentoController::class, 'mover'])->name('mover');
        Route::post('/papelera', [DocumentoController::class, 'papelera'])->name('papelera');
        Route::post('/restaurar', [DocumentoController::class, 'restaurar'])->name('restaurar');
        Route::post('/compartir', [DocumentoController::class, 'compartir'])->name('compartir');
        Route::post('/revisado', [DocumentoController::class, 'revisado'])->name('revisado');
    });

    // ---------------- AG Cloud (despacho) ----------------
    Route::middleware('rol:despacho')->group(function () {
        Route::get('/escritorio', [EscritorioController::class, 'index'])->name('escritorio');
        Route::post('/escritorio/entorno/{cliente}', [EscritorioController::class, 'seleccionar'])->name('escritorio.seleccionar');
        Route::post('/escritorio/cambiar', [EscritorioController::class, 'cambiar'])->name('escritorio.cambiar');
        Route::get('/modulo/{modulo}', [EscritorioController::class, 'preparacion'])->name('preparacion');

        // Gestión de la empresa elegida: facturas de clientes, proveedores y acreedores
        Route::get('/clientes/facturas', [ProveedorController::class, 'facturasClientes'])->name('clientes.facturas');
        // Cobros y pagos: marcar con un clic y enviar a contabilidad (banco o caja)
        Route::get('/clientes/cobros', [CobrosPagosController::class, 'cobros'])->name('clientes.cobros');
        Route::post('/cobros/{factura}/marcar', [CobrosPagosController::class, 'marcarCobro'])->whereNumber('factura')->name('cobros.marcar');
        Route::post('/cobros/{factura}/contabilizar', [CobrosPagosController::class, 'contabilizarCobro'])->whereNumber('factura')->name('cobros.contabilizar');
        Route::post('/cobros/contabilizar', [CobrosPagosController::class, 'contabilizarCobros'])->name('cobros.contabilizarTodos');
        foreach (['proveedores' => 'Proveedor', 'acreedores' => 'Acreedor'] as $seg => $suf) {
            Route::get('/'.$seg.'/pagos', [CobrosPagosController::class, 'pagos'.($suf === 'Proveedor' ? 'Proveedores' : 'Acreedores')])->name($seg.'.pagos');
            Route::post('/'.$seg.'/pagos/contabilizar', [CobrosPagosController::class, 'contabilizarPagos'.($suf === 'Proveedor' ? 'Proveedores' : 'Acreedores')])->name($seg.'.pagos.contabilizarTodos');
            Route::post('/'.$seg.'/pagos/{factura}/marcar', [CobrosPagosController::class, 'marcarPago'.$suf])->whereNumber('factura')->name($seg.'.pagos.marcar');
            Route::post('/'.$seg.'/pagos/{factura}/contabilizar', [CobrosPagosController::class, 'contabilizarPago'.$suf])->whereNumber('factura')->name($seg.'.pagos.contabilizar');
        }
        Route::get('/acreedores/no-deducibles', [AcreedorController::class, 'noDeducibles'])->name('acreedores.nodeducibles');
        // Productos y servicios de la empresa elegida (se usan al facturar)
        Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
        Route::post('/productos', [ProductoController::class, 'guardar'])->name('productos.guardar');
        Route::delete('/productos/{indice}', [ProductoController::class, 'borrar'])->whereNumber('indice')->name('productos.borrar');
        foreach (['proveedores' => ProveedorController::class, 'acreedores' => AcreedorController::class] as $seg => $ctl) {
            Route::prefix($seg)->name($seg.'.')->group(function () use ($ctl) {
                Route::get('/', [$ctl, 'index'])->name('index');
                Route::post('/', [$ctl, 'store'])->name('store');
                Route::get('/facturas', [$ctl, 'facturas'])->name('facturas');
                Route::post('/facturas', [$ctl, 'storeFactura'])->name('facturas.store');
                Route::post('/facturas/{factura}', [$ctl, 'updateFactura'])->whereNumber('factura')->name('facturas.update');
                Route::post('/facturas/{factura}/pagar', [$ctl, 'pagar'])->whereNumber('factura')->name('facturas.pagar');
                Route::delete('/facturas/{factura}', [$ctl, 'borrarFactura'])->whereNumber('factura')->name('facturas.destroy');
                Route::post('/{tercero}', [$ctl, 'update'])->whereNumber('tercero')->name('update');
                Route::delete('/{tercero}', [$ctl, 'borrar'])->whereNumber('tercero')->name('destroy');
            });
        }

        // Contabilidad de la empresa elegida
        Route::prefix('contabilidad')->name('contabilidad.')->group(function () {
            Route::get('/', [ContabilidadController::class, 'index'])->name('index');
            Route::get('/bandeja', [BandejaController::class, 'bandeja'])->name('bandeja');
            Route::post('/bandeja/subir', [BandejaController::class, 'subir'])->name('bandeja.subir');
            Route::delete('/bandeja/documentos/{documento}', [BandejaController::class, 'descartar'])->whereNumber('documento')->name('bandeja.descartar');
            Route::post('/bandeja/{k}/contabilizar', [BandejaController::class, 'contabilizarRevision'])->where('k', '[erd]\\d+')->name('bandeja.revisar');
            Route::post('/bandeja', [ContabilidadController::class, 'contabilizar'])->name('contabilizar');
            Route::get('/diario', [ContabilidadController::class, 'diario'])->name('diario');
            Route::delete('/asientos/{asiento}', [ContabilidadController::class, 'borrar'])->whereNumber('asiento')->name('borrar');
            Route::get('/apuntes', [ContabilidadController::class, 'apuntes'])->name('apuntes');
            Route::post('/apuntes', [ContabilidadController::class, 'guardarAsiento'])->name('apuntes.guardar');
            // Listados contables
            Route::get('/listados/extracto', [ContabilidadController::class, 'mayor'])->name('mayor');
            Route::get('/listados/sumas-y-saldos', [ContabilidadController::class, 'sumas'])->name('sumas');
            Route::get('/listados/balances-oficiales', [ContabilidadController::class, 'cuentasAnuales'])->name('anuales');
            Route::get('/listados/cuenta-de-explotacion', [ContabilidadListadosController::class, 'pyg'])->name('pyg');
            Route::get('/listados/diario', [ContabilidadListadosController::class, 'ldiario'])->name('ldiario');
            Route::get('/listados/mayor', [ContabilidadListadosController::class, 'lmayor'])->name('lmayor');
            Route::redirect('/mayor', '/contabilidad/listados/extracto');
            Route::redirect('/sumas-y-saldos', '/contabilidad/listados/sumas-y-saldos');
            Route::redirect('/cuentas-anuales', '/contabilidad/listados/balances-oficiales');
            // Conciliador bancario
            Route::get('/conciliador', [ConciliadorController::class, 'index'])->name('conciliador');
            Route::post('/conciliador/importar', [ConciliadorController::class, 'importar'])->name('conciliador.importar');
            Route::post('/conciliador/automatico', [ConciliadorController::class, 'automatico'])->name('conciliador.auto');
            Route::post('/conciliador/{mov}/conciliar', [ConciliadorController::class, 'conciliar'])->whereNumber('mov')->name('conciliador.conciliar');
            Route::post('/conciliador/{mov}/asiento', [ConciliadorController::class, 'crearAsiento'])->whereNumber('mov')->name('conciliador.asiento');
            Route::post('/conciliador/{mov}/deshacer', [ConciliadorController::class, 'deshacer'])->whereNumber('mov')->name('conciliador.deshacer');
            Route::delete('/conciliador/{mov}', [ConciliadorController::class, 'borrarMovimiento'])->whereNumber('mov')->name('conciliador.borrar');
            // Registro Mercantil
            Route::get('/registro-mercantil/{sec}', [RegistroMercantilController::class, 'seccion'])->whereIn('sec', ['plantillas', 'legalia', 'd2'])->name('rm');
            Route::post('/registro-mercantil/{sec}', [RegistroMercantilController::class, 'guardar'])->whereIn('sec', ['plantillas', 'legalia', 'd2'])->name('rm.guardar');
            Route::get('/registro-mercantil/documento/{doc}', [RegistroMercantilController::class, 'documento'])->whereIn('doc', ['cert', 'acta', 'aplic', 'titular', 'instancia', 'gestion'])->name('rm.doc');
            // Plan General Contable de la empresa
            Route::get('/pgc', [ContabilidadListadosController::class, 'pgc'])->name('pgc');
            Route::post('/pgc', [ContabilidadListadosController::class, 'guardarPgc'])->name('pgc.guardar');
            Route::delete('/pgc/{codigo}', [ContabilidadListadosController::class, 'borrarPgc'])->where('codigo', '\\d{9}')->name('pgc.borrar');
            Route::post('/pgc/{codigo}/reactivar', [ContabilidadListadosController::class, 'reactivarPgc'])->where('codigo', '\\d{9}')->name('pgc.reactivar');
            Route::get('/ejercicios', [ContabilidadController::class, 'ejercicios'])->name('ejercicios');
            Route::post('/ejercicios', [ContabilidadController::class, 'abrirEjercicio'])->name('ejercicios.abrir');
            Route::post('/ejercicios/{ejercicio}/cerrar', [ContabilidadController::class, 'cerrarEjercicio'])->whereNumber('ejercicio')->name('ejercicios.cerrar');
            Route::post('/ejercicios/{ejercicio}/reabrir', [ContabilidadController::class, 'reabrirEjercicio'])->whereNumber('ejercicio')->name('ejercicios.reabrir');
            Route::post('/ejercicios/{ejercicio}/bloqueo', [ContabilidadController::class, 'bloqueo'])->whereNumber('ejercicio')->name('ejercicios.bloqueo');
            Route::post('/subcuentas', [ContabilidadController::class, 'guardarCuenta'])->name('subcuentas');
        });

        // Fiscal: modelos de la empresa elegida (borradores; la presentación se hace en la Sede de la AEAT)
        Route::prefix('fiscal')->name('fiscal.')->group(function () {
            Route::get('/', [FiscalController::class, 'index'])->name('index');
            Route::post('/parametros', [FiscalController::class, 'guardarParametros'])->name('parametros');
            Route::get('/modelo/{codigo}', [FiscalController::class, 'modelo'])->where('codigo', '\\d{3}')->name('modelo');
            Route::post('/modelo/{codigo}/calcular', [FiscalController::class, 'calcular'])->where('codigo', '\\d{3}')->name('calcular');
            Route::post('/modelo/{codigo}/borrador', [FiscalController::class, 'borrador'])->where('codigo', '\\d{3}')->name('borrador');
            Route::post('/modelo/{codigo}/recalcular', [FiscalController::class, 'recalcular'])->where('codigo', '\\d{3}')->name('recalcular');
            Route::post('/modelo/{codigo}/presentado', [FiscalController::class, 'presentado'])->where('codigo', '\\d{3}')->name('presentado');
            Route::get('/modelo/{codigo}/exportar', [FiscalController::class, 'exportar'])->where('codigo', '\\d{3}')->name('exportar');
        });

        // Laboral y SS de la empresa elegida (no envía ficheros a la TGSS ni al SEPE: abre la sede y registra lo hecho)
        Route::prefix('laboral')->name('laboral.')->group(function () {
            Route::get('/', [LaboralController::class, 'index'])->name('index');
            Route::get('/trabajador/{id}', [LaboralController::class, 'ficha'])->where('id', 'nuevo|\\d+')->name('ficha');
            Route::post('/trabajador/{id}', [LaboralController::class, 'guardar'])->where('id', 'nuevo|\\d+')->name('guardar');
            Route::delete('/trabajador/{trabajador}/eliminar', [LaboralController::class, 'eliminar'])->whereNumber('trabajador')->name('eliminar');
            Route::post('/trabajador/{trabajador}/accion/{k}', [LaboralController::class, 'accion'])->whereNumber('trabajador')->name('accion');
            Route::get('/trabajador/{trabajador}/documento/{tipo}', [LaboralController::class, 'documento'])->whereNumber('trabajador')->name('documento');
            Route::get('/nominas', [LaboralController::class, 'integral'])->name('integral');
            Route::post('/nominas/contabilizar', [LaboralController::class, 'contabilizar'])->name('contabilizar');
            Route::post('/nominas/{trabajador}/variables', [LaboralController::class, 'incidencias'])->whereNumber('trabajador')->name('incidencias');
            Route::get('/nominas/{trabajador}/recibo', [LaboralController::class, 'recibo'])->whereNumber('trabajador')->name('recibo');
            Route::get('/seguros-sociales', [LaboralController::class, 'seguros'])->name('seguros');
            Route::post('/seguros-sociales/at', [LaboralController::class, 'at'])->name('at');
            Route::post('/seguros-sociales/confirmar', [LaboralController::class, 'confirmar'])->name('confirmar');
            Route::get('/altas-y-bajas', [LaboralController::class, 'movimientos'])->name('movimientos');
            Route::post('/altas-y-bajas/baja', [LaboralController::class, 'baja'])->name('baja');
            Route::post('/altas-y-bajas/comunicar', [LaboralController::class, 'comunicar'])->name('comunicar');
            Route::get('/parametros', [LaboralController::class, 'parametros'])->name('parametros');
            Route::post('/parametros', [LaboralController::class, 'guardarParametros'])->name('parametros.guardar');
        });

        // Calendario y tareas del despacho
        Route::get('/calendario', [CalendarioController::class, 'index'])->name('calendario');
        Route::post('/calendario/eventos', [CalendarioController::class, 'guardar'])->name('calendario.store');
        Route::delete('/calendario/eventos/{evento}', [CalendarioController::class, 'borrar'])->whereNumber('evento')->name('calendario.destroy');
        Route::get('/tareas', [TareaController::class, 'index'])->name('tareas.index');
        Route::post('/tareas', [TareaController::class, 'guardar'])->name('tareas.store');
        Route::post('/tareas/{tarea}/hecha', [TareaController::class, 'hecha'])->whereNumber('tarea')->name('tareas.hecha');
        Route::delete('/tareas/{tarea}', [TareaController::class, 'borrar'])->whereNumber('tarea')->name('tareas.destroy');
        Route::get('/tareas/horario', [TareaController::class, 'horario'])->name('tareas.horario');
        Route::post('/tareas/horario/fichar', [TareaController::class, 'fichar'])->name('tareas.fichar');
        Route::get('/tareas/horario/exportar', [TareaController::class, 'exportar'])->name('tareas.exportar');
        Route::post('/tareas/solicitudes', [TareaController::class, 'solicitar'])->name('tareas.solicitar');
        Route::post('/tareas/solicitudes/{solicitud}', [TareaController::class, 'resolver'])->whereNumber('solicitud')->name('tareas.resolver');

        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/exportar', [ClienteController::class, 'exportar'])->name('clientes.exportar');
        Route::get('/clientes/nuevo', [ClienteController::class, 'create'])->name('clientes.create');
        Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
        Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
        Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');
        Route::post('/clientes/{id}/recuperar', [ClienteController::class, 'recuperar'])->whereNumber('id')->name('clientes.recuperar');
        Route::post('/clientes/{cliente}/plataforma', [ClienteController::class, 'plataforma'])->name('clientes.plataforma');
        Route::post('/clientes/{cliente}/seccion/{seccion}', [ClienteSeccionController::class, 'guardar'])->name('clientes.seccion.guardar');
        Route::delete('/clientes/{cliente}/seccion/{seccion}/{idx}', [ClienteSeccionController::class, 'borrar'])->whereNumber('idx')->name('clientes.seccion.borrar');
        Route::post('/clientes/{cliente}/imagen/{slot}', [ClienteSeccionController::class, 'imagen'])->whereIn('slot', ['logo', 'firma'])->name('clientes.imagen');
        Route::delete('/clientes/{cliente}/imagen/{slot}', [ClienteSeccionController::class, 'quitarImagen'])->whereIn('slot', ['logo', 'firma'])->name('clientes.imagen.quitar');
        Route::get('/clientes/{cliente}/imagen/{slot}', [ClienteSeccionController::class, 'verImagen'])->whereIn('slot', ['logo', 'firma'])->name('clientes.imagen.ver');

        Route::post('/clientes/{cliente}/accesos', [ClienteAccesoController::class, 'store'])->name('clientes.accesos.store');
        Route::post('/clientes/{cliente}/accesos/{usuario}/reenviar', [ClienteAccesoController::class, 'reenviar'])->name('clientes.accesos.reenviar');
        Route::post('/clientes/{cliente}/accesos/{usuario}/activo', [ClienteAccesoController::class, 'activo'])->name('clientes.accesos.activo');

        Route::get('/documentos', [DocumentoController::class, 'index'])->name('documentos.index');
        Route::post('/documentos/subir', [DocumentoController::class, 'subir'])->name('documentos.subir');
        Route::post('/documentos/confirmar', [DocumentoController::class, 'confirmar'])->name('documentos.confirmar');
        Route::post('/documentos/cancelar', [DocumentoController::class, 'cancelar'])->name('documentos.cancelar');
        Route::post('/documentos/vaciar-papelera', [DocumentoController::class, 'vaciarPapelera'])->name('documentos.vaciar');
        Route::get('/documentos/indice', [DocumentoController::class, 'indice'])->name('documentos.indice');

        // Notificaciones (registro manual · sin conexión con DEHú/AEAT/TGSS)
        Route::get('/notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
        Route::post('/notificaciones', [NotificacionController::class, 'store'])->name('notificaciones.store');
        Route::post('/notificaciones/avisos', [NotificacionController::class, 'guardarAvisos'])->name('notificaciones.avisos');
        Route::prefix('notificaciones/{notificacion}')->name('notificaciones.')->whereNumber('notificacion')->group(function () {
            Route::post('/abrir', [NotificacionController::class, 'abrir'])->name('abrir');
            Route::post('/rechazar', [NotificacionController::class, 'rechazar'])->name('rechazar');
            Route::post('/archivar', [NotificacionController::class, 'archivar'])->name('archivar');
            Route::post('/actualizar', [NotificacionController::class, 'actualizar'])->name('actualizar');
            Route::post('/adjuntar', [NotificacionController::class, 'adjuntar'])->name('adjuntar');
            Route::get('/calendario.ics', [NotificacionController::class, 'ics'])->name('ics');
            Route::delete('/', [NotificacionController::class, 'destroy'])->name('destroy');
        });

        // Parámetros de cada módulo
        Route::get('/parametros/{modulo}', [ParametrosController::class, 'show'])->name('parametros');
        Route::post('/parametros/{modulo}', [ParametrosController::class, 'guardar'])->name('parametros.guardar');

        // Facturación (modo No VERI*FACTU: sin envío a la AEAT)
        Route::prefix('facturacion')->name('facturacion.')->group(function () {
            Route::get('/', [FacturaController::class, 'index'])->name('index');
            Route::get('/nueva', [FacturaController::class, 'create'])->name('create');
            Route::post('/', [FacturaController::class, 'store'])->name('store');
            Route::post('/borrador', [FacturaController::class, 'borrador'])->name('borrador');
            Route::get('/registro', [FacturaController::class, 'registro'])->name('registro');
            Route::post('/registro/verificar', [FacturaController::class, 'verificar'])->name('verificar');
            Route::get('/configuracion', [FacturaController::class, 'config'])->name('config');
            Route::post('/configuracion', [FacturaController::class, 'guardarConfig'])->name('config.guardar');
            Route::match(['get', 'post'], '/muestra', [FacturaController::class, 'muestra'])->name('muestra');
            Route::get('/diseno', [FacturaController::class, 'diseno'])->name('diseno');
            Route::post('/diseno', [FacturaController::class, 'guardarDiseno'])->name('diseno.guardar');
            Route::get('/{factura}', [FacturaController::class, 'show'])->whereNumber('factura')->name('show');
            Route::get('/{factura}/documento', [FacturaController::class, 'documento'])->whereNumber('factura')->name('documento');
            Route::get('/{factura}/facturae', [FacturaController::class, 'facturae'])->whereNumber('factura')->name('facturae');
            Route::post('/{factura}/cobro', [FacturaController::class, 'cobro'])->whereNumber('factura')->name('cobro');
            Route::post('/{factura}/anular', [FacturaController::class, 'anular'])->whereNumber('factura')->name('anular');
        });

        // CRM
        Route::prefix('crm')->name('crm.')->group(function () {
            Route::get('/', [CrmController::class, 'index'])->name('index');
            Route::post('/oportunidades', [CrmController::class, 'guardarOportunidad'])->name('op.store');
            Route::post('/oportunidades/{oportunidad}/etapa', [CrmController::class, 'etapa'])->whereNumber('oportunidad')->name('op.etapa');
            Route::delete('/oportunidades/{oportunidad}', [CrmController::class, 'borrarOportunidad'])->whereNumber('oportunidad')->name('op.destroy');
            Route::post('/presupuestos', [CrmController::class, 'guardarPresupuesto'])->name('pres.store');
            Route::post('/presupuestos/{presupuesto}/estado', [CrmController::class, 'estadoPresupuesto'])->whereNumber('presupuesto')->name('pres.estado');
            Route::get('/presupuestos/{presupuesto}/documento', [CrmController::class, 'documentoPresupuesto'])->whereNumber('presupuesto')->name('pres.documento');
            Route::post('/actividades', [CrmController::class, 'guardarActividad'])->name('act.store');
            Route::post('/actividades/{actividad}/hecha', [CrmController::class, 'hecha'])->whereNumber('actividad')->name('act.hecha');
        });

        // Expedientes
        Route::get('/expedientes', [ExpedienteController::class, 'index'])->name('expedientes.index');
        Route::post('/expedientes', [ExpedienteController::class, 'store'])->name('expedientes.store');
        Route::prefix('expedientes/{expediente}')->name('expedientes.')->whereNumber('expediente')->group(function () {
            Route::get('/', [ExpedienteController::class, 'show'])->name('show');
            Route::post('/estado', [ExpedienteController::class, 'estado'])->name('estado');
            Route::post('/paso/{i}', [ExpedienteController::class, 'paso'])->whereNumber('i')->name('paso');
            Route::post('/doc/{i}', [ExpedienteController::class, 'doc'])->whereNumber('i')->name('doc');
            Route::post('/nota', [ExpedienteController::class, 'nota'])->name('nota');
            Route::post('/portal', [ExpedienteController::class, 'portal'])->name('portal');
            Route::post('/actualizar', [ExpedienteController::class, 'actualizar'])->name('actualizar');
            Route::delete('/', [ExpedienteController::class, 'destroy'])->name('destroy');
        });
    });

    // ---------------- Administración del despacho ----------------
    Route::middleware('rol:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/equipo', [EquipoController::class, 'index'])->name('equipo');
        Route::post('/equipo', [EquipoController::class, 'store'])->name('equipo.store');
        Route::post('/equipo/{usuario}/activo', [EquipoController::class, 'activo'])->name('equipo.activo');
        Route::post('/equipo/{usuario}/rol', [EquipoController::class, 'rol'])->name('equipo.rol');
        Route::post('/equipo/{usuario}/reenviar', [EquipoController::class, 'reenviar'])->name('equipo.reenviar');
        Route::post('/aeat/certificado', [AeatController::class, 'guardar'])->name('aeat.guardar');
        Route::post('/aeat/activar', [AeatController::class, 'activar'])->name('aeat.activar');
        Route::delete('/aeat/certificado', [AeatController::class, 'quitar'])->name('aeat.quitar');
        Route::post('/aeat/probar', [AeatController::class, 'probar'])->name('aeat.probar');
        Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria');
        Route::get('/auditoria/exportar', [AuditoriaController::class, 'exportar'])->name('auditoria.exportar');
        Route::get('/copias', [CopiasController::class, 'index'])->middleware('password.confirm')->name('copias');
        Route::post('/copias/ahora', [CopiasController::class, 'ahora'])->name('copias.ahora');
        Route::get('/copias/descargar/{nombre}', [CopiasController::class, 'descargar'])->where('nombre', 'agcloud-\d{8}-\d{6}\.zip')->middleware('password.confirm')->name('copias.descargar');
        Route::post('/copias/drive/conectar', [CopiasController::class, 'conectar'])->name('copias.drive.conectar');
        Route::get('/copias/drive/callback', [CopiasController::class, 'callback'])->name('copias.drive.callback');
        Route::post('/copias/drive/desconectar', [CopiasController::class, 'desconectar'])->name('copias.drive.desconectar');
    });

    // ---------------- Portal del cliente ----------------
    Route::middleware('rol:portal')->prefix('portal')->name('portal.')->group(function () {
        Route::get('/', [PortalController::class, 'inicio'])->name('inicio');
        Route::get('/documentos', [DocumentoController::class, 'index'])->name('documentos');
        Route::post('/documentos/subir', [DocumentoController::class, 'subir'])->name('documentos.subir');
        Route::post('/documentos/confirmar', [DocumentoController::class, 'confirmar'])->name('documentos.confirmar');
        Route::post('/documentos/cancelar', [DocumentoController::class, 'cancelar'])->name('documentos.cancelar');
        Route::get('/modulo/{modulo}', [PortalController::class, 'preparacion'])->name('preparacion');
    });
});
