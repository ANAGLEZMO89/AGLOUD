@extends('layouts.app')
@php
    use App\Http\Controllers\FacturaController as FC;
    use App\Models\Oportunidad;
    use App\Support\Formato;
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h"><div class="t"><span class="crumb">{{ $grupo }} · {{ $nombre }}</span><h1 class="ttl">{{ $nombre }}</h1></div>@if($ctx ?? null)@include('partials.empresa', ['c' => $ctx, 'volver' => '/parametros/'.$modulo])@endif</div>
  @if($ctx ?? null)<div class="msg" style="font-weight:500">Parámetros {{ $ctx->es_despacho ? 'del despacho' : 'de '.$ctx->nombre }}: solo se aplican a {{ $ctx->es_despacho ? 'tu despacho' : 'esta empresa' }}. Cada empresa tiene los suyos.</div>@endif
  @if($modulo === 'crm')
    <div class="chipbar" style="border-radius:11px;align-self:flex-start">
      @foreach([['embudo', 'Embudo'], ['pres', 'Presupuestos'], ['agenda', 'Agenda']] as [$id, $l])<a style="padding:7px 14px;border-radius:8px" href="{{ route('crm.index', $id === 'embudo' ? [] : ['tab' => $id]) }}">{{ $l }}</a>@endforeach
      <a class="on" style="padding:7px 14px;border-radius:8px" href="{{ route('parametros', 'crm') }}">Parámetros</a>
    </div>
  @else
    @include('partials.tabs-modulo', ['actual' => 'param'])
  @endif
  @unless($puede)<div class="msg warn">Solo la administración del despacho puede cambiar estos parámetros. Puedes consultarlos.</div>@endunless
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <form method="POST" action="{{ route('parametros.guardar', $modulo) }}" style="display:flex;flex-direction:column;gap:16px">
    @csrf
    <fieldset @disabled(! $puede) style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:16px;min-width:0">

    @if($modulo === 'clientes')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Cuota por defecto de los clientes nuevos</span>
        <span class="hint muted">Se proponen al dar de alta un cliente. Luego se pueden cambiar en su ficha.</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:12px">
          <label class="lbl-s">Periodicidad<select class="sel" name="periodicidad">@foreach(config('agcloud.cuota.periodicidad') as $x)<option @selected(old('periodicidad', $p['periodicidad']) === $x)>{{ $x }}</option>@endforeach</select></label>
          <label class="lbl-s">IVA de la cuota<select class="sel" name="iva">@foreach(config('agcloud.cuota.iva') as $k => $l)<option value="{{ $k }}" @selected((string) old('iva', $p['iva']) === (string) $k)>{{ $l }}</option>@endforeach</select></label>
          <label class="lbl-s">Forma de cobro<input class="inp" name="forma_cobro" value="{{ old('forma_cobro', $p['forma_cobro']) }}" maxlength="60"></label>
          <label class="lbl-s">Concepto<input class="inp" name="concepto" value="{{ old('concepto', $p['concepto']) }}" maxlength="120"></label>
        </div>
      </div>
    @endif

    @if($modulo === 'documentos')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Gestor documental</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr));gap:12px">
          <label class="lbl-s">Espacio por cliente (MB)<input class="inp mono" type="number" name="cupo_mb" min="100" max="102400" value="{{ old('cupo_mb', $p['cupo_mb']) }}"><span class="hint muted">Se muestra en la tarjeta «Documentos».</span></label>
          <label class="lbl-s">Avisar de caducidad con (días)<input class="inp mono" type="number" name="aviso_caducidad" min="7" max="365" value="{{ old('aviso_caducidad', $p['aviso_caducidad']) }}"><span class="hint muted">DNI, certificados digitales… aparecen en «Caducan pronto».</span></label>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;padding:12px;border-radius:12px;background:#F4F4F0;font-size:13px">
          <b>Plazos de conservación (fijados por ley)</b>
          @foreach(config('agcloud.conservacion') as $k => [$a, $fund])<span>{{ config('agcloud.carpetas.'.$k.'.0', ucfirst($k)) }}: {{ $a }} años · {{ $fund }}</span>@endforeach
        </div>
      </div>
    @endif

    @if($modulo === 'notificaciones')
      <div class="card" style="padding:18px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:16px">
        <span style="grid-column:1 / -1;font-size:16px;font-weight:700">Avisos</span>
        <div style="display:flex;flex-direction:column;gap:6px"><label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" data-avisos-nav style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Avisos en este navegador<span style="font-size:12px;font-weight:400;color:#6A6A65">Al entrar en AG Cloud, si hay notificaciones a punto de caducar. Se activa en cada navegador.</span></span></label><span style="font-size:12px;color:#6A6A65;padding-left:28px" data-avisos-perm></span></div>
        <label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" name="email" value="1" @checked($p['email']) style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Resumen por correo al asesor<span style="font-size:12px;font-weight:400;color:#6A6A65">Cada mañana, con las notificaciones sin abrir de sus clientes.</span></span></label>
        <label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" name="wa" value="1" @checked($p['wa']) style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Avisar al cliente por WhatsApp<span style="font-size:12px;font-weight:400;color:#6A6A65">Propone el mensaje al abrir una notificación con plazo.</span></span></label>
        <label class="lbl-s" style="font-size:13.5px">Avisar antes de que caduque<select class="sel" name="dias">@foreach([1, 3, 5] as $d)<option value="{{ $d }}" @selected((int) $p['dias'] === $d)>{{ $d }} {{ $d === 1 ? 'día' : 'días' }} antes</option>@endforeach</select></label>
      </div>
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Días inhábiles para el cálculo de plazos</span>
        <span class="hint muted">Los festivos nacionales (incluido Viernes Santo) se calculan solos. Añade aquí los locales y los traslados de domingo a lunes de cada año. Si falta un festivo, el plazo calculado será más corto que el real.</span>
        <label class="lbl-s">Festivos autonómicos fijos<select class="sel" name="comunidad" style="max-width:420px"><option value="AN" @selected($p['comunidad'] === 'AN')>Andalucía (28 de febrero y Jueves Santo)</option><option value="" @selected($p['comunidad'] === '')>Ninguno</option></select></label>
        <label class="lbl-s">Otros festivos · uno por línea: dd/mm/aaaa Nombre<textarea class="txa mono" name="festivos" rows="6" placeholder="Ejemplo: 15/05/2027 Fiesta local de mi municipio">{{ old('festivos', collect($p['festivos'])->map(fn ($f) => Formato::fecha($f['fecha']).' '.$f['nombre'])->implode("\n")) }}</textarea></label>
      </div>
      <div class="card" style="padding:18px 20px;gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">Tipos de notificación (plantillas)</span>@if($puede)<button type="button" class="pillbtn" style="height:32px" data-row-add="tipos">+ Añadir</button>@endif</div>
        <span class="hint muted">Rellenan el registro de una notificación. Revisa siempre el plazo con el que indique el propio documento.</span>
        <div class="par-tipos par-head"><span>Organismo</span><span>Canal</span><span>Tipo</span><span>Plazo</span><span>Días</span><span>Actuación</span><span></span></div>
        <div data-rows="tipos" style="display:flex;flex-direction:column;gap:8px">
          @foreach($p['tipos'] as $i => $t)
            @include('parametros._tipo', ['i' => $i, 't' => $t])
          @endforeach
        </div>
        <template data-row-tpl="tipos">@include('parametros._tipo', ['i' => '__', 't' => ['AEAT', 'Sede AEAT', '', 10, 'h', '']])</template>
      </div>
    @endif

    @if($modulo === 'expedientes')
      <div class="card" style="padding:16px 20px;gap:10px">
        <label class="chk" style="gap:10px"><input type="checkbox" name="portal_defecto" value="1" @checked($p['portal_defecto']) style="width:18px;height:18px;accent-color:#0B0B0B"><span style="font-size:13.5px;font-weight:600">Los expedientes nuevos se ven en el portal del cliente</span></label>
      </div>
      <div class="card" style="padding:18px 20px;gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">Tipos de trámite (plantillas)</span>@if($puede)<button type="button" class="pillbtn" style="height:32px" data-row-add="pl">+ Añadir trámite</button>@endif</div>
        <span class="hint muted">Al abrir un expediente se copian los pasos, la documentación y los honorarios. Cambiar una plantilla no modifica los expedientes ya abiertos.</span>
        <div data-rows="pl" style="display:flex;flex-direction:column;gap:12px">
          @php $j = 0; @endphp
          @foreach($p['plantillas'] as $n => $t)
            @include('parametros._tramite', ['i' => $j++, 'n' => $n, 't' => $t])
          @endforeach
        </div>
        <template data-row-tpl="pl">@include('parametros._tramite', ['i' => '__', 'n' => '', 't' => ['cat' => 'Otros', 'hon' => 0, 'pasos' => [], 'docs' => []]])</template>
      </div>
    @endif

    @if($modulo === 'crm')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Embudo</span>
        <span class="hint muted">Probabilidad de cierre de cada etapa: se usa para la «Previsión ponderada».</span>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
          @foreach(array_keys(Oportunidad::ETAPAS) as $e)<label class="lbl-s">{{ $e }} (%)<input class="inp mono" type="number" min="0" max="100" name="prob[{{ $e }}]" value="{{ old('prob.'.$e, $p['probabilidades'][$e] ?? Oportunidad::ETAPAS[$e]) }}"></label>@endforeach
        </div>
        <label class="lbl-s">Orígenes de los clientes potenciales · uno por línea<textarea class="txa" name="origenes" rows="6">{{ old('origenes', implode("\n", $p['origenes'])) }}</textarea></label>
      </div>
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Presupuestos</span>
        <label class="lbl-s" style="max-width:260px">Validez por defecto<select class="sel" name="validez">@foreach([15, 30, 60, 90] as $v)<option value="{{ $v }}" @selected((int) $p['validez'] === $v)>{{ $v }} días</option>@endforeach</select></label>
        <label class="lbl-s">Texto al pie del presupuesto<textarea class="txa" name="pie_presupuesto" rows="2" maxlength="500">{{ old('pie_presupuesto', $p['pie_presupuesto']) }}</textarea></label>
      </div>
    @endif

    @if(in_array($modulo, ['proveedores', 'acreedores'], true))
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Valores por defecto de los {{ $modulo }} nuevos</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:12px">
          <label class="lbl-s">Forma de pago<select class="sel" name="forma_pago">@foreach(\App\Http\Controllers\TerceroController::PAGOS as $x)<option @selected($p['forma_pago'] === $x)>{{ $x }}</option>@endforeach</select></label>
          <label class="lbl-s">Vencimiento<select class="sel" name="pago_dias">@foreach(['0' => 'Al contado', '15' => '15 días', '30' => '30 días', '45' => '45 días', '60' => '60 días', '90' => '90 días'] as $k => $l)<option value="{{ $k }}" @selected((string) $p['pago_dias'] === (string) $k)>{{ $l }}</option>@endforeach</select></label>
          @if($modulo === 'acreedores')
            <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(\App\Models\Tercero::TIPOS_ACREEDOR as $x)<option @selected($p['tipo'] === $x)>{{ $x }}</option>@endforeach</select></label>
            <label class="lbl-s">Retención IRPF %<select class="sel" name="retencion">@foreach(['0', '7', '15', '19'] as $x)<option @selected((string) $p['retencion'] === $x)>{{ $x }}</option>@endforeach</select></label>
          @endif
        </div>
        <span class="hint muted">La cuenta contable se asigna sola (400… proveedores, 410… acreedores) o a mano en cada ficha.</span>
      </div>
    @endif

    @if($modulo === 'contabilidad')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Cuentas que se usan al contabilizar las facturas</span>
        <span class="hint muted">Para todas las empresas. Puedes escribir la cuenta con 3 o 4 cifras (se completa con ceros) o una subcuenta de 9.</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:12px">
          @foreach(['ventas' => 'Ventas / prestación de servicios', 'compras' => 'Compras (facturas de proveedores)', 'gasto_servicios' => 'Gastos de acreedores «Servicios»', 'gasto_profesional' => 'Gastos de profesionales', 'gasto_arrendamiento' => 'Arrendamientos', 'iva_repercutido' => 'IVA repercutido', 'iva_soportado' => 'IVA soportado', 'ret_soportada' => 'Retenciones que nos practican', 'ret_practicada' => 'Retenciones que practicamos', 'suplidos' => 'Suplidos facturados', 'banco' => 'Banco por defecto (cobros y pagos)', 'caja' => 'Caja (cobros y pagos en efectivo)'] as $k => $l)
            <label class="lbl-s">{{ $l }}<input class="inp mono" name="{{ $k }}" value="{{ old($k, $p[$k]) }}" maxlength="9"><span class="hint muted">{{ \App\Services\Contabilidad::nombre(\App\Services\Contabilidad::a9((string) $p[$k])) }}</span></label>
          @endforeach
        </div>
      </div>
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Cuentas laborales (asiento de nómina)</span>
        <span class="hint muted">Se proponen al hacer un asiento de nómina en Mantenimiento de apuntes. Las que dejes vacías se eligen en cada asiento.</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:12px">
          @foreach(['sueldos' => 'Sueldos y salarios', 'especie' => 'Sueldos y salarios en especie', 'dietas' => 'Dietas de nóminas', 'indemn' => 'Indemnizaciones', 'acr_irpf_nom' => 'Acreedor IRPF (nóminas)', 'acr_irpf_esp' => 'Acreedor IRPF en especie', 'rem_pend' => 'Remuneraciones pendientes de pago', 'ss_acr' => 'Seguridad Social acreedora', 'ss_emp' => 'Seguridad Social a cargo de la empresa', 'anticipos' => 'Anticipos', 'embargos' => 'Embargos', 'ded_esp' => 'Deducciones en especie', 'otras_ded' => 'Otras deducciones'] as $k => $l)
            <label class="lbl-s">{{ $l }}<input class="inp mono" name="{{ $k }}" value="{{ old($k, $p[$k] ?? '') }}" maxlength="9" placeholder="Sin cuenta"><span class="hint muted">{{ ($p[$k] ?? '') !== '' ? \App\Services\Contabilidad::nombre(\App\Services\Contabilidad::a9((string) $p[$k])) : '' }}</span></label>
          @endforeach
        </div>
      </div>
    @endif

    @if($modulo === 'productos')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Valores por defecto de los productos nuevos</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:12px">
          <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(\App\Http\Controllers\ProductoController::TIPOS as $x)<option @selected($p['tipo'] === $x)>{{ $x }}</option>@endforeach</select></label>
          <label class="lbl-s">IVA %<select class="sel" name="iva">@foreach(\App\Http\Controllers\ProductoController::IVAS as $x)<option @selected((string) $p['iva'] === $x)>{{ $x }}</option>@endforeach</select></label>
        </div>
        <span class="hint muted">Cada empresa tiene su propio catálogo en Gestión › Productos; se usa al hacer facturas y presupuestos.</span>
      </div>
    @endif

    @if($modulo === 'fiscal')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Avisos y cálculo de los modelos</span>
        <span class="hint muted">Para todas las empresas. Los datos de cada empresa (régimen de IVA, retenciones, Impuesto sobre Sociedades…) se configuran en Fiscal › «Parámetros» con la empresa elegida.</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr));gap:12px">
          <label class="lbl-s">Avisar de un plazo con (días de antelación)<input class="inp mono" type="number" min="0" max="60" name="aviso_dias" value="{{ old('aviso_dias', $p['aviso_dias']) }}"><span class="hint muted">El modelo se marca «Plazo cerca» en la lista.</span></label>
          <label class="lbl-s">Umbral del modelo 347 (€)<input class="inp mono" name="umbral_347" value="{{ old('umbral_347', Formato::num($p['umbral_347'])) }}"><span class="hint muted">Art. 33 RGAT: 3.005,06 € anuales por tercero.</span></label>
          <label class="lbl-s">Pago fraccionado del 130 (%)<input class="inp mono" type="number" min="1" max="100" name="pct_130" value="{{ old('pct_130', $p['pct_130']) }}"><span class="hint muted">Art. 110 RIRPF: 20 % del rendimiento neto.</span></label>
          <label class="lbl-s">202 · artículo 40.2 LIS (%)<input class="inp mono" type="number" min="1" max="100" name="pct_202_402" value="{{ old('pct_202_402', $p['pct_202_402']) }}"><span class="hint muted">18 % de la cuota del último IS.</span></label>
          <label class="lbl-s">202 · artículo 40.3 LIS (%)<input class="inp mono" type="number" min="1" max="100" name="pct_202_403" value="{{ old('pct_202_403', $p['pct_202_403']) }}"><span class="hint muted">5/7 del tipo de gravamen redondeado por defecto (17 % con el tipo del 25 %). Con cifra de negocios ≥ 10 M€, 24 %.</span></label>
        </div>
      </div>
    @endif

    @if($modulo === 'calendario')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Qué se muestra en el calendario</span>
        <label class="lbl-s" style="max-width:260px">Vista al entrar<select class="sel" name="vista"><option value="mes" @selected($p['vista'] === 'mes')>Mes</option><option value="agenda" @selected($p['vista'] === 'agenda')>Agenda</option></select></label>
        @foreach(['notificaciones' => 'Vencimientos y caducidades de notificaciones', 'expedientes' => 'Fechas límite de expedientes', 'tareas' => 'Tareas con fecha', 'fiscal' => 'Plazos de los modelos fiscales de la empresa elegida', 'festivos' => 'Festivos (los mismos que usa el cálculo de plazos)'] as $k => $l)
          <label class="chk" style="gap:10px"><input type="checkbox" name="{{ $k }}" value="1" @checked($p[$k]) style="width:18px;height:18px;accent-color:#0B0B0B"><span style="font-size:13.5px">{{ $l }}</span></label>
        @endforeach
      </div>
    @endif

    @if($modulo === 'tareas')
      <div class="card" style="padding:18px 20px;gap:12px">
        <span style="font-size:16px;font-weight:700">Jornada y tareas del despacho</span>
        <div class="fgrid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
          <label class="lbl-s">Jornada semanal (horas)<input class="inp mono" type="number" step="0.5" min="1" max="60" name="jornada_semanal" value="{{ $p['jornada_semanal'] }}"></label>
          <label class="lbl-s">Jornada diaria (horas)<input class="inp mono" type="number" step="0.5" min="1" max="12" name="jornada_diaria" value="{{ $p['jornada_diaria'] }}"></label>
          <label class="lbl-s">Vacaciones al año (días hábiles)<input class="inp mono" type="number" min="0" max="60" name="vacaciones" value="{{ $p['vacaciones'] }}"><span class="hint muted">Mínimo legal: 30 días naturales (art. 38 ET). Revisa tu convenio.</span></label>
          <label class="lbl-s">Prioridad por defecto<select class="sel" name="prioridad">@foreach(array_keys(\App\Models\Tarea::PRIORIDADES) as $x)<option @selected($p['prioridad'] === $x)>{{ $x }}</option>@endforeach</select></label>
        </div>
      </div>
    @endif

    </fieldset>
    @if($puede)<div style="display:flex;justify-content:flex-end"><button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button></div>@endif
  </form>

  @if($modulo === 'clientes')
    @php $cert = app(\App\Services\VerificacionNif::class)->certificado(); @endphp
    <div class="card" style="padding:18px 20px;gap:12px" id="aeat">
      <span style="font-size:16px;font-weight:700">Verificación del NIF en la AEAT</span>
      <span class="hint muted">Al guardar un cliente se consulta el censo de la AEAT (servicio «Verificación de NIF»): si no identifica el NIF con su nombre o razón social, o figura de baja o revocado, no deja guardar. Para consultar hace falta un certificado electrónico; se guarda cifrado y solo lo usa este servicio de consulta (no presenta nada).</span>
      @if($errors->has('aeat'))<div class="ferr">{{ $errors->first('aeat') }}</div>@endif
      @if($cert)
        <div class="boxsoft" style="display:flex;flex-direction:column;gap:4px">
          <b style="font-size:14px">{{ $cert['titular'] }}{{ $cert['nif'] ? ' · '.$cert['nif'] : '' }}</b>
          <span style="font-size:12.5px;color:#3C3C3C">Emisor: {{ $cert['emisor'] ?: '—' }} · Caduca: {{ $cert['caduca'] ? \App\Support\Formato::fecha($cert['caduca']) : '—' }} · {{ $cert['activo'] ? 'Verificación activada' : 'Verificación desactivada' }}</span>
        </div>
        @if($puede)
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
          <form method="POST" action="{{ route('admin.aeat.activar') }}">@csrf<input type="hidden" name="activo" value="{{ $cert['activo'] ? 0 : 1 }}"><button class="btn soft sm" type="submit">{{ $cert['activo'] ? 'Desactivar la verificación' : 'Activar la verificación' }}</button></form>
          <form method="POST" action="{{ route('admin.aeat.probar') }}" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">@csrf
            <input class="inp mono" name="nif" placeholder="NIF (vacío: el del despacho)" style="width:200px;height:34px" maxlength="15">
            <input class="inp" name="nombre" placeholder="Nombre o razón social" style="width:220px;height:34px" maxlength="120">
            <button class="btn sm" type="submit">Probar conexión</button>
          </form>
          <form method="POST" action="{{ route('admin.aeat.quitar') }}" data-confirm="¿Eliminar el certificado? Dejará de verificarse el NIF en la AEAT.">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">Eliminar certificado</button></form>
        </div>
        @endif
      @endif
      @if($puede)
        <form method="POST" action="{{ route('admin.aeat.guardar') }}" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">@csrf
          <label class="lbl-s" style="flex:1 1 240px">{{ $cert ? 'Sustituir el certificado' : 'Certificado del despacho (.p12 o .pfx)' }}<input class="inp" type="file" name="certificado" accept=".p12,.pfx,application/x-pkcs12" style="padding-top:8px" required></label>
          <label class="lbl-s" style="flex:1 1 200px">Contraseña del certificado<input class="inp" type="password" name="pass" autocomplete="off" required></label>
          <button class="btn lime" type="submit">Guardar certificado</button>
        </form>
      @else
        <span class="hint muted">Solo la administración del despacho puede configurar el certificado.</span>
      @endif
    </div>
  @endif
</div>
@endsection
