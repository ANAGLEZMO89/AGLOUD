<?php

/*
|--------------------------------------------------------------------------
| AG Cloud · catálogos y parámetros de negocio
|--------------------------------------------------------------------------
| Valores tomados del prototipo (AG Cloud.dc.html). Cambiar aquí si el
| despacho necesita otra estructura de carpetas o plazos.
*/

return [

    'despacho' => [
        'nombre' => env('DESPACHO_NOMBRE', 'GA AGOPYME SL'),
        'nif' => env('DESPACHO_NIF', ''),
        'email' => env('DESPACHO_EMAIL', 'info@gestoriaagopyme.es'),
        'telefono' => env('DESPACHO_TELEFONO', ''),
        'provincia' => env('DESPACHO_PROVINCIA', 'Huelva'),
    ],

    // Carpeta privada de documentos cifrados (fuera de public_html)
    'docs_path' => env('DOCS_PATH', storage_path('app/privado')),

    // Tamaño máximo por archivo subido (KB) y cupo orientativo por cliente (MB)
    'max_upload_kb' => (int) env('AGCLOUD_MAX_UPLOAD_KB', 20480),
    'cupo_mb' => (int) env('AGCLOUD_CUPO_MB', 5120),

    'roles' => [
        'admin_gestoria' => 'Administración del despacho',
        'asesor' => 'Asesor',
        'cliente_admin' => 'Cliente · administrador',
        'cliente_empleado' => 'Cliente · empleado',
    ],

    'tipos_cliente' => ['Autónomo', 'Sociedad', 'Comunidad de bienes', 'Particular'],
    'filtros_tipo' => ['Todos' => null, 'Autónomos' => 'Autónomo', 'Sociedades' => 'Sociedad', 'CB' => 'Comunidad de bienes', 'Particulares' => 'Particular'],

    'estados_cliente' => [
        'Activo' => ['#E6F6A8', '#2E3A00'],
        'Alta en curso' => ['#FCEBCB', '#6E4300'],
        'Baja' => ['#ECECE7', '#6A6A65'],
    ],

    // Gestor documental: carpetas y subcarpetas (DOC_F)
    'carpetas' => [
        'fiscal' => ['Fiscal', ['modelos' => 'Modelos presentados', 'certificados' => 'Certificados tributarios', 'censal' => 'Censales (036/037)', 'requerimientos' => 'Requerimientos y notificaciones']],
        'contable' => ['Contable', ['emitidas' => 'Facturas emitidas', 'recibidas' => 'Facturas recibidas', 'bancos' => 'Extractos bancarios', 'libros' => 'Libros contables', 'anuales' => 'Cuentas anuales']],
        'laboral' => ['Laboral', ['contratos' => 'Contratos de trabajo', 'nominas' => 'Nóminas', 'seguros' => 'Seguros sociales (RLC/RNT)', 'altas' => 'Altas, bajas y variaciones', 'certemp' => 'Certificados de empresa', 'prl' => 'Prevención y formación']],
        'mercantil' => ['Mercantil', ['escrituras' => 'Escrituras', 'actas' => 'Actas y libro de socios', 'poderes' => 'Poderes']],
        'ident' => ['Identificación', ['dni' => 'DNI, NIE y CIF', 'certdig' => 'Certificados digitales', 'bancarios' => 'Certificados bancarios']],
        'otros' => ['Otros', ['contratos' => 'Contratos mercantiles', 'polizas' => 'Pólizas de seguro', 'vehiculos' => 'Vehículos', 'varios' => 'Varios']],
    ],

    'tipos_documento' => ['fra' => 'Factura', 'alb' => 'Albarán', 'rec' => 'Recibo', 'nom' => 'Nómina', 'mod' => 'Modelo', 'ext' => 'Extracto', 'tick' => 'Ticket', 'contr' => 'Contrato', 'acta' => 'Acta', 'cert' => 'Certificado', 'poder' => 'Poder', 'cv' => 'Currículum', 'docu' => 'Documento'],

    // Plazos de conservación (años, fundamento) — DOC_KEEP del prototipo
    'conservacion' => [
        'fiscal' => [4, 'art. 66 LGT'],
        'contable' => [6, 'art. 30 Código de Comercio'],
        'laboral' => [4, 'art. 21 LISOS'],
        'mercantil' => [6, 'art. 30 Código de Comercio'],
    ],

    'provincias' => ['A Coruña', 'Álava', 'Albacete', 'Alicante', 'Almería', 'Asturias', 'Ávila', 'Badajoz', 'Barcelona', 'Bizkaia', 'Burgos', 'Cáceres', 'Cádiz', 'Cantabria', 'Castellón', 'Ceuta', 'Ciudad Real', 'Córdoba', 'Cuenca', 'Gipuzkoa', 'Girona', 'Granada', 'Guadalajara', 'Huelva', 'Huesca', 'Illes Balears', 'Jaén', 'La Rioja', 'Las Palmas', 'León', 'Lleida', 'Lugo', 'Madrid', 'Málaga', 'Melilla', 'Murcia', 'Navarra', 'Ourense', 'Palencia', 'Pontevedra', 'Salamanca', 'Santa Cruz de Tenerife', 'Segovia', 'Sevilla', 'Soria', 'Tarragona', 'Teruel', 'Toledo', 'Valencia', 'Valladolid', 'Zamora', 'Zaragoza'],

    // Orden INE (código postal / CCC → provincia)
    'provincias_ine' => ['Álava', 'Albacete', 'Alicante', 'Almería', 'Ávila', 'Badajoz', 'Illes Balears', 'Barcelona', 'Burgos', 'Cáceres', 'Cádiz', 'Castellón', 'Ciudad Real', 'Córdoba', 'A Coruña', 'Cuenca', 'Girona', 'Granada', 'Guadalajara', 'Gipuzkoa', 'Huelva', 'Huesca', 'Jaén', 'León', 'Lleida', 'La Rioja', 'Lugo', 'Madrid', 'Málaga', 'Murcia', 'Navarra', 'Ourense', 'Asturias', 'Palencia', 'Las Palmas', 'Pontevedra', 'Salamanca', 'Santa Cruz de Tenerife', 'Cantabria', 'Segovia', 'Sevilla', 'Soria', 'Tarragona', 'Teruel', 'Toledo', 'Valencia', 'Valladolid', 'Bizkaia', 'Zamora', 'Zaragoza', 'Ceuta', 'Melilla'],

    'bancos' => ['0049' => ['Banco Santander', 'BSCHESMMXXX'], '0030' => ['Banco Santander', 'ESPCESMMXXX'], '0075' => ['Banco Santander', 'POPUESMMXXX'], '0182' => ['BBVA', 'BBVAESMMXXX'], '2100' => ['CaixaBank', 'CAIXESBBXXX'], '2038' => ['CaixaBank', 'CAHMESMMXXX'], '0081' => ['Banco Sabadell', 'BSABESBBXXX'], '0128' => ['Bankinter', 'BKBKESMMXXX'], '3058' => ['Cajamar', 'CCRIES2AXXX'], '1465' => ['ING', 'INGDESMMXXX'], '0073' => ['Openbank', 'OPENESMMXXX'], '2080' => ['Abanca', 'CAGLESMMXXX'], '2085' => ['Ibercaja', 'CAZRES2ZXXX'], '2095' => ['Kutxabank', 'BASKES2BXXX'], '0239' => ['EVO Banco', 'EVOBESMMXXX'], '1491' => ['Triodos Bank', 'TRIOESMMXXX'], '2103' => ['Unicaja', 'UCJAES2MXXX'], '2048' => ['Unicaja (Liberbank)', 'CECAESMM048'], '0237' => ['Cajasur', 'CSURES2CXXX'], '0487' => ['Banco Mare Nostrum', 'GBMNESMMXXX'], '3035' => ['Laboral Kutxa', 'CLPEES2MXXX'], '0061' => ['Banca March', 'BMARES2MXXX'], '0186' => ['Banco Mediolanum', 'BFIVESBBXXX'], '0019' => ['Deutsche Bank', 'DEUTESBBXXX'], '3025' => ["Caixa d'Enginyers", 'CDENESBBXXX'], '3183' => ['Arquia Bank', 'CASDESBBXXX'], '0234' => ['Banco Caminos', 'CCOCESMMXXX'], '0083' => ['Renta 4 Banco', 'RENBESMMXXX'], '0138' => ['Bankoa', 'BKOAES22XXX'], '1583' => ['Revolut Bank', 'REVOESM2XXX']],

    'iva_reg' => ['Régimen general', 'Recargo de equivalencia', 'Simplificado (módulos)', 'REAGP', 'Exento'],
    'irpf_reg' => ['Estimación directa normal', 'Estimación directa simplificada', 'Estimación objetiva (módulos)', 'No aplica (Impuesto sobre Sociedades)', 'Atribución de rentas (CB)'],
    'cargos' => ['Administrador único', 'Administrador solidario', 'Administrador mancomunado', 'Consejero delegado', 'Presidente del consejo', 'Secretario del consejo', 'Apoderado', 'Socio sin cargo'],
    'cargos_cb' => ['Comunero', 'Comunero administrador', 'Apoderado'],
    'reg_tipos' => ['Constitución', 'Ampliación de capital', 'Reducción de capital', 'Nombramiento de cargos', 'Cese de cargos', 'Cambio de domicilio', 'Modificación de estatutos', 'Otorgamiento de poderes', 'Revocación de poderes', 'Transformación', 'Disolución', 'Otros'],
    'reg_cb' => ['Contrato privado de constitución', 'Escritura de constitución', 'Entrada o salida de comuneros', 'Modificación del contrato', 'Disolución', 'Otros'],
    'cert_tipos' => ['Persona física', 'Representante de persona jurídica', 'Representante de entidad sin personalidad jurídica', 'Sello electrónico'],
    'emisores' => ['FNMT-RCM', 'Camerfirma', 'ACCV', 'Firmaprofesional', 'ANF AC', 'Izenpe', 'Otro'],

    'cuota' => [
        'periodicidad' => ['Mensual', 'Trimestral', 'Semestral', 'Anual', 'Por trámite'],
        'iva' => ['21' => '21 %', '10' => '10 %', '0' => 'Exento (0 %)'],
        'forma_cobro' => ['Domiciliación SEPA', 'Transferencia', 'Tarjeta', 'Efectivo'],
    ],

    // Menú lateral AG Cloud (idéntico al prototipo). 'ruta' null = módulo en preparación.
    'menu' => [
        ['label' => 'Gestión', 'items' => [
            ['clientes', 'Clientes', 'clientes.index'],
            ['proveedores', 'Proveedores', 'proveedores.index'],
            ['acreedores', 'Acreedores', 'acreedores.index'],
            ['calendario', 'Calendario', 'calendario'],
            ['tareas', 'Tareas y horario', 'tareas.index'],
        ]],
        ['label' => 'Áreas', 'items' => [
            ['contabilidad', 'Contabilidad', 'contabilidad.index'],
            ['fiscal', 'Fiscal', 'fiscal.index'],
            ['laboral', 'Laboral y SS', null],
            ['facturacion', 'Facturación', 'facturacion.index'],
            ['crm', 'CRM', 'crm.index'],
            ['horario', 'Control horario', null],
        ]],
        ['label' => 'Comunicación', 'items' => [
            ['notificaciones', 'Notificaciones', 'notificaciones.index'],
            ['documentos', 'Documentos', 'documentos.index'],
            ['portal', 'Portal del cliente', null],
        ]],
        ['label' => 'Análisis', 'items' => [
            ['informes', 'Informes', null],
        ]],
    ],

    // Menú del portal del cliente (prototipo). Solo Gestor documental activo en fase 1.
    'menu_portal' => [
        ['label' => 'Gestión', 'items' => [
            ['pt_clientes', 'Clientes', null], ['pt_proveedores', 'Proveedores', null], ['pt_plazos', 'Plazos', null], ['crm', 'CRM', null],
        ]],
        ['label' => 'Áreas', 'items' => [
            ['pt_conta', 'Contabilidad', null], ['pt_fiscal', 'Fiscal', null], ['facturacion', 'Facturación', null],
        ]],
        ['label' => 'Documentos', 'items' => [
            ['documentos', 'Gestor documental', 'portal.documentos'],
        ]],
        ['label' => 'Análisis', 'items' => [
            ['informes', 'Informes', null],
        ]],
        ['label' => 'Comunicación', 'items' => [
            ['pt_msgs', 'Mi asesor', null],
        ]],
    ],

    // ---------------- Fase 2 · Notificaciones ----------------
    'organismos' => [
        'AEAT' => 'Agencia Tributaria',
        'TGSS' => 'Tesorería General de la Seguridad Social',
        'DGT' => 'Dirección General de Tráfico',
        'SEPE' => 'Servicio Público de Empleo Estatal',
        'JA' => 'Junta de Andalucía',
        'AYTO' => 'Ayuntamiento',
        'OTRO' => 'Otro organismo',
    ],
    'organismos_url' => [
        'AEAT' => 'https://sede.agenciatributaria.gob.es/Sede/notificaciones.html',
        'TGSS' => 'https://sede.seg-social.gob.es/',
        'DGT' => 'https://dehu.redsara.es/',
        'SEPE' => 'https://dehu.redsara.es/',
        'JA' => 'https://dehu.redsara.es/',
        'AYTO' => 'https://dehu.redsara.es/',
        'OTRO' => 'https://dehu.redsara.es/',
    ],
    'canales' => ['Sede AEAT', 'Sede TGSS', 'DEHú', 'Correo postal', 'Otro'],
    // Plantillas de notificación (NT_TPL del prototipo): [organismo, canal, tipo, plazo número, h|m|n, acción]
    'tipos_notificacion' => [
        ['AEAT', 'Sede AEAT', 'Requerimiento de información', 10, 'h', 'Requerimiento'],
        ['AEAT', 'Sede AEAT', 'Comunicación de inicio de comprobación limitada', 10, 'h', 'Alegaciones'],
        ['AEAT', 'Sede AEAT', 'Propuesta de liquidación provisional', 10, 'h', 'Alegaciones'],
        ['AEAT', 'Sede AEAT', 'Providencia de apremio', 1, 'm', 'Recurso de reposición'],
        ['AEAT', 'Sede AEAT', 'Acuerdo de devolución', 1, 'm', 'Recurso de reposición'],
        ['TGSS', 'Sede TGSS', 'Reclamación de deuda', 1, 'm', 'Recurso de alzada'],
        ['TGSS', 'DEHú', 'Resolución de alta de oficio', 1, 'm', 'Recurso de alzada'],
        ['TGSS', 'Sede TGSS', 'Comunicación informativa', 0, 'n', ''],
        ['DGT', 'DEHú', 'Notificación de denuncia', 20, 'n', 'Identificar conductor o alegar'],
        ['SEPE', 'DEHú', 'Requerimiento de documentación', 10, 'h', 'Requerimiento'],
        ['AYTO', 'DEHú', 'Liquidación de tasa', 1, 'm', 'Recurso de reposición'],
        ['JA', 'DEHú', 'Resolución de subvención', 1, 'm', 'Recurso de alzada'],
    ],
    // Comunidad autónoma para los festivos autonómicos fijos (AN = Andalucía: 28-F y Jueves Santo)
    'comunidad' => env('FESTIVOS_COMUNIDAD', 'AN'),
    // Festivos además de los nacionales (que se calculan solos). Formato AAAA-MM-DD, separados por comas.
    // Ej.: autonómicos de Andalucía y locales de Huelva. Revísalos cada año.
    'festivos_extra' => array_filter(array_map('trim', explode(',', (string) env('FESTIVOS_EXTRA', '')))),
    // Avisar cuando falten estos días para que caduque una notificación sin abrir
    'avisar_dias' => (int) env('NOTIF_AVISAR_DIAS', 3),

    // ---------------- Fase 2 · Expedientes ----------------
    'estados_expediente' => [
        'Abierto' => ['#F4F4F0', '#0B0B0B'],
        'En curso' => ['#E6F6A8', '#2E3A00'],
        'Esperando cliente' => ['#FCEBCB', '#6E4300'],
        'Presentado' => ['#0B0B0B', '#C8F031'],
        'Cerrado' => ['#ECECE7', '#6A6A65'],
    ],
    // Plantillas de trámite (TPL del prototipo): categoría, honorarios orientativos (€ sin IVA), pasos y documentos
    'plantillas_expediente' => [
        'Constitución de SL' => ['cat' => 'Mercantil', 'hon' => 350, 'pasos' => ['Certificación negativa de denominación', 'Cuenta bancaria y depósito del capital', 'Firma de la escritura en notaría', 'Alta censal (036) y NIF definitivo', 'Inscripción en el Registro Mercantil'], 'docs' => ['DNI de los socios', 'Estatutos revisados', 'Certificado bancario del capital']],
        'Arraigo sociolaboral' => ['cat' => 'Extranjería', 'hon' => 300, 'pasos' => ['Revisión de requisitos', 'Recopilación de documentación', 'Presentación telemática', 'Subsanación si hay requerimiento', 'Resolución'], 'docs' => ['Pasaporte completo', 'Certificado de empadronamiento', 'Contrato de trabajo firmado', 'Antecedentes penales del país de origen']],
        'Transferencia DGT' => ['cat' => 'Tráfico', 'hon' => 90, 'pasos' => ['Comprobación de cargas del vehículo', 'Liquidación del ITP', 'Presentación en la DGT', 'Envío del permiso de circulación'], 'docs' => ['DNI de comprador y vendedor', 'Contrato de compraventa', 'Permiso de circulación y ficha técnica']],
        'Subvención para pymes' => ['cat' => 'Subvenciones', 'hon' => 150, 'pasos' => ['Estudio de requisitos', 'Preparación de la memoria', 'Presentación de la solicitud', 'Resolución', 'Justificación'], 'docs' => ['Certificados al corriente AEAT y TGSS', 'Presupuestos o facturas de la inversión', 'Memoria del proyecto']],
        'Alta en RETA' => ['cat' => 'Autónomos', 'hon' => 60, 'pasos' => ['Alta censal (036/037)', 'Alta en el RETA', 'Elección de base de cotización', 'Acceso al portal del cliente'], 'docs' => ['DNI', 'IBAN para domiciliación', 'Descripción de la actividad']],
        'Requerimiento AEAT' => ['cat' => 'Notificaciones', 'hon' => 120, 'pasos' => ['Lectura y análisis', 'Recopilación de justificantes', 'Redacción de la respuesta', 'Presentación por registro electrónico', 'Resolución'], 'docs' => ['Facturas del periodo requerido', 'Libros registro']],
        'Reclamación TGSS' => ['cat' => 'Notificaciones', 'hon' => 120, 'pasos' => ['Revisión de la deuda', 'Comprobación de pagos', 'Pago o alegaciones', 'Cierre'], 'docs' => ['Recibos de cuotas', 'Extracto bancario']],
        'Licencia de apertura' => ['cat' => 'Licencias', 'hon' => 250, 'pasos' => ['Consulta urbanística', 'Proyecto técnico', 'Declaración responsable', 'Inspección municipal'], 'docs' => ['Contrato de alquiler', 'Planos del local', 'Certificado de instalaciones']],
        'Otro trámite' => ['cat' => 'Otros', 'hon' => 0, 'pasos' => ['Análisis', 'Preparación', 'Presentación', 'Resolución'], 'docs' => []],
    ],

    // Copias de seguridad
    'backup' => [
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'dias_local' => (int) env('BACKUP_DIAS_LOCAL', 7),
        'dias_drive' => (int) env('BACKUP_DIAS_DRIVE', 30),
    ],
];
