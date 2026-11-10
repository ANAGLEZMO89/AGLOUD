<?php

use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CopiasController;
use App\Http\Controllers\Admin\EquipoController;
use App\Http\Controllers\ClienteAccesoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ClienteSeccionController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\EscritorioController;
use App\Http\Controllers\ExpedienteController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\Portal\PortalController;
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

        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('/clientes/exportar', [ClienteController::class, 'exportar'])->name('clientes.exportar');
        Route::get('/clientes/nuevo', [ClienteController::class, 'create'])->name('clientes.create');
        Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
        Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
        Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit'])->name('clientes.edit');
        Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
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
