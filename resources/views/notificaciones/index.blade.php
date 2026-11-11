@extends('layouts.app')
@php
    use App\Support\Formato;
    use App\Support\Plazos;
    $orgs = config('agcloud.organismos');
    $tiposN = \App\Support\Parametros::get('notificaciones')['tipos'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Comunicación · Notificaciones</span><h1 class="ttl">Notificaciones</h1></div>
    <div class="row" style="min-width:0;max-width:100%">
      <a class="btn white lg" href="{{ route('parametros', 'notificaciones') }}">Avisos</a>
      <a class="btn lg" href="{{ $url(['nueva' => 1]) }}" style="background:#0B0B0B;color:#fff"><span style="width:8px;height:8px;border-radius:50%;background:#C8F031"></span>Registrar notificación</a>
      <div style="display:flex;align-items:center;gap:10px;padding:6px 8px 6px 6px;border-radius:22px;background:#fff;min-width:0;max-width:100%">
        <span style="width:32px;height:32px;flex:none;border-radius:50%;background:#0B0B0B;color:#C8F031;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">{{ $c->iniciales() }}</span>
        <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell" style="font-size:13.5px;font-weight:700">{{ $c->nombre }}</span><span class="ell" style="font:500 11.5px var(--mono);color:#6A6A65">{{ $desp ? 'Todos los clientes del despacho' : ($c->nif.' · '.$c->tipo) }}</span></div>
        <a href="{{ route('escritorio', ['cambiar' => 1, 'volver' => '/notificaciones']) }}" style="margin-left:6px;padding:6px 12px;border-radius:14px;background:#F4F4F0;font-size:12.5px;font-weight:600;white-space:nowrap;text-decoration:none">Cambiar empresa</a>
      </div>
    </div>
  </div>

  @include('partials.tabs-modulo', ['modulo' => 'notificaciones', 'actual' => 'modulo'])
  @if($errors->any() && ! $nuevo)<div class="ferr">{{ $errors->first() }}</div>@endif

  @if($avOpen)
  <form method="POST" action="{{ route('notificaciones.avisos') }}" class="card" style="padding:18px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:16px">
    @csrf
    <div style="display:flex;flex-direction:column;gap:6px"><label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" data-avisos-nav style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Avisos en este navegador<span style="font-size:12px;font-weight:400;color:#6A6A65">Al entrar en AG Cloud, si hay notificaciones a punto de caducar.</span></span></label><span style="font-size:12px;color:#6A6A65;padding-left:28px" data-avisos-perm></span></div>
    <label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" name="email" value="1" @checked($avisos['email']) style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Resumen por correo al asesor<span style="font-size:12px;font-weight:400;color:#6A6A65">Cada mañana, con las notificaciones sin abrir de sus clientes.</span></span></label>
    <label class="chk" style="align-items:flex-start;gap:10px"><input type="checkbox" name="wa" value="1" @checked($avisos['wa']) style="width:18px;height:18px;margin:1px 0 0;accent-color:#0B0B0B;flex:none"><span style="display:flex;flex-direction:column;gap:2px;font-size:13.5px;font-weight:600">Avisar al cliente por WhatsApp<span style="font-size:12px;font-weight:400;color:#6A6A65">Propone el mensaje al abrir una notificación con plazo.</span></span></label>
    <label class="lbl-s" style="font-size:13.5px">Avisar antes de que caduque<select class="sel" name="dias">@foreach([1, 3, 5] as $d)<option value="{{ $d }}" @selected($avisos['dias'] === $d)>{{ $d }} {{ $d === 1 ? 'día' : 'días' }} antes</option>@endforeach</select></label>
    <div style="grid-column:1 / -1;display:flex;justify-content:flex-end"><button class="btn lime" type="submit">Guardar avisos</button></div>
  </form>
  @endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px">
    @foreach($kpis as [$l, $v, $s])<div class="kpi2"><span>{{ $l }}</span><b>{{ $v }}</b><small>{{ $s }}</small></div>@endforeach
  </div>

  <div class="ntbar">
    <span style="font-size:13px;font-weight:600">Registro manual · revisa las sedes con tu certificado y anota aquí lo que llegue</span>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      @foreach([['DEHú', 'Dirección Electrónica Habilitada única', 'https://dehu.redsara.es/'], ['AEAT', 'Sede electrónica de la AEAT', config('agcloud.organismos_url.AEAT')], ['TGSS', 'Sede electrónica de la TGSS', config('agcloud.organismos_url.TGSS')]] as [$o, $l, $href])
        <a class="cn" href="{{ $href }}" target="_blank" rel="noopener noreferrer" title="{{ $l }}"><i></i><b>{{ $o }}</b><span>Abrir sede ↗</span></a>
      @endforeach
    </div>
  </div>

  <form method="GET" action="{{ route('notificaciones.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px" data-autoform>
    <div class="chipbar">
      @foreach($chips as $ch)<a class="{{ $ch['on'] ? 'on' : '' }}" href="{{ $url(['chip' => $ch['i'] ?: null]) }}">{{ $ch['l'] }} · {{ $ch['n'] }}</a>@endforeach
    </div>
    @if($chip)<input type="hidden" name="chip" value="{{ $chip }}">@endif
    <select class="sel" name="org" style="width:auto;height:40px" data-change-submit>
      <option value="Todos">Todos los organismos</option>
      @foreach($orgs as $k => $l)<option value="{{ $k }}" @selected($org === $k)>{{ $k }} · {{ $l }}</option>@endforeach
    </select>
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por asunto, cliente o referencia" style="flex:1 1 220px;height:40px" data-autosubmit>
  </form>

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div class="ntlist">
      @forelse($lista as $n)
        @php [$st, $bg, $fg] = $n->estadoChip(); @endphp
        <a class="ntrow {{ $sel && $sel->id === $n->id ? 'on' : '' }}" href="{{ $url(['sel' => $n->id]) }}">
          <span class="dt" style="background:{{ $n->pendiente() ? '#0B0B0B' : 'transparent' }}"></span>
          <span class="org">{{ $n->organismo }}</span>
          <div class="tx"><b style="font-weight:{{ $n->pendiente() ? 700 : 500 }}">{{ $n->tipo }}</b><span>{{ $n->asunto ?: '—' }}</span><small>@if($desp)<strong>{{ $n->cliente?->nombre }}</strong> · @endif Puesta a disposición {{ Formato::fecha($n->puesta) }}</small></div>
          <span class="stpill" style="background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span>
        </a>
      @empty
        <span style="padding:28px 20px;font-size:14px;color:#6A6A65">No hay notificaciones en esta vista.@if($chip === 0 && $q === '' && $org === 'Todos') Cuando llegue una a DEHú o a la sede, pulsa «Registrar notificación».@endif</span>
      @endforelse
    </div>

    @if($sel)
      @php
        [$st, $bg, $fg] = $sel->estadoChip();
        $cli = $sel->cliente;
        $hoy = Plazos::hoy();
        $prev = Plazos::vencimiento($hoy, (int) $sel->plazo_n, (string) $sel->plazo_tipo);
        $wa = $cli?->whatsapp();
      @endphp
      <div class="ntdet">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0"><span style="font-size:12.5px;color:#6A6A65">{{ $sel->organismoTexto() }} · {{ $sel->canal }}</span><span style="font-size:19px;font-weight:700;letter-spacing:-.01em">{{ $sel->tipo }}</span><span style="font-size:13.5px;color:#3C3C3C">{{ $sel->asunto }}</span></div>
          <a href="{{ $url() }}" style="font-size:22px;line-height:1;color:#6A6A65;text-decoration:none" aria-label="Cerrar">×</a>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span class="stpill" style="background:{{ $bg }};color:{{ $fg }};font-size:12.5px">{{ $st }}</span><span style="font:500 12px var(--mono);color:#6A6A65">{{ $sel->referencia }}</span></div>
        <div class="ntgrid">
          <div><span>Titular</span><span>{{ $cli?->nombre ?? '—' }}</span></div>
          <div><span>NIF</span><span class="mono">{{ $cli?->nif }}</span></div>
          <div><span>Puesta a disposición</span><span>{{ Formato::fecha($sel->puesta) }}</span></div>
          <div><span>Caduca</span><span>{{ Formato::fecha($sel->caducidad()) }}</span></div>
          <div><span>Abierta</span><span>{{ $sel->acceso ? Formato::fecha($sel->acceso) : '—' }}</span></div>
          <div><span>Plazo</span><span>{{ $sel->plazoTexto() }}</span></div>
          <div><span>Vence</span><span>{{ $sel->vence ? Formato::fecha($sel->vence) : '—' }}</span></div>
          <div><span>Actuación</span><span>{{ $sel->accion ?: '—' }}</span></div>
        </div>

        @if($sel->pendiente())
          <span class="note-amber">Si nadie la abre antes del {{ Formato::fecha($sel->caducidad()) }} se da por notificada y el plazo empieza igualmente (art. 43.2 Ley 39/2015).</span>
          @if($ask === 'acc')
            <form method="POST" action="{{ route('notificaciones.abrir', $sel) }}" class="askbox">
              @csrf
              <span style="font-weight:600">{{ $sel->plazo_n ? 'Al abrirla empieza el plazo de '.$sel->plazoTexto().': si la abres hoy, vencería el '.Formato::fecha($prev).'.' : 'Esta notificación no abre ningún plazo.' }}</span>
              <label class="lbl-s">Fecha en que se abrió en la sede<input class="inp" type="date" name="fecha" value="{{ old('fecha', $hoy->toDateString()) }}" min="{{ $sel->puesta->toDateString() }}" max="{{ ($hoy->lt($sel->caducidad()) ? $hoy : $sel->caducidad())->toDateString() }}" required></label>
              <div style="display:flex;gap:8px"><button class="pillbtn lime" type="submit">Marcar como abierta</button><a class="pillbtn" href="{{ $url(['sel' => $sel->id]) }}">Cancelar</a></div>
            </form>
          @elseif($ask === 'rej')
            <form method="POST" action="{{ route('notificaciones.rechazar', $sel) }}" class="askbox red">
              @csrf
              <span>Rechazarla no la anula: se considera notificada en la fecha del rechazo y el procedimiento continúa.</span>
              <label class="lbl-s" style="color:#8A1C1C">Fecha del rechazo<input class="inp" type="date" name="fecha" value="{{ old('fecha', $hoy->toDateString()) }}" min="{{ $sel->puesta->toDateString() }}" max="{{ ($hoy->lt($sel->caducidad()) ? $hoy : $sel->caducidad())->toDateString() }}" required></label>
              <div style="display:flex;gap:8px"><button class="pillbtn red" type="submit">Rechazar</button><a class="pillbtn wh" href="{{ $url(['sel' => $sel->id]) }}">Cancelar</a></div>
            </form>
          @endif
          <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="pillbtn ink f1" href="{{ $url(['sel' => $sel->id, 'ask' => 'acc']) }}">Abrir y aceptar</a><a class="pillbtn" href="{{ $url(['sel' => $sel->id, 'ask' => 'rej']) }}">Rechazar</a></div>
        @else
          @if($sel->caducada)<span class="note-grey">Pasaron 10 días naturales sin abrirla: se considera notificada el {{ Formato::fecha($sel->acceso) }} y el plazo corre desde esa fecha.</span>@endif
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if($sel->documento_id)
              <a class="pillbtn ink" href="{{ route('documentos.ver', $sel->documento_id) }}" target="_blank" rel="noopener">Descargar justificante</a>
            @endif
            @if($sel->vence)<a class="pillbtn" href="{{ route('notificaciones.ics', $sel) }}">Añadir vencimiento al calendario</a>@endif
          </div>
        @endif

        @unless($sel->documento_id)
          <form method="POST" action="{{ route('notificaciones.adjuntar', $sel) }}" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            @csrf
            <label class="pillbtn" style="cursor:pointer">Adjuntar justificante (PDF)<input type="file" name="archivo" accept="application/pdf" class="hidden" data-autoupload></label>
            <span style="font-size:12px;color:#6A6A65">Se guarda cifrado en Documentos › Fiscal › Requerimientos y notificaciones.</span>
          </form>
        @endunless

        <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:12px;border-top:1px solid #F1F1EC">
          <a class="pillbtn" href="{{ $sel->urlSede() }}" target="_blank" rel="noopener noreferrer">Ver en la sede ↗</a>
          @if($sel->expediente_id)
            <a class="pillbtn" href="{{ route('expedientes.show', $sel->expediente_id) }}">Ver expediente</a>
          @else
            <a class="pillbtn" href="{{ route('expedientes.index', ['nuevo' => 1, 'cli' => $sel->cliente_id, 'notif' => $sel->id]) }}">Crear expediente</a>
          @endif
          @if($wa)
            <a class="pillbtn" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($sel->textoWhatsapp()) }}" target="_blank" rel="noopener noreferrer">Avisar al cliente</a>
          @else
            <span class="pillbtn" style="opacity:.5;cursor:not-allowed" title="El cliente no tiene teléfono en su ficha">Avisar al cliente</span>
          @endif
          <form method="POST" action="{{ route('notificaciones.archivar', $sel) }}">@csrf<button class="pillbtn" type="submit">{{ $sel->archivada ? 'Desarchivar' : 'Archivar' }}</button></form>
        </div>

        <form method="POST" action="{{ route('notificaciones.actualizar', $sel) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,160px),1fr));gap:12px">
          @csrf
          <label class="lbl-s">Responsable<select class="sel" name="responsable_id" data-change-submit><option value="">—</option>@foreach($equipo as $e)<option value="{{ $e->id }}" @selected($sel->responsable_id === $e->id)>{{ $e->name }}</option>@endforeach</select></label>
          <label class="lbl-s" style="grid-column:1 / -1">Nota interna<textarea class="txa" name="nota" rows="2" maxlength="2000" placeholder="Qué hay que hacer, documentos pedidos al cliente…">{{ $sel->nota }}</textarea></label>
          <div style="grid-column:1 / -1;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <button class="btn sm" type="submit">Guardar nota</button>
          </div>
        </form>
        @if(auth()->user()->esAdmin())
          <form method="POST" action="{{ route('notificaciones.destroy', $sel) }}" data-confirm="¿Eliminar este registro? Úsalo solo si se anotó por error. Queda constancia en el registro de accesos.">
            @csrf @method('DELETE')
            <button class="lnk red" type="submit" style="font-size:12.5px">Eliminar registro (anotado por error)</button>
          </form>
        @endif
      </div>
    @endif
  </div>
