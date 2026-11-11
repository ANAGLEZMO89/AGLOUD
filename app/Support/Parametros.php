<?php

namespace App\Support;

use App\Models\Ajuste;
use Throwable;

/**
 * Parámetros de configuración de cada módulo (pestaña «Parámetros»).
 * Valores por defecto en config/agcloud.php; lo que guarda la administración
 * se conserva cifrado en la tabla ajustes («param.<módulo>»).
 */
class Parametros
{
    public const MODULOS = [
        'clientes' => ['Ventas', 'Gestión', 'clientes.index'],
        'expedientes' => ['Expedientes', 'Gestión', 'expedientes.index'],
        'notificaciones' => ['Notificaciones', 'Comunicación', 'notificaciones.index'],
        'documentos' => ['Documentos', 'Comunicación', 'documentos.index'],
        'crm' => ['CRM', 'Áreas', 'crm.index'],
        'proveedores' => ['Compras', 'Gestión', 'proveedores.index'],
        'acreedores' => ['Gastos', 'Gestión', 'acreedores.index'],
        'productos' => ['Productos', 'Gestión', 'productos.index'],
        'calendario' => ['Calendario', 'Gestión', 'calendario'],
        'tareas' => ['Tareas y horario', 'Gestión', 'tareas.index'],
        'contabilidad' => ['Contabilidad', 'Áreas', 'contabilidad.index'],
        'fiscal' => ['Fiscal', 'Áreas', 'fiscal.index'],
    ];

    /** Pestañas de cada módulo (además de «Parámetros») */
    public const PESTANAS = [
        'clientes' => [['Clientes', 'clientes.index'], ['Facturas de clientes', 'clientes.facturas'], ['Cobros', 'clientes.cobros']],
        'proveedores' => [['Proveedores', 'proveedores.index'], ['Facturas de proveedores', 'proveedores.facturas'], ['Pagos', 'proveedores.pagos']],
        'acreedores' => [['Acreedores', 'acreedores.index'], ['Facturas de acreedores', 'acreedores.facturas'], ['Pagos', 'acreedores.pagos'], ['Gastos no deducibles IVA', 'acreedores.nodeducibles']],
        'tareas' => [['Tareas', 'tareas.index'], ['Horario', 'tareas.horario']],
    ];

    private static array $cache = [];

    public static function defecto(string $m): array
    {
        $a = json_decode((string) json_encode(config('agcloud')), true);

        return match ($m) {
            'clientes' => ['periodicidad' => 'Mensual', 'iva' => 21, 'forma_cobro' => 'Domiciliación SEPA', 'concepto' => 'Honorarios de asesoría'],
            'documentos' => ['cupo_mb' => (int) ($a['cupo_mb'] ?? 2048), 'aviso_caducidad' => 60],
            'notificaciones' => ['email' => true, 'wa' => false, 'dias' => (int) ($a['avisar_dias'] ?? 3), 'comunidad' => (string) ($a['comunidad'] ?? 'AN'),
                'festivos' => [], 'tipos' => $a['tipos_notificacion'] ?? []],
            'expedientes' => ['portal_defecto' => true, 'plantillas' => $a['plantillas_expediente'] ?? []],
            'crm' => ['origenes' => ['Web', 'Recomendación', 'Llamada', 'Feria', 'Cliente actual', 'Redes sociales'],
                'probabilidades' => ['Nuevo' => 10, 'Contactado' => 25, 'Presupuesto enviado' => 50, 'Negociación' => 75, 'Ganado' => 100, 'Perdido' => 0],
                'validez' => 30, 'pie_presupuesto' => 'Para aceptar el presupuesto, devuélvalo firmado o confírmelo por escrito antes de la fecha de validez.'],
            'proveedores' => ['forma_pago' => 'Transferencia', 'pago_dias' => 30],
            'acreedores' => ['forma_pago' => 'Domiciliación SEPA', 'pago_dias' => 0, 'tipo' => 'Servicios', 'retencion' => 0],
            'calendario' => ['vista' => 'mes', 'notificaciones' => true, 'expedientes' => true, 'tareas' => true, 'festivos' => true, 'fiscal' => true],
            'productos' => ['tipo' => 'Servicio', 'iva' => 21],
            'tareas' => ['jornada_semanal' => 40, 'jornada_diaria' => 8, 'vacaciones' => 22, 'prioridad' => 'Media'],
            'contabilidad' => ['ventas' => '705', 'compras' => '600', 'gasto_servicios' => '629', 'gasto_profesional' => '623', 'gasto_arrendamiento' => '621',
                'iva_repercutido' => '477', 'iva_soportado' => '472', 'ret_soportada' => '473', 'ret_practicada' => '4751', 'suplidos' => '555', 'banco' => '572', 'caja' => '570',
                // Cuentas laborales (asiento de nómina); vacías = se eligen al hacer el asiento
                'sueldos' => '640', 'especie' => '', 'dietas' => '', 'indemn' => '641', 'acr_irpf_nom' => '4751', 'acr_irpf_esp' => '', 'rem_pend' => '465',
                'ss_acr' => '476', 'ss_emp' => '642', 'anticipos' => '', 'embargos' => '', 'ded_esp' => '', 'otras_ded' => ''],
            'fiscal' => ['aviso_dias' => 10, 'umbral_347' => 3005.06, 'pct_130' => 20, 'pct_202_402' => 18, 'pct_202_403' => 17],
            default => [],
        };
    }

    public static function get(string $m): array
    {
        if (isset(self::$cache[$m])) {
            return self::$cache[$m];
        }
        $guardado = [];
        try {
            $guardado = json_decode((string) Ajuste::get('param.'.$m, ''), true) ?: [];
        } catch (Throwable) {
            // Sin base de datos (instalación): valores por defecto
        }
        // Compatibilidad: avisos guardados antes de existir esta pestaña
        if ($m === 'notificaciones' && ! $guardado) {
            try {
                $guardado = json_decode((string) Ajuste::get('avisos_notificaciones', ''), true) ?: [];
            } catch (Throwable) {
            }
        }

        return self::$cache[$m] = array_merge(self::defecto($m), array_intersect_key($guardado, self::defecto($m)));
    }

    public static function put(string $m, array $v): void
    {
        Ajuste::put('param.'.$m, json_encode(array_intersect_key($v, self::defecto($m)), JSON_UNESCAPED_UNICODE));
        unset(self::$cache[$m]);
        Plazos::limpiarCache();
    }

    public static function limpiar(): void
    {
        self::$cache = [];
    }
}
