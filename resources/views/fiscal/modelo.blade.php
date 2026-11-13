@extends('layouts.app')
@php use App\Support\Formato; $fichero = in_array($code, ['303', '111', '115', '130'], true); $q = ['codigo' => $code, 'anio' => $anio, 'per' => $p]; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('fiscal._cabecera', ['crumb' => 'Fiscal · '.$nombre, 'h1' => 'Modelo '.$code])

  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <a href="{{ route('fiscal.index') }}" class="fis-back">← Modelos fiscales</a>
    <div class="chipbar" style="border-radius:11px">
      @foreach($chips as [$l, $y, $pp])
        <a class="{{ $y === $anio && $pp === $p ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ route('fiscal.modelo', ['codigo' => $code, 'anio' => $y, 'per' => $pp]) }}">{{ $l }}</a>
      @endforeach
    </div>
    @if(in_array(\App\Services\Fiscal::per($code), ['T', 'P'], true))
      <select class="sel" style="width:auto;height:36px" data-nav aria-label="Ejercicio">
        @foreach($aniosT as $y)<option value="{{ route('fiscal.modelo', ['codigo' => $code, 'anio' => $y, 'per' => $p]) }}" @selected($y === $anio)>Ejercicio {{ $y }}</option>@endforeach
      </select>
    @endif
    <span style="font-size:12px;font-weight:700;padding:5px 11px;border-radius:12px;background:{{ $estado[1] }};color:{{ $estado[2] }}">{{ $estado[0] }}</span>
    <span style="font-size:13px;color:#3C3C3C">{{ $plazo ? 'Plazo: '.Formato::fecha($plazo) : 'Sin plazo fijo' }}</span>
  </div>
  @unless($aplica)<div class="msg warn" style="font-weight:600">Según los parámetros fiscales, este cliente no tiene que presentar este modelo. Puedes consultarlo igualmente.</div>@endunless

  <form id="fis-form" method="POST" action="{{ route('fiscal.borrador', $code) }}" data-fiscal data-calc="{{ route('fiscal.calcular', $code) }}" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    @csrf
    <input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="per" value="{{ $p }}">
    <div class="card" style="flex:999 1 480px;padding:18px 20px;gap:4px;min-width:0">
      <div style="display:flex;flex-direction:column;gap:4px;padding-bottom:10px"><span style="font-size:16px;font-weight:700">{{ $nombre }} · {{ $perLbl }}</span><span style="font-size:12.5px;color:#6A6A65;line-height:1.45">{{ $origen }}</span></div>
      @if(abs($calc['devOt']) > 0.004 && in_array($code, ['303', '390'], true))
        <div class="msg warn" style="font-size:12.5px">Hay {{ Formato::eur($calc['devOt']) }} de base imponible a otros tipos de IVA (p. ej. 5 % o 0 %) que este borrador no recoge. Revísalos antes de presentar.</div>
      @endif
      @foreach($calc['filas'] as $x)
        @if(isset($x['h']))
          <div class="fis-h">{{ $x['h'] }}</div>
        @elseif($x['res'])
          <div class="fis-row fis-res"><span class="mono" style="font-weight:700;font-size:12px">{{ $x['n'] }}</span><span style="font-size:14.5px;font-weight:700">{{ $x['l'] }}</span><span class="mono" style="text-align:right;font-weight:700;font-size:16px" data-cas="{{ $x['id'] }}">{{ $x['txt'] }}</span></div>
        @elseif($x['ed'])
          <div class="fis-row"><span class="fis-n">{{ $x['n'] }}</span><span style="font-size:13.5px">{{ $x['l'] }}</span>
            <span><input class="fis-inp {{ $x['cambiada'] ? 'cambiada' : '' }}" name="cas[{{ $x['id'] }}]" value="{{ $x['txt'] }}" @if($presentado) readonly @endif aria-label="{{ $x['n'] ? 'Casilla '.$x['n'].' · ' : '' }}{{ $x['l'] }}"><input type="hidden" name="orig[{{ $x['id'] }}]" value="{{ $x['def'] }}"></span></div>
        @else
          <div class="fis-row" style="padding:9px 0"><span class="fis-n">{{ $x['n'] }}</span><span style="font-size:13.5px;font-weight:600">{{ $x['l'] }}</span><span class="mono" style="text-align:right;padding-right:10px;font-weight:700;font-size:13px" data-cas="{{ $x['id'] }}">{{ $x['txt'] }}</span></div>
        @endif
      @endforeach
      @if($calc['terc'])
        <div class="fis-h" style="padding-top:16px">Terceros declarados</div>
        @foreach($calc['terc'] as $t)
          <div class="fis-terc"><span class="mono" style="font-size:12px">{{ $t[0] }}</span><span class="ell">{{ $t[1] }}</span><span style="font-size:12px;color:#6A6A65">{{ $t[2] }}</span><span class="mono" style="text-align:right;font-weight:600">{{ Formato::eur($t[3]) }}</span></div>
        @endforeach
      @endif
    </div>

    <div style="flex:1 1 260px;display:flex;flex-direction:column;gap:12px;min-width:0">
      @if($calc['res'] && $code !== '036')
        <div style="background:#0B0B0B;color:#F4F4F0;border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:6px"><span style="font-size:13px;color:#BDBDB7">Resultado · {{ $perLbl }}</span><span class="mono" style="font-weight:700;font-size:30px;line-height:1.1;color:#C8F031;letter-spacing:-.02em" data-res>{{ Formato::eur($calc['resV']) }}</span><span style="font-size:13px" data-reslbl>{{ $calc['resLbl'] }}</span></div>
      @endif
      <div class="card" style="padding:16px 18px;gap:10px">
        <label class="lbl-s">Tipo de declaración<select class="sel" name="tipo_decl" data-tipo @disabled($presentado)>@foreach($calc['tipoOpts'] as $k => $l)<option value="{{ $k }}" @selected($calc['tipo'] === $k)>{{ $l }}</option>@endforeach</select></label>
        @unless($presentado)<button class="btn" type="submit" style="height:42px;border-radius:21px;justify-content:center">Guardar borrador</button>@endunless
        <span class="hidden" data-dirty style="font-size:12px;color:#6E4300;font-weight:600">Hay cambios sin guardar</span>
      </div>
    </div>
  </form>

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;margin-top:-4px">
    <div style="flex:999 1 480px"></div>
    <div class="card" style="flex:1 1 260px;padding:16px 18px;gap:10px;min-width:0">
      <form method="POST" action="{{ route('fiscal.presentado', $code) }}" style="display:flex;flex-direction:column;gap:8px">
        @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="per" value="{{ $p }}">
        @if($presentado)
          <button class="btn soft" type="submit" style="height:40px;border-radius:20px;justify-content:center">Desmarcar presentado</button>
        @else
          <label class="lbl-s">Fecha de presentación<input class="inp" type="date" name="fecha" value="{{ \App\Support\Plazos::hoy()->toDateString() }}" max="{{ \App\Support\Plazos::hoy()->toDateString() }}"></label>
          <button class="btn soft" type="submit" style="height:40px;border-radius:20px;justify-content:center">Marcar como presentado</button>
        @endif
      </form>
      @unless($presentado)
      <form method="POST" action="{{ route('fiscal.recalcular', $code) }}">
        @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="per" value="{{ $p }}">
        <button class="btn soft" type="submit" style="width:100%;height:38px;border-radius:19px;justify-content:center;font-weight:600;font-size:13px">Recalcular desde contabilidad</button>
      </form>
      @endunless
      <div style="display:flex;flex-direction:column;gap:8px;padding-top:10px;border-top:1px solid #F1F1EC">
        <span style="font-size:13px;font-weight:700">Presentación en la AEAT</span>
        @if($fichero)
          <span class="fis-gen" aria-disabled="true">Generar fichero<small>pendiente de validar el diseño de registro oficial</small></span>
        @endif
        <a class="btn lime" href="{{ $url }}" target="_blank" rel="noopener" style="height:42px;border-radius:21px;justify-content:center">Presentar en Hacienda ↗</a>
        <span style="font-size:12px;color:#6A6A65;line-height:1.45">Se abre la Sede de la AEAT. Este modelo se cumplimenta allí con los importes de esta pantalla. Cuando lo presentes, márcalo como presentado.</span>
      </div>
      <div style="display:flex;align-items:center;gap:4px;padding-top:6px;border-top:1px solid #F1F1EC;flex-wrap:wrap"><span style="padding-right:6px;font-size:13px;font-weight:700">Exportar</span>
        <a class="fis-x" href="{{ route('fiscal.exportar', $q + ['formato' => 'csv']) }}">Excel</a>
        <a class="fis-x" href="{{ route('fiscal.exportar', $q + ['formato' => 'csv']) }}">CSV</a>
        <a class="fis-x" href="{{ route('fiscal.exportar', $q + ['formato' => 'pdf']) }}" target="_blank" rel="noopener">PDF</a>
      </div>
    </div>
  </div>
</div>
@endsection
