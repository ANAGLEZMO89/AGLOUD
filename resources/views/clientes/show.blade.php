@extends('layouts.app')
@php
    use App\Support\Formato;
    use App\Support\SeccionesAlta;
    [$ebg, $efg] = $c->estadoColores();
    $act = collect($c->seccion('acts'))->first(fn ($a) => ! empty($a['principal']) && empty($a['fFin'])) ?? ($c->seccion('acts')[0] ?? null);
    $per = ['Mensual' => 'al mes', 'Trimestral' => 'al trimestre', 'Semestral' => 'al semestre', 'Anual' => 'al año'];
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="crumbs"><a href="{{ route('clientes.index') }}">Clientes</a><span>/</span><b>{{ $c->nombre }}</b></div>
  <div class="fhead">
    <span class="av">{{ $c->iniciales() }}</span>
    <div style="flex:1;min-width:240px;display:flex;flex-direction:column;gap:6px">
      <h1>{{ $c->nombre }}</h1>
      <div class="row" style="gap:8px;font-size:13px;color:#3C3C3C">
        <span class="mono" style="font-size:12.5px;font-weight:500">{{ $c->nif ?: 'Pendiente' }}</span><span class="dot">·</span><span>{{ $c->es_despacho ? 'Despacho propio' : $c->tipo }}</span>
        @if($c->actividadPrincipal())<span class="dot">·</span><span>{{ $c->actividadPrincipal() }}</span>@endif
        <span class="pill" style="background:{{ $ebg }};color:{{ $efg }}">{{ $c->estado }}</span>
      </div>
    </div>
    <div class="row">
      <a class="btn soft" href="{{ route('clientes.edit', $c) }}">Editar ficha</a>
      @if($c->telefono)<a class="btn lime" href="https://wa.me/{{ $c->whatsapp() }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>@endif
      <form method="POST" action="{{ route('escritorio.seleccionar', $c) }}">@csrf<input type="hidden" name="volver" value="{{ route('documentos.index', [], false) }}"><button class="btn soft" type="submit">Subir documento</button></form>
      @unless($c->es_despacho)<a class="btn" href="{{ route('expedientes.index', ['nuevo' => 1, 'cli' => $c->id]) }}" style="background:#0B0B0B;color:#fff"><span style="color:#C8F031">+</span>Nuevo expediente</a>@endunless
    </div>
  </div>

  <div class="tabs">
    @foreach($tabs as [$id, $l, $num])
      <a class="{{ $tab === $id ? 'on' : '' }}" href="{{ route('clientes.show', [$c, 'tab' => $id]) }}">{{ $l }}@if($num)<small>{{ $num }}</small>@endif</a>
    @endforeach
  </div>

  {{-- RESUMEN --}}
  @if($tab === 'resumen')
  <div class="g2">
    <div class="col">
      <div class="card">
        <span class="ct">Datos principales</span>
        <div class="dprin">
          @foreach([['NIF', $c->nif ?: 'Pendiente'], ['Tipo', $c->tipo], ['Email', $c->email ?: 'Sin indicar'], ['Teléfono', $c->telefono ?: 'Sin indicar'], ['Domicilio fiscal', trim(implode(', ', array_filter([$c->domicilio, $c->cp, $c->municipio]))) ?: 'Sin indicar'], ['Provincia', $c->provincia ?: '—'], ['Asesor', $c->asesor?->name ?? 'Sin asignar'], ['Cliente desde', Formato::fecha($c->fecha_alta) ?: '—']] as [$l, $v])
            <div><span>{{ $l }}</span><b style="overflow-wrap:anywhere">{{ $v }}</b></div>
          @endforeach
        </div>
      </div>
      <div class="card" style="gap:6px">
        <div class="card-h" style="padding-bottom:6px"><span class="ct">Próximas obligaciones</span></div>
        <div class="emptybox" style="text-align:left">Los modelos y plazos se calcularán automáticamente cuando activemos el módulo Fiscal.</div>
      </div>
    </div>
    <div class="col">
      <div class="card limecard" style="gap:10px">
        <div class="card-h"><span class="ct">Documentos por revisar</span><span style="font:600 22px var(--mono)">{{ $porRevisar->count() }}</span></div>
        @forelse($porRevisar as $d)
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-top:1px solid rgba(11,11,11,.22);font-size:13.5px">
            <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell">{{ $d->nombre }}</span><span style="font:500 11.5px var(--mono)">{{ Formato::fecha($d->created_at) }} · subido desde el portal</span></div>
          </div>
        @empty
          <span style="font-size:13.5px;padding-top:4px;border-top:1px solid rgba(11,11,11,.22)">No hay documentos del cliente pendientes de revisar.</span>
        @endforelse
        @if($porRevisar->count())
          <form method="POST" action="{{ route('escritorio.seleccionar', $c) }}">@csrf<input type="hidden" name="volver" value="{{ route('documentos.index', ['orig' => 1], false) }}"><button class="btn sm" type="submit" style="margin-top:4px">Revisar en Documentos</button></form>
        @endif
      </div>
      <div class="card dark" style="gap:10px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">PLAN CONTRATADO</span>
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px"><span style="font-size:18px;font-weight:700">{{ $c->cuota_importe ? 'Cuota '.mb_strtolower($c->cuota_periodicidad) : 'Sin cuota definida' }}</span><span style="font:600 24px var(--mono)">{{ $c->cuotaTexto() }}</span></div>
        <span style="font-size:13px;color:#BDBDB7;line-height:1.45">{{ $c->cuota_importe ? trim(($c->cuota_concepto ?: 'Honorarios de asesoría').' · '.Formato::eur($c->cuotaConIva()).' con IVA '.($per[$c->cuota_periodicidad] ?? 'por trámite').'. '.($c->cuota_observaciones ?? '')) : 'Fija el importe en Editar ficha › Cuota.' }}</span>
      </div>
      <div class="card" style="gap:4px">
        <span class="ct" style="padding-bottom:6px">Actividad reciente</span>
        @forelse($actividad as $a)
          <div class="tl"><i></i><div><span style="font-size:13.5px">{{ $a->descripcion }}</span><small>{{ Formato::fechaHora($a->created_at) }} · {{ $a->user?->name ?? $a->user_email ?? 'Sistema' }}</small></div></div>
        @empty
          <span style="font-size:13.5px;color:#6A6A65">Sin actividad todavía.</span>
        @endforelse
      </div>
    </div>
  </div>
  @endif

  {{-- DATOS FISCALES --}}
  @if($tab === 'datos')
  @php
    $ccc = collect($c->seccion('centros'))->pluck('ccc')->merge(collect($c->seccion('acts'))->pluck('ccc'))->filter()->unique()->implode(' · ');
    $secciones = [
      ['Identificación fiscal', [['NIF', $c->nif ?: 'Pendiente'], [$c->tipo === 'Sociedad' || $c->tipo === 'Comunidad de bienes' ? 'Razón social' : 'Nombre y apellidos', $c->nombre], ['Nombre comercial', $c->comercial ?: '—'], ['Domicilio fiscal', trim(implode(', ', array_filter([$c->domicilio, $c->cp, $c->municipio, $c->provincia]))) ?: 'Sin indicar']]],
      ['IVA', [['Régimen', $act['iva'] ?? 'Sin actividad'], ['Recargo de equivalencia', ! empty($act['recEq']) ? 'Sí' : 'No'], ['Criterio de caja', ! empty($act['caja']) ? 'Sí' : 'No']]],
      ['Impuesto sobre la renta', [['Régimen', $act['irpf'] ?? 'Sin actividad'], ['CNAE 2025', $act['cnae'] ?? '—'], ['IAE', $act['iae'] ?? '—']]],
      ['Seguridad Social', [['Cuentas de cotización', $ccc ?: 'Sin CCC'], ['Centros de trabajo', (string) count($c->seccion('centros'))], ['Trabajadores declarados', (string) collect($c->seccion('centros'))->sum(fn ($x) => (int) ($x['trab'] ?? 0))]]],
    ];
  @endphp
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px">
    @foreach($secciones as [$t, $f])
      <div class="card" style="gap:8px">
        <div class="card-h" style="padding-bottom:4px"><span class="ct">{{ $t }}</span><a class="lnk" href="{{ route('clientes.edit', [$c, 'sec' => $t === 'Identificación fiscal' ? 'general' : ($t === 'Seguridad Social' ? 'centros' : 'acts')]) }}">Editar</a></div>
        @foreach($f as [$l, $v])<div class="kv"><span>{{ $l }}</span><span>{{ $v }}</span></div>@endforeach
      </div>
    @endforeach
  </div>
  @endif

  {{-- OBLIGACIONES / TRABAJADORES / EXPEDIENTES (próximas fases) --}}
  @if($tab === 'exp')
  <div class="card pad16" style="gap:6px">
    @forelse($expedientes as $x)
      @php [$xbg, $xfg] = $x->colores(); @endphp
      <a href="{{ route('expedientes.show', $x) }}" style="display:grid;grid-template-columns:130px minmax(0,1fr) 140px auto;gap:14px;align-items:center;padding:12px 6px;border-bottom:1px solid #F1F1EC;font-size:13.5px;border-radius:8px;text-decoration:none;color:var(--ink)"><span style="font:500 12px var(--mono);color:#6A6A65">{{ $x->ref }}</span><span style="font-weight:600">{{ $x->tramite }}</span><span class="ell" style="color:#3C3C3C">{{ $x->responsable?->name ?? '—' }}</span><span style="justify-self:start;font-size:12px;font-weight:600;padding:3px 9px;border-radius:20px;background:{{ $xbg }};color:{{ $xfg }}">{{ $x->estado }}</span></a>
    @empty
      <div style="padding:36px 10px;display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center"><span style="font-size:15px;font-weight:600">Sin expedientes abiertos</span>@unless($c->es_despacho)<a class="btn sm" href="{{ route('expedientes.index', ['nuevo' => 1, 'cli' => $c->id]) }}" style="background:#0B0B0B;color:#fff">Abrir expediente</a>@endunless</div>
    @endforelse
  </div>
  @endif

  @if(in_array($tab, ['obl', 'trab'], true))
  <div class="card">
    <div class="empty">
      <b>{{ ['obl' => 'Obligaciones fiscales', 'trab' => 'Trabajadores'][$tab] }}: módulo en preparación</b>
      <span>{{ ['obl' => 'Se calcularán desde la contabilidad y los parámetros fiscales en la fase Fiscal.', 'trab' => 'Cuando contrate, el alta en Seguridad Social se gestionará desde aquí (fase Laboral).'][$tab] }}</span>
    </div>
  </div>
  @endif

  {{-- DOCUMENTOS --}}
  @if($tab === 'docs')
  <div class="card pad16" style="gap:6px;overflow-x:auto">
    <div class="th" style="min-width:760px;grid-template-columns:minmax(0,2.2fr) minmax(0,1fr) 110px 120px;padding:8px 0"><span>Archivo</span><span>Tipo</span><span>Origen</span><span>Fecha</span></div>
    @forelse($docs as $d)
      <a class="tr" style="min-width:760px;grid-template-columns:minmax(0,2.2fr) minmax(0,1fr) 110px 120px;padding:11px 0;border-radius:0" href="{{ route('documentos.descargar', $d) }}">
        <div style="display:flex;align-items:center;gap:10px;min-width:0"><span style="flex:none;font:600 10px var(--mono);padding:4px 6px;border-radius:5px;background:#0B0B0B;color:#C8F031">{{ strtoupper(pathinfo($d->nombre, PATHINFO_EXTENSION) ?: 'DOC') }}</span><span class="ell" style="font:500 12.5px var(--mono)">{{ $d->nombre }}</span></div>
        <span class="c3">{{ $d->tipoTexto() }}</span><span class="c3">{{ $d->origen === 'Portal del cliente' ? 'Cliente' : 'Despacho' }}</span><span class="mono c3" style="font-size:12.5px">{{ Formato::fecha($d->fecha) }}</span>
      </a>
    @empty
      <div class="empty"><b>Sin documentos</b><span>Sube el primero desde Documentos.</span></div>
    @endforelse
  </div>
  @endif

  {{-- DATOS DE ALTA --}}
  @if($tab === 'alta')
  <div style="display:flex;flex-direction:column;gap:16px">
    @php
      $gen = [['Tipo', $c->tipo], ['Nombre o razón social', $c->nombre], ['NIF', $c->nif ?: 'Pendiente'], ['Nombre comercial', $c->comercial ?: '—'], ['Fecha de alta', Formato::fecha($c->fecha_alta) ?: '—'], ['Email', $c->email ?: '—'], ['Teléfono', $c->telefono ?: '—'], ['Asesor', $c->asesor?->name ?? '—'], ['Domicilio fiscal', trim(implode(', ', array_filter([$c->domicilio, $c->cp, $c->municipio, $c->provincia]))) ?: '—'], ['Estado', $c->estado]];
      $cuo = [['Importe sin IVA', $c->cuota_importe ? Formato::eur($c->cuota_importe) : 'Sin definir'], ['Periodicidad', $c->cuota_periodicidad], ['IVA', $c->cuota_iva.' %'], ['Fecha de inicio', Formato::fecha($c->cuota_inicio) ?: '—'], ['Forma de cobro', $c->cuota_forma_cobro], ['Concepto', $c->cuota_concepto ?: '—']];
    @endphp
    @foreach(SeccionesAlta::secciones($c->tipo) as $k => $l)
      @php $sp = SeccionesAlta::spec($k, $c->tipo); $lst = $sp ? $c->seccion($k) : []; @endphp
      <div class="card" style="gap:8px">
        <div class="card-h"><span class="ct">{{ $l }}</span><a class="lnk" href="{{ route('clientes.edit', [$c, 'sec' => $k]) }}">Editar</a></div>
        @if($k === 'general' || $k === 'cuota')
          <div class="kvg">@foreach($k === 'general' ? $gen : $cuo as [$kl, $kv])<div class="kv"><span>{{ $kl }}</span><span>{{ $kv }}</span></div>@endforeach</div>
        @elseif($k === 'logo')
          <div class="row" style="gap:16px">
            @foreach(['logo' => 'Logo', 'firma' => 'Firma'] as $slot => $sl)
              <div style="display:flex;flex-direction:column;gap:6px"><span style="font-size:12.5px;color:#6A6A65">{{ $sl }}</span>
                <div style="width:220px;height:120px;border-radius:12px;background:#F4F4F0;display:flex;align-items:center;justify-content:center;overflow:hidden">
                  @if($c->{$slot.'_documento_id'})<img src="{{ route('clientes.imagen.ver', [$c, $slot]) }}?v={{ $c->{$slot.'_documento_id'} }}" alt="{{ $sl }}" style="max-width:90%;max-height:100px">@else<span style="font-size:12.5px;color:#9A9A94">Sin subir</span>@endif
                </div></div>
            @endforeach
          </div>
        @elseif(count($lst))
          <div class="tbl">
            <div class="ftbl h">@foreach($sp['cols'] as $h)<span>{{ $h }}</span>@endforeach<span class="act n"></span></div>
            @foreach($lst as $d)
              @php [$cells, $tag, $tbg, $tfg] = SeccionesAlta::row($k, $d); @endphp
              <div class="ftbl">@foreach($cells as $cv)<span title="{{ $cv }}">{{ $cv }}</span>@endforeach<div class="act n">@if($tag)<span class="tag" style="{{ $tbg ? 'background:'.$tbg.';color:'.$tfg : '' }}">{{ $tag }}</span>@endif</div></div>
            @endforeach
          </div>
        @else
          <span style="font-size:13.5px;color:#6A6A65">{{ $sp['empty'] }}</span>
        @endif
      </div>
    @endforeach
  </div>
  @endif

  {{-- ACCESOS AL PORTAL --}}
  @if($tab === 'accesos')
  <div class="g2">
    <div class="card" style="gap:6px;overflow-x:auto">
      <span class="ct" style="padding-bottom:6px">Usuarios con acceso al portal</span>
      @forelse($usuarios as $u)
        <div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) auto;gap:12px;align-items:center;padding:11px 0;border-top:1px solid #F1F1EC;font-size:13.5px;min-width:520px">
          <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><b class="ell" style="font-weight:600">{{ $u->name }}</b><span class="mono ell" style="font-size:12px;color:#6A6A65">{{ $u->email }}</span></div>
          <div style="display:flex;flex-direction:column;gap:2px"><span class="c3">{{ $u->role === 'cliente_admin' ? 'Administrador' : 'Empleado' }}</span><span style="font-size:12px;color:#6A6A65">{{ ! $u->activo ? 'Desactivado' : ($u->must_change_password ? 'Invitado · pendiente de entrar' : 'Último acceso '.(Formato::fechaHora($u->ultimo_acceso_at) ?: '—')) }}</span></div>
          <div class="row" style="gap:12px;justify-content:flex-end">
            <form method="POST" action="{{ route('clientes.accesos.reenviar', [$c, $u]) }}" data-confirm="Se generará una contraseña provisional nueva y se enviará por correo. ¿Continuar?">@csrf<button class="lnk" type="submit">Reenviar acceso</button></form>
            <form method="POST" action="{{ route('clientes.accesos.activo', [$c, $u]) }}">@csrf<button class="lnk {{ $u->activo ? 'red' : '' }}" type="submit">{{ $u->activo ? 'Desactivar' : 'Reactivar' }}</button></form>
          </div>
        </div>
      @empty
        <div class="emptybox">Este cliente aún no tiene acceso al portal.</div>
      @endforelse
    </div>
    <form class="card" method="POST" action="{{ route('clientes.accesos.store', $c) }}" style="gap:14px">
      @csrf
      <span class="ct">Invitar al portal</span>
      <label class="fld"><span>Nombre y apellidos</span><input class="inp" name="name" value="{{ old('name') }}" required></label>
      <label class="fld"><span>Correo (será su usuario)</span><input class="inp" type="email" name="email" value="{{ old('email') }}" required></label>
      <label class="fld"><span>Rol</span><select class="sel" name="role"><option value="cliente_admin">Administrador · ve lo compartido y sube documentos</option><option value="cliente_empleado">Empleado · solo ve lo que sube él</option></select></label>
      <label class="fld"><span>Cargo (opcional)</span><input class="inp" name="cargo" value="{{ old('cargo') }}"></label>
      @if($errors->any())<span class="hint err">{{ $errors->first() }}</span>@endif
      <span class="hint muted">Recibirá un correo con una contraseña provisional que tendrá que cambiar al entrar.</span>
      <button class="btn" type="submit" style="align-self:flex-start">Enviar invitación</button>
    </form>
  </div>
  @endif
</div>
@endsection

@if(isset($drawer))
  @section('overlay')
    @include('clientes.partials.drawer', $drawer)
  @endsection
@endif
