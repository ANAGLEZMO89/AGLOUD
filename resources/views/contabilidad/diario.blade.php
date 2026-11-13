@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; $cuadra = abs($sumD - $sumH) < 0.01; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <form method="GET" action="{{ route('contabilidad.diario') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por concepto, cuenta, nº o rango (10-25)" style="flex:1;min-width:220px;max-width:380px;height:38px">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px">Desde<input class="inp" type="date" name="desde" value="{{ $desde }}" style="height:38px"></label>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px">Hasta<input class="inp" type="date" name="hasta" value="{{ $hasta }}" style="height:38px"></label>
    <button class="pillbtn" type="submit">Buscar</button>
    @if($q || $desde || $hasta)<a href="{{ route('contabilidad.diario') }}" style="font-size:13px;font-weight:600">Limpiar</a>@endif
    <span style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <span style="font-size:12.5px;font-weight:600;padding:5px 10px;border-radius:12px;background:{{ $cuadra ? '#E6F6A8' : '#FDE7E7' }};color:{{ $cuadra ? '#2E3A00' : '#8A1C1C' }}">{{ $cuadra ? '✓ Debe y haber cuadran' : 'Descuadre: '.Formato::eur($sumD - $sumH) }}</span>
      <a class="pillbtn" href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">Excel / CSV</a>
      @unless($ej->cerrado())<a class="btn" href="{{ route('contabilidad.apuntes') }}" style="background:#0B0B0B;color:#fff">+ Asiento</a>@endunless
    </span>
  </form>
  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:860px">
      <div class="dia-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Asiento</span><span>Fecha</span><span>Cuenta / concepto</span><span style="text-align:right">Debe</span><span style="text-align:right">Haber</span><span></span></div>
      @forelse($lista as $a)
        <div style="padding:10px 0;border-bottom:1px solid #EDEDE8">
          <div class="dia-cols" style="font-size:13.5px;align-items:start">
            <b class="mono">{{ $a->numero }}</b><span class="mono" style="font-size:12.5px">{{ Formato::fecha($a->fecha) }}</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;min-width:0"><b>{{ $a->concepto }}</b>@if($a->tipo !== 'Manual')<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;background:#F4F4F0">{{ $a->tipo }}</span>@endif</div>
            <span></span><span></span>
            <span style="display:flex;gap:8px;justify-content:flex-end">
              @unless($ej->cerrado() || in_array($a->tipo, ['Regularización', 'Cierre', 'Apertura'], true))
                @unless($a->origen)<a href="{{ route('contabilidad.apuntes', ['editar' => $a->id]) }}" style="font-size:12.5px;font-weight:600">Editar</a>@endunless
                <form method="POST" action="{{ route('contabilidad.borrar', $a) }}" data-confirm="¿Eliminar el asiento {{ $a->numero }}?{{ $a->origen ? ' La factura volverá a la bandeja.' : '' }}">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">Eliminar</button></form>
              @endunless
            </span>
          </div>
          @foreach($a->apuntes as $p)
            <div class="dia-cols" style="font-size:13px;padding:3px 0;color:#3C3C3C">
              <span></span><span></span>
              <span class="ell" style="padding-left:{{ (float) $p->haber ? '28px' : '0' }}"><span class="mono" style="font-size:12.5px;color:#0B0B0B">{{ $p->cuenta }}</span> · {{ Contabilidad::nombre($p->cuenta, $propias) }}@if($p->concepto)<span style="color:#6A6A65"> · {{ $p->concepto }}</span>@endif</span>
              <span class="mono" style="text-align:right">{{ (float) $p->debe ? Formato::num($p->debe) : '' }}</span><span class="mono" style="text-align:right">{{ (float) $p->haber ? Formato::num($p->haber) : '' }}</span><span></span>
            </div>
          @endforeach
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">{{ $q || $desde || $hasta ? 'Sin asientos con esa búsqueda.' : 'El libro diario de '.$ej->anio.' está vacío.' }}</div>
      @endforelse
      @if($lista->count())<div class="dia-cols" style="padding:12px 0 2px;font-weight:700;font-size:13.5px"><span></span><span></span><span>Sumas del ejercicio</span><span class="mono" style="text-align:right">{{ Formato::num($sumD) }}</span><span class="mono" style="text-align:right">{{ Formato::num($sumH) }}</span><span></span></div>@endif
    </div>
  </div>
</div>
@endsection
