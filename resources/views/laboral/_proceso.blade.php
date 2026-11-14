{{-- Estado real de un proceso posterior: qué hace AG Cloud y qué se hace fuera; y de qué nóminas salen los datos.
     $pasos: [clave => [estado, texto]] con estado hecho | aqui | fuera | no ; $est: Posteriores::estado() --}}
@php
  $col = ['hecho' => ['#E6F6A8', '#2E3A00', '✓'], 'aqui' => ['#E3ECFF', '#1D3A8A', '●'], 'fuera' => ['#FCEBCB', '#6E4300', '↗'], 'no' => ['#ECECE7', '#6A6A65', '—']];
@endphp
<div class="card" style="gap:8px;padding:12px 16px">
  <div role="list" aria-label="Pasos del proceso" style="display:flex;flex-wrap:wrap;gap:6px">
    @foreach(\App\Services\Posteriores::PASOS as $k => $l)
      @php [$e, $t] = $pasos[$k] ?? ['no', 'No se hace en AG Cloud']; [$bg, $fg, $ic] = $col[$e]; @endphp
      <span role="listitem" title="{{ $t }}" style="display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:9px;background:{{ $bg }};color:{{ $fg }};font-size:12.5px"><b aria-hidden="true">{{ $ic }}</b>{{ $l }}<span class="sr-only">: {{ $t }}</span></span>
    @endforeach
  </div>
  <span style="font-size:12.5px;color:#3C3C3C;line-height:1.45">@foreach($pasos as $k => [$e, $t])<b>{{ \App\Services\Posteriores::PASOS[$k] }}:</b> {{ $t }}@if(! $loop->last) · @endif @endforeach</span>
  @if(isset($est))
    @if($est['pendientes'])
      <span class="note-amber"><b>Datos provisionales:</b> {{ $est['emitidas'] }} de {{ $est['total'] }} recibos emitidos. Sin emitir: {{ implode(', ', $est['pendientes']) }}. Lo que no está emitido puede cambiar.</span>
    @elseif($est['total'])
      <span style="font-size:12.5px;color:#2E3A00">Datos de los {{ $est['total'] }} recibos emitidos del mes (su foto del cálculo).</span>
    @endif
  @endif
</div>
