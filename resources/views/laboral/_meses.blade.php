{{-- Selector de mes (prototipo: Ene … Dic) y de año --}}
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
  <div class="chipbar" style="border-radius:11px">
    @foreach(\App\Services\Nomina::MESES as $i => $l)
      <a class="{{ $mes === $i + 1 ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ route($ruta, ['anio' => $anio, 'mes' => $i + 1]) }}">{{ mb_substr($l, 0, 3) }}</a>
    @endforeach
  </div>
  <select class="sel" style="width:auto;height:38px" data-nav aria-label="Año">
    @foreach(range(\App\Support\Plazos::hoy()->year + 1, \App\Support\Plazos::hoy()->year - 4) as $y)<option value="{{ route($ruta, ['anio' => $y, 'mes' => $mes]) }}" @selected($y === $anio)>{{ $y }}</option>@endforeach
  </select>
</div>
