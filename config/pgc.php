<?php

/*
| Plan General de Contabilidad de PYMES (RD 1515/2007): cuentas de 3 y 4 cifras
| de uso habitual. Las subcuentas de 9 cifras se forman rellenando con ceros
| (472 → 472000000) o son propias de cada empresa (400000001, 430000001…).
| Si falta alguna cuenta del plan, añádela aquí.
*/

return [
    // GRUPO 1 · Financiación básica
    '100' => 'Capital social', '101' => 'Fondo social', '102' => 'Capital', '103' => 'Socios por desembolsos no exigidos', '108' => 'Acciones o participaciones propias en situaciones especiales',
    '110' => 'Prima de emisión o asunción', '112' => 'Reserva legal', '113' => 'Reservas voluntarias', '114' => 'Reservas especiales', '118' => 'Aportaciones de socios o propietarios', '119' => 'Diferencias por ajuste del capital a euros',
    '120' => 'Remanente', '121' => 'Resultados negativos de ejercicios anteriores', '129' => 'Resultado del ejercicio',
    '130' => 'Subvenciones oficiales de capital', '131' => 'Donaciones y legados de capital', '132' => 'Otras subvenciones, donaciones y legados',
    '141' => 'Provisión para impuestos', '142' => 'Provisión para otras responsabilidades', '143' => 'Provisión por desmantelamiento, retiro o rehabilitación del inmovilizado',
    '160' => 'Deudas a largo plazo con entidades de crédito vinculadas', '170' => 'Deudas a largo plazo con entidades de crédito', '171' => 'Deudas a largo plazo', '172' => 'Deudas a largo plazo transformables en subvenciones, donaciones y legados',
    '173' => 'Proveedores de inmovilizado a largo plazo', '174' => 'Acreedores por arrendamiento financiero a largo plazo', '175' => 'Efectos a pagar a largo plazo', '180' => 'Fianzas recibidas a largo plazo', '185' => 'Depósitos recibidos a largo plazo',
    // GRUPO 2 · Activo no corriente
    '200' => 'Investigación', '201' => 'Desarrollo', '202' => 'Concesiones administrativas', '203' => 'Propiedad industrial', '205' => 'Derechos de traspaso', '206' => 'Aplicaciones informáticas', '209' => 'Anticipos para inmovilizaciones intangibles',
    '210' => 'Terrenos y bienes naturales', '211' => 'Construcciones', '212' => 'Instalaciones técnicas', '213' => 'Maquinaria', '214' => 'Utillaje', '215' => 'Otras instalaciones', '216' => 'Mobiliario',
    '217' => 'Equipos para procesos de información', '218' => 'Elementos de transporte', '219' => 'Otro inmovilizado material', '220' => 'Inversiones en terrenos y bienes naturales', '221' => 'Inversiones en construcciones',
    '230' => 'Adaptación de terrenos y bienes naturales', '231' => 'Construcciones en curso', '232' => 'Instalaciones técnicas en montaje', '233' => 'Maquinaria en montaje', '239' => 'Anticipos para inmovilizaciones materiales',
    '240' => 'Participaciones a largo plazo en partes vinculadas', '250' => 'Inversiones financieras a largo plazo en instrumentos de patrimonio', '251' => 'Valores representativos de deuda a largo plazo', '252' => 'Créditos a largo plazo',
    '258' => 'Imposiciones a largo plazo', '260' => 'Fianzas constituidas a largo plazo', '265' => 'Depósitos constituidos a largo plazo',
    '280' => 'Amortización acumulada del inmovilizado intangible', '281' => 'Amortización acumulada del inmovilizado material', '282' => 'Amortización acumulada de las inversiones inmobiliarias',
    '290' => 'Deterioro de valor del inmovilizado intangible', '291' => 'Deterioro de valor del inmovilizado material',
    // GRUPO 3 · Existencias
    '300' => 'Mercaderías', '310' => 'Materias primas', '320' => 'Elementos y conjuntos incorporables', '321' => 'Combustibles', '322' => 'Repuestos', '325' => 'Materiales diversos', '326' => 'Embalajes', '327' => 'Envases', '328' => 'Material de oficina',
    '330' => 'Productos en curso', '340' => 'Productos semiterminados', '350' => 'Productos terminados', '360' => 'Subproductos, residuos y materiales recuperados', '390' => 'Deterioro de valor de las mercaderías',
    // GRUPO 4 · Acreedores y deudores por operaciones comerciales
    '400' => 'Proveedores', '401' => 'Proveedores, efectos comerciales a pagar', '403' => 'Proveedores, empresas del grupo', '406' => 'Envases y embalajes a devolver a proveedores', '407' => 'Anticipos a proveedores',
    '410' => 'Acreedores por prestaciones de servicios', '411' => 'Acreedores, efectos comerciales a pagar', '419' => 'Acreedores por operaciones en común',
    '430' => 'Clientes', '431' => 'Clientes, efectos comerciales a cobrar', '433' => 'Clientes, empresas del grupo', '436' => 'Clientes de dudoso cobro', '437' => 'Envases y embalajes a devolver por clientes', '438' => 'Anticipos de clientes',
    '440' => 'Deudores', '441' => 'Deudores, efectos comerciales a cobrar', '446' => 'Deudores de dudoso cobro', '449' => 'Deudores por operaciones en común',
    '460' => 'Anticipos de remuneraciones', '465' => 'Remuneraciones pendientes de pago', '466' => 'Remuneraciones mediante sistemas de aportación definida pendientes de pago',
    '470' => 'Hacienda Pública, deudora por diversos conceptos', '4700' => 'Hacienda Pública, deudora por IVA', '4708' => 'Hacienda Pública, deudora por subvenciones concedidas', '4709' => 'Hacienda Pública, deudora por devolución de impuestos',
    '471' => 'Organismos de la Seguridad Social, deudores', '472' => 'Hacienda Pública, IVA soportado', '473' => 'Hacienda Pública, retenciones y pagos a cuenta',
    '474' => 'Activos por impuesto diferido', '475' => 'Hacienda Pública, acreedora por conceptos fiscales', '4750' => 'Hacienda Pública, acreedora por IVA', '4751' => 'Hacienda Pública, acreedora por retenciones practicadas',
    '4752' => 'Hacienda Pública, acreedora por impuesto sobre sociedades', '4758' => 'Hacienda Pública, acreedora por subvenciones a reintegrar',
    '476' => 'Organismos de la Seguridad Social, acreedores', '477' => 'Hacienda Pública, IVA repercutido', '479' => 'Pasivos por diferencias temporarias imponibles',
    '480' => 'Gastos anticipados', '485' => 'Ingresos anticipados', '490' => 'Deterioro de valor de créditos por operaciones comerciales',
    // GRUPO 5 · Cuentas financieras
    '500' => 'Obligaciones y bonos a corto plazo', '520' => 'Deudas a corto plazo con entidades de crédito', '5200' => 'Préstamos a corto plazo de entidades de crédito', '5201' => 'Deudas a corto plazo por crédito dispuesto', '5208' => 'Deudas por efectos descontados',
    '521' => 'Deudas a corto plazo', '523' => 'Proveedores de inmovilizado a corto plazo', '524' => 'Acreedores por arrendamiento financiero a corto plazo', '526' => 'Dividendo activo a pagar', '527' => 'Intereses a corto plazo de deudas con entidades de crédito',
    '540' => 'Inversiones financieras a corto plazo en instrumentos de patrimonio', '541' => 'Valores representativos de deuda a corto plazo', '542' => 'Créditos a corto plazo', '544' => 'Créditos a corto plazo al personal',
    '548' => 'Imposiciones a corto plazo', '550' => 'Titular de la explotación', '551' => 'Cuenta corriente con socios y administradores', '552' => 'Cuenta corriente con otras personas y entidades vinculadas',
    '553' => 'Cuentas corrientes en fusiones y escisiones', '554' => 'Cuenta corriente con uniones temporales de empresas y comunidades de bienes', '555' => 'Partidas pendientes de aplicación', '556' => 'Desembolsos exigidos sobre participaciones en el patrimonio neto', '557' => 'Dividendo activo a cuenta',
    '560' => 'Fianzas recibidas a corto plazo', '561' => 'Depósitos recibidos a corto plazo', '565' => 'Fianzas constituidas a corto plazo', '566' => 'Depósitos constituidos a corto plazo',
    '570' => 'Caja, euros', '571' => 'Caja, moneda extranjera', '572' => 'Bancos e instituciones de crédito c/c vista, euros', '573' => 'Bancos e instituciones de crédito c/c vista, moneda extranjera', '574' => 'Bancos e instituciones de crédito, cuentas de ahorro, euros', '576' => 'Inversiones a corto plazo de gran liquidez',
    '580' => 'Activos no corrientes mantenidos para la venta', '593' => 'Deterioro de valor de participaciones a corto plazo',
    // GRUPO 6 · Compras y gastos
    '600' => 'Compras de mercaderías', '601' => 'Compras de materias primas', '602' => 'Compras de otros aprovisionamientos', '606' => 'Descuentos sobre compras por pronto pago', '607' => 'Trabajos realizados por otras empresas',
    '608' => 'Devoluciones de compras y operaciones similares', '609' => '«Rappels» por compras', '610' => 'Variación de existencias de mercaderías', '611' => 'Variación de existencias de materias primas', '612' => 'Variación de existencias de otros aprovisionamientos',
    '620' => 'Gastos en investigación y desarrollo del ejercicio', '621' => 'Arrendamientos y cánones', '622' => 'Reparaciones y conservación', '623' => 'Servicios de profesionales independientes', '624' => 'Transportes',
    '625' => 'Primas de seguros', '626' => 'Servicios bancarios y similares', '627' => 'Publicidad, propaganda y relaciones públicas', '628' => 'Suministros', '629' => 'Otros servicios',
    '630' => 'Impuesto sobre beneficios', '6300' => 'Impuesto corriente', '6301' => 'Impuesto diferido', '631' => 'Otros tributos', '633' => 'Ajustes negativos en la imposición sobre beneficios', '634' => 'Ajustes negativos en la imposición indirecta', '636' => 'Devolución de impuestos', '638' => 'Ajustes positivos en la imposición sobre beneficios', '639' => 'Ajustes positivos en la imposición indirecta',
    '640' => 'Sueldos y salarios', '641' => 'Indemnizaciones', '642' => 'Seguridad Social a cargo de la empresa', '643' => 'Retribuciones a largo plazo mediante sistemas de aportación definida', '649' => 'Otros gastos sociales',
    '650' => 'Pérdidas de créditos comerciales incobrables', '651' => 'Resultados de operaciones en común', '659' => 'Otras pérdidas en gestión corriente',
    '660' => 'Gastos financieros por actualización de provisiones', '662' => 'Intereses de deudas', '663' => 'Pérdidas por valoración de instrumentos financieros por su valor razonable', '665' => 'Intereses por descuento de efectos y operaciones de «factoring»', '666' => 'Pérdidas en participaciones y valores representativos de deuda', '667' => 'Pérdidas de créditos no comerciales', '668' => 'Diferencias negativas de cambio', '669' => 'Otros gastos financieros',
    '670' => 'Pérdidas procedentes del inmovilizado intangible', '671' => 'Pérdidas procedentes del inmovilizado material', '672' => 'Pérdidas procedentes de las inversiones inmobiliarias', '673' => 'Pérdidas procedentes de participaciones a largo plazo en partes vinculadas', '675' => 'Pérdidas por operaciones con obligaciones propias', '678' => 'Gastos excepcionales',
    '680' => 'Amortización del inmovilizado intangible', '681' => 'Amortización del inmovilizado material', '682' => 'Amortización de las inversiones inmobiliarias',
    '690' => 'Pérdidas por deterioro del inmovilizado intangible', '691' => 'Pérdidas por deterioro del inmovilizado material', '693' => 'Pérdidas por deterioro de existencias', '694' => 'Pérdidas por deterioro de créditos por operaciones comerciales', '695' => 'Dotación a la provisión por operaciones comerciales',
    // GRUPO 7 · Ventas e ingresos
    '700' => 'Ventas de mercaderías', '701' => 'Ventas de productos terminados', '702' => 'Ventas de productos semiterminados', '703' => 'Ventas de subproductos y residuos', '704' => 'Ventas de envases y embalajes', '705' => 'Prestaciones de servicios',
    '706' => 'Descuentos sobre ventas por pronto pago', '708' => 'Devoluciones de ventas y operaciones similares', '709' => '«Rappels» sobre ventas', '710' => 'Variación de existencias de productos en curso', '712' => 'Variación de existencias de productos terminados',
    '730' => 'Trabajos realizados para el inmovilizado intangible', '731' => 'Trabajos realizados para el inmovilizado material', '740' => 'Subvenciones, donaciones y legados a la explotación', '746' => 'Subvenciones, donaciones y legados de capital transferidos al resultado del ejercicio', '747' => 'Otras subvenciones, donaciones y legados transferidos al resultado del ejercicio',
    '751' => 'Resultados de operaciones en común', '752' => 'Ingresos por arrendamientos', '753' => 'Ingresos de propiedad industrial cedida en explotación', '754' => 'Ingresos por comisiones', '755' => 'Ingresos por servicios al personal', '759' => 'Ingresos por servicios diversos',
    '760' => 'Ingresos de participaciones en instrumentos de patrimonio', '761' => 'Ingresos de valores representativos de deuda', '762' => 'Ingresos de créditos', '763' => 'Beneficios por valoración de instrumentos financieros por su valor razonable', '766' => 'Beneficios en participaciones y valores representativos de deuda', '768' => 'Diferencias positivas de cambio', '769' => 'Otros ingresos financieros',
    '770' => 'Beneficios procedentes del inmovilizado intangible', '771' => 'Beneficios procedentes del inmovilizado material', '772' => 'Beneficios procedentes de las inversiones inmobiliarias', '775' => 'Beneficios por operaciones con obligaciones propias', '778' => 'Ingresos excepcionales',
    '790' => 'Reversión del deterioro del inmovilizado intangible', '791' => 'Reversión del deterioro del inmovilizado material', '793' => 'Reversión del deterioro de existencias', '794' => 'Reversión del deterioro de créditos por operaciones comerciales', '795' => 'Exceso de provisiones',
];