</div>
@endsection

@section('overlay')
@if($nuevo)
@php $hoy = Plazos::hoy()->toDateString(); @endphp
<div class="drawer sm" role="dialog" aria-modal="true" aria-label="Registrar notificación">
  <a class="shade" href="{{ $url() }}" aria-label="Cerrar"></a>
  <div class="panel">
    <div class="ph"><div><b>Registrar notificación</b><small>Anota lo que ha llegado a DEHú o a la sede del organismo</small></div><a class="x" href="{{ $url() }}" aria-label="Cerrar">×</a></div>
    <form method="POST" action="{{ route('notificaciones.store') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;flex:1;min-height:0" data-notif-form data-tipos='@json($tiposN)' data-festivos-hoy="{{ $hoy }}">
      @csrf
      <div class="pc" style="overflow-y:auto;gap:14px">
        @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif
        <label class="lbl-s">Cliente *
          <select class="sel" name="cliente_id" required>
            @if($desp)<option value="">Selecciona un cliente</option>@endif
            @foreach($clientes as $cl)<option value="{{ $cl->id }}" @selected((string) old('cliente_id') === (string) $cl->id)>{{ $cl->nombre }}{{ $cl->nif ? ' · '.$cl->nif : '' }}</option>@endforeach
          </select>
        </label>
        <label class="lbl-s">Tipo de notificación
          <select class="sel" name="plantilla" data-plantilla>
            @foreach($tiposN as $i => $t)<option value="{{ $i }}" @selected((string) old('plantilla', '0') === (string) $i)>{{ $t[0] }} · {{ $t[2] }}</option>@endforeach
            <option value="otro" @selected(old('plantilla') === 'otro')>Otra (escribir a mano)</option>
          </select>
        </label>
        <div class="fgrid" style="grid-template-columns:1fr 1fr;gap:12px">
          <label class="lbl-s">Organismo<select class="sel" name="organismo" data-f="0">@foreach($orgs as $k => $l)<option value="{{ $k }}" @selected(old('organismo', 'AEAT') === $k)>{{ $k }} · {{ $l }}</option>@endforeach</select></label>
          <label class="lbl-s">Canal<select class="sel" name="canal" data-f="1">@foreach(config('agcloud.canales') as $k)<option @selected(old('canal', 'Sede AEAT') === $k)>{{ $k }}</option>@endforeach</select></label>
        </div>
        <label class="lbl-s">Tipo<input class="inp" name="tipo" value="{{ old('tipo', $tiposN[0][2] ?? '') }}" maxlength="160" required data-f="2"></label>
        <label class="lbl-s">Asunto<input class="inp" name="asunto" value="{{ old('asunto') }}" maxlength="255" placeholder="Ej.: IVA 2T 2026 · diferencias en el modelo 303"></label>
        <div class="fgrid" style="grid-template-columns:1fr 1fr;gap:12px">
          <label class="lbl-s">Puesta a disposición *<input class="inp" type="date" name="puesta" value="{{ old('puesta', $hoy) }}" max="{{ $hoy }}" required data-puesta></label>
          <label class="lbl-s">Referencia / CSV<input class="inp mono" name="referencia" value="{{ old('referencia') }}" maxlength="80"></label>
        </div>
        <div class="fgrid" style="grid-template-columns:90px 1fr 1fr;gap:12px">
          <label class="lbl-s">Plazo<input class="inp" type="number" name="plazo_n" min="0" max="365" value="{{ old('plazo_n', $tiposN[0][3] ?? 0) }}" data-f="3" required></label>
          <label class="lbl-s">Días<select class="sel" name="plazo_tipo" data-f="4">@foreach(['h' => 'hábiles', 'n' => 'naturales', 'm' => 'meses'] as $k => $l)<option value="{{ $k }}" @selected(old('plazo_tipo', $tiposN[0][4] ?? 'h') === $k)>{{ $l }}</option>@endforeach</select></label>
          <label class="lbl-s">Actuación<input class="inp" name="accion" value="{{ old('accion', $tiposN[0][5] ?? '') }}" maxlength="120" data-f="5"></label>
        </div>
        <span class="hint muted" style="margin-top:-6px">Revisa el plazo con el que indique la propia notificación: manda lo que diga el documento.</span>
        <label class="chk" style="gap:10px"><input type="checkbox" name="abierta" value="1" @checked(old('abierta')) data-abierta style="width:18px;height:18px;accent-color:#0B0B0B"><span style="font-size:13.5px;font-weight:600">Ya la hemos abierto en la sede</span></label>
        <label class="lbl-s {{ old('abierta') ? '' : 'hidden' }}" data-acceso>Fecha de apertura<input class="inp" type="date" name="acceso" value="{{ old('acceso', $hoy) }}" max="{{ $hoy }}"></label>
        <div class="fgrid" style="grid-template-columns:1fr;gap:12px">
          <label class="lbl-s">Responsable<select class="sel" name="responsable_id"><option value="">El asesor del cliente</option>@foreach($equipo as $e)<option value="{{ $e->id }}" @selected((string) old('responsable_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select></label>
          <label class="lbl-s">Nota interna<textarea class="txa" name="nota" rows="2" maxlength="2000" placeholder="Qué hay que hacer, documentos pedidos al cliente…">{{ old('nota') }}</textarea></label>
          <label class="lbl-s">Justificante o notificación (PDF, opcional)<input class="inp" type="file" name="archivo" accept="application/pdf" style="padding-top:8px"></label>
        </div>
      </div>
      <div class="pf">
        <a class="btn soft" href="{{ $url() }}">Cancelar</a>
        <button class="btn lime" type="submit">Registrar</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection
