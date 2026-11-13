@extends('layouts.app')
@php
    use App\Models\Expediente;
    use App\Support\Formato;
    [$bg, $fg] = $e->colores();
    $idx = array_search($e->estado, Expediente::ESTADOS, true);
    $sig = $e->siguienteEstado();
    $pend = $e->docsPendientes();
    $wa = $e->cliente?->whatsapp();
    $nombre = explode(' ', trim((string) $e->cliente?->nombre))[0] ?? '';
    $waTxt = 'Hola, '.$nombre.'. Para avanzar con tu expediente '.$e->ref.' ('.$e->tramite.') necesitamos: '.implode(', ', $pend).'. Puedes subirlo al portal del cliente o enviárnoslo por aquí. ¡Gracias!';
    $eventos = $e->eventos;
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6A6A65"><a href="{{ route('expedientes.index') }}" style="color:#6A6A65;text-decoration:underline">Expedientes</a><span>/</span><span style="color:#0B0B0B;font-weight:600;font-family:var(--mono)">{{ $e->ref }}</span></div>

  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div class="card" style="padding:20px 22px;flex-direction:row;align-items:center;gap:18px;flex-wrap:wrap">
    <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:8px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span style="font:500 12.5px var(--mono);color:#6A6A65">{{ $e->ref }}</span><span style="font-size:12px;font-weight:600;padding:3px 9px;border-radius:20px;background:{{ $bg }};color:{{ $fg }}">{{ $e->estado }}</span><span style="font-size:12px;padding:3px 9px;border-radius:20px;background:#F4F4F0;color:#3C3C3C">{{ $e->categoria }}</span></div>
      <h1 style="margin:0;font-size:26px;font-weight:700;letter-spacing:-.02em">{{ $e->tramite }}</h1>
      <span style="font-size:14px;color:#3C3C3C">Cliente: <a href="{{ route('clientes.show', [$e->cliente_id, 'tab' => 'exp']) }}" style="font-weight:600;color:#0B0B0B;text-decoration:underline">{{ $e->cliente?->nombre }}</a> · Asesor: {{ $e->responsable?->name ?? '—' }}</span>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      @if($pend && $wa)
        <a class="btn lime" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($waTxt) }}" target="_blank" rel="noopener noreferrer">Pedir documentación por WhatsApp</a>
      @elseif($pend)
        <span class="btn lime" style="opacity:.5;cursor:not-allowed" title="El cliente no tiene teléfono en su ficha">Pedir documentación por WhatsApp</span>
      @endif
      @if($sig)
        <form method="POST" action="{{ route('expedientes.estado', $e) }}">@csrf<input type="hidden" name="estado" value="{{ $sig }}"><button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Pasar a <span style="color:#C8F031">{{ $sig }}</span></button></form>
      @endif
    </div>
  </div>

  <div class="steps">
    @foreach(Expediente::ESTADOS as $i => $l)
      <form method="POST" action="{{ route('expedientes.estado', $e) }}" style="display:contents">
        @csrf<input type="hidden" name="estado" value="{{ $l }}">
        <button type="submit" class="{{ $i < $idx ? 'done' : ($i === $idx ? 'cur' : '') }}" @disabled($i === $idx)><i>{{ $i < $idx ? '✓' : $i + 1 }}</i><span>{{ $l }}</span></button>
      </form>
    @endforeach
  </div>

  <div class="exp-det">
    <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
      <div class="card" style="padding:18px 20px;gap:6px">
        <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font-weight:700;font-size:15px">Pasos del trámite</span><span style="font:600 13px var(--mono)">{{ $e->progTxt() }}</span></div>
        <div style="height:6px;border-radius:3px;background:#F1F1EC;overflow:hidden;margin:6px 0 8px"><div style="height:100%;border-radius:3px;background:#C8F031;width:{{ $e->pct() }}%"></div></div>
        @foreach($e->pasos as $i => $p)
          <form method="POST" action="{{ route('expedientes.paso', [$e, $i]) }}">@csrf<button type="submit" class="chkrow {{ $p['ok'] ? 'ok' : '' }}"><i>{{ $p['ok'] ? '✓' : '' }}</i><span>{{ $p['l'] }}</span></button></form>
        @endforeach
      </div>

      <div class="card" style="padding:18px 20px;gap:6px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:6px"><span style="font-weight:700;font-size:15px">Documentación</span><span style="font:600 13px var(--mono)">{{ $e->docsOk() }}/{{ count($e->docs) }}</span></div>
        @forelse($e->docs as $i => $d)
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-top:1px solid #F1F1EC">
            <span style="font-size:14px">{{ $d['l'] }}</span>
            <form method="POST" action="{{ route('expedientes.doc', [$e, $i]) }}">@csrf<button type="submit" class="docst {{ $d['ok'] ? 'ok' : 'no' }}">{{ $d['ok'] ? 'Recibido' : 'Pendiente' }}</button></form>
          </div>
        @empty
          <span style="font-size:13.5px;color:#6A6A65;padding:6px 0">Este trámite no pide documentación al cliente.</span>
        @endforelse
      </div>

      <div class="card" style="padding:18px 20px;gap:10px">
        <span style="font-weight:700;font-size:15px">Historial y notas</span>
        <form method="POST" action="{{ route('expedientes.nota', $e) }}" style="display:flex;gap:8px">
          @csrf
          <input class="inp" name="texto" maxlength="2000" placeholder="Añadir una nota interna…" style="flex:1;min-width:0;height:38px;background:#F4F4F0;border-color:#E6E6E0" required>
          <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Añadir</button>
        </form>
        @foreach($eventos as $ev)
          <div style="display:grid;grid-template-columns:12px minmax(0,1fr);gap:10px;padding:6px 0"><span style="width:8px;height:8px;margin-top:5px;border-radius:50%;background:{{ $ev->nota ? '#C8F031' : '#0B0B0B' }};{{ $ev->nota ? 'box-shadow:0 0 0 1.5px #0B0B0B' : '' }}"></span><div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:13.5px;white-space:pre-line">{{ $ev->texto }}</span><span style="font:500 11.5px var(--mono);color:#8A8A85">{{ Formato::fechaHora($ev->created_at) }}{{ $ev->autor ? ' · '.$ev->autor->name : '' }}</span></div></div>
        @endforeach
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
      <div class="dark" style="border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:8px;background:#0B0B0B;color:#F4F4F0">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">PLAZO</span>
        <span style="font:600 34px/1 var(--mono);letter-spacing:-.03em">{{ $e->plazoTxt() }}</span>
        <span style="display:flex;align-items:center;gap:8px;font-size:13.5px;color:#D0D0CA"><span style="width:8px;height:8px;border-radius:50%;background:{{ $e->dot() }}"></span>{{ $e->leftTxt() }}</span>
        @if($e->plazo_lbl)<span style="font-size:13px;color:#8A8A85">{{ $e->plazo_lbl }}</span>@endif
      </div>

      <div class="card" style="padding:18px 20px;gap:2px">
        <span style="font-weight:700;font-size:15px;padding-bottom:8px">Detalles</span>
        @foreach([['Cliente', $e->cliente?->nombre], ['Asesor', $e->responsable?->name ?? '—'], ['Apertura', Formato::fecha($e->apertura)], ['Categoría', $e->categoria], ['Referencia', $e->ref]] as [$l, $v])
          <div style="display:grid;grid-template-columns:100px minmax(0,1fr);gap:12px;padding:8px 0;border-top:1px solid #F1F1EC;font-size:13.5px"><span style="color:#6A6A65">{{ $l }}</span><span style="font-weight:500">{{ $v }}</span></div>
        @endforeach
        @if($notif)
          <div style="display:grid;grid-template-columns:100px minmax(0,1fr);gap:12px;padding:8px 0;border-top:1px solid #F1F1EC;font-size:13.5px"><span style="color:#6A6A65">Notificación</span><a href="{{ route('notificaciones.index', ['sel' => $notif->id, 'chip' => 2]) }}" style="font-weight:500">{{ $notif->organismo }} · {{ $notif->tipo }}</a></div>
        @endif
        <details style="padding-top:10px;border-top:1px solid #F1F1EC">
          <summary style="cursor:pointer;font-size:13px;font-weight:600">Cambiar asesor, fecha límite u honorarios</summary>
          <form method="POST" action="{{ route('expedientes.actualizar', $e) }}" style="display:flex;flex-direction:column;gap:10px;padding-top:10px">
            @csrf
            <label class="lbl-s">Asesor responsable<select class="sel" name="responsable_id"><option value="">—</option>@foreach(\App\Models\User::whereIn('role', \App\Models\User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get() as $a)<option value="{{ $a->id }}" @selected($e->responsable_id === $a->id)>{{ $a->name }}</option>@endforeach</select></label>
            <label class="lbl-s">Fecha límite<input class="inp" type="date" name="plazo" value="{{ $e->plazo?->toDateString() }}"></label>
            <label class="lbl-s">Qué vence en esa fecha<input class="inp" name="plazo_lbl" value="{{ $e->plazo_lbl }}" maxlength="160"></label>
            <label class="lbl-s">Honorarios (€, sin IVA)<input class="inp mono" name="honorarios" value="{{ Formato::num($e->honorarios) }}" inputmode="decimal"></label>
            <button class="btn sm" type="submit" style="align-self:flex-start">Guardar</button>
          </form>
        </details>
      </div>

      <div style="background:#C8F031;border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font-weight:700;font-size:15px">Honorarios</span><span style="font:600 24px var(--mono)">{{ Formato::eur($e->honorarios, fmod((float) $e->honorarios, 1) ? 2 : 0) }}</span></div>
        <span style="font-size:13px">{{ $e->facturaTxt() }} · aparte de la cuota mensual · sin IVA</span>
      </div>

      <div class="card" style="padding:16px 20px;flex-direction:row;align-items:center;justify-content:space-between;gap:12px">
        <div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:14px;font-weight:600">Visible en el portal del cliente</span><span style="font-size:12.5px;color:#6A6A65">El cliente ve el estado y los documentos que faltan.</span></div>
        <form method="POST" action="{{ route('expedientes.portal', $e) }}">@csrf<button type="submit" class="toggle {{ $e->portal ? 'on' : '' }}" aria-label="Visible en el portal" aria-pressed="{{ $e->portal ? 'true' : 'false' }}"><i></i></button></form>
      </div>

      @if(auth()->user()->esAdmin())
        <form method="POST" action="{{ route('expedientes.destroy', $e) }}" data-confirm="¿Eliminar el expediente {{ $e->ref }}? No se puede deshacer. Queda constancia en el registro de accesos.">
          @csrf @method('DELETE')
          <button class="lnk red" type="submit" style="font-size:12.5px">Eliminar expediente</button>
        </form>
      @endif
    </div>
  </div>
</div>
@endsection
