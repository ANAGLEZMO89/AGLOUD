@extends('layouts.app')
@php
  use App\Support\Formato;
  use App\Services\Contabilidad;
  $cob = $tipo === 'cobro';
  $verbo = $cob ? 'Cobrado' : 'Pagado';
  $url = fn (array $x = []) => route($rutaLista, array_filter(array_merge(['chip' => $chip ?: null, 't' => $ter ?: null], $x), fn ($v) => $v !== null && $v !== ''));
  $terLbl = $cob ? 'Cliente' : ($mod === 'proveedores' ? 'Proveedor' : 'Acreedor');
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('gestion._cabecera', ['tituloH' => $titulo, 'volver' => '/'.$mod.'/'.($cob ? 'cobros' : 'pagos'), 'modulo' => $mod, 'actual' => $rutaLista])

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach($kpis as [$l, $val, $s])<div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>@endforeach
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    <div class="card" style="flex:1 1 260px;padding:16px 18px;gap:2px;min-width:0">
      <span style="font-size:15px;font-weight:700;padding-bottom:8px">{{ $cob ? 'Por cliente' : 'Por '.mb_strtolower($terLbl) }}</span>
      <a class="cp-ter {{ $ter === '' ? 'on' : '' }}" href="{{ $url(['t' => null]) }}"><span>Todos</span><span class="mono">{{ Formato::eur($porTercero->sum('pend')) }}</span></a>
      @foreach($porTercero as $t)
        <a class="cp-ter {{ $ter === $t['ter'] ? 'on' : '' }}" href="{{ $url(['t' => $t['ter']]) }}"><span class="ell">{{ $t['ter'] ?: '—' }}@if($t['n'])<small>{{ $t['n'] }} pendiente{{ $t['n'] === 1 ? '' : 's' }}</small>@endif</span><span class="mono">{{ Formato::eur($t['pend']) }}</span></a>
      @endforeach
      @if($porTercero->isEmpty())<span class="hint muted">Aún no hay facturas.</span>@endif
    </div>

    <div class="card" style="flex:999 1 560px;padding:16px 18px;gap:12px;min-width:0">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
        <div class="chipbar" style="border-radius:11px">@foreach(\App\Http\Controllers\CobrosPagosController::CHIPS as $i => $l)<a class="{{ $chip === $i ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ $url(['chip' => $i ?: null]) }}">{{ $l }}</a>@endforeach</div>
        @if($nSinConta)
          <form method="POST" action="{{ $rutaTodos }}" data-confirm="¿Enviar a contabilidad los {{ $nSinConta }} {{ $cob ? 'cobros' : 'pagos' }} marcados? Cada uno se asienta por su medio (banco o caja).">@csrf<button class="btn lime sm" type="submit">Enviar a contabilidad los {{ $nSinConta }} marcados</button></form>
        @endif
      </div>
      @if($ter !== '')<span style="font-size:13px;color:#3C3C3C">{{ $terLbl }}: <b>{{ $ter }}</b> · <a href="{{ $url(['t' => null]) }}">ver todos</a></span>@endif
      <div class="cp-row cp-head"><span>Factura</span><span>{{ $terLbl }}</span><span>Vence</span><span style="text-align:right">Importe</span><span>{{ $verbo }}</span><span>Contabilidad</span></div>
      @forelse($lista as $f)
        @php $vencida = ! $f['hecho'] && $f['venc']->lt($hoy); @endphp
        <div class="cp-row">
          <a href="{{ $f['url'] }}" style="display:flex;flex-direction:column;color:inherit;text-decoration:none;min-width:0"><b class="mono" style="font-size:12.5px">{{ $f['num'] }}</b><small style="color:#6A6A65">{{ Formato::fecha($f['fecha']) }}</small></a>
          <span class="ell" style="font-size:13px">{{ $f['ter'] }}</span>
          <span style="font-size:12.5px;{{ $vencida ? 'color:#B42318;font-weight:700' : 'color:#3C3C3C' }}">{{ Formato::fecha($f['venc']) }}</span>
          <span class="mono" style="text-align:right;font-weight:600;font-size:13px">{{ Formato::eur($f['total']) }}</span>
          <form method="POST" action="{{ $f['marcar'] }}">@csrf
            @if($f['hecho'])
              <button type="submit" class="cp-chip ok" @if($f['asiento']) disabled title="Contabilizado: borra antes su asiento para deshacerlo" @else title="Pulsa para deshacer" @endif>✓ {{ $verbo }} {{ $f['hecho_at'] ? Formato::fecha($f['hecho_at']) : '' }}</button>
            @else
              <button type="submit" class="cp-chip {{ $vencida ? 'venc' : '' }}">Marcar {{ mb_strtolower($verbo) }}</button>
            @endif
          </form>
          @if($f['asiento'])
            <a class="cp-conta" href="{{ route('contabilidad.diario', ['q' => $f['num']]) }}">✓ Contabilizado · {{ Contabilidad::MEDIOS[$f['medio']] ?? 'Banco' }}</a>
          @elseif($f['hecho'])
            <form method="POST" action="{{ $f['contabilizar'] }}" class="cp-envio">@csrf
              <select class="sel" name="medio" aria-label="Medio">@foreach(Contabilidad::MEDIOS as $k => $l)<option value="{{ $k }}" @selected($f['medio'] === $k)>{{ $l }}</option>@endforeach</select>
              <button class="btn sm" type="submit">Enviar a contabilidad</button>
            </form>
          @else
            <span class="hint muted" style="font-size:12px">{{ $f['conta'] ? 'Factura contabilizada' : 'Factura sin contabilizar' }}</span>
          @endif
        </div>
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay facturas en esta vista.</div>
      @endforelse
      <span class="hint muted">«Enviar a contabilidad» registra el asiento del {{ $tipo }} con la fecha en que se marcó: {{ $cob ? 'banco (572) o caja (570) al debe y el cliente (430…) al haber' : 'el '.mb_strtolower($terLbl).' (400…/410…) al debe y banco (572) o caja (570) al haber' }}. Si la factura aún no estaba contabilizada, se contabiliza antes.</span>
    </div>
  </div>
</div>
@endsection
