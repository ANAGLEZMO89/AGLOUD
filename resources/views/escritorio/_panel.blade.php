{{-- Escritorio · panel de la empresa elegida: cuadros con lo que ya tiene metido --}}
@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $C = $P['cards'];
  $tono = ['mal' => '#B42318', 'aviso' => '#8A5A00', 'bien' => '#2E6B00'];
  $card = function (string $k, string $ico) use ($C, $tono) {
      $x = $C[$k];
      $h = '<a class="db-card'.(($x['tono'] ?? null) === 'mal' ? ' alerta' : '').'" href="'.e($x['url']).'">'
          .'<div class="db-h"><span class="db-ico">'.$ico.'</span><span class="db-t">'.e($x['t']).'</span><span class="db-go">→</span></div>'
          .'<div class="db-v">'.e($x['v']).'</div><div class="db-vs">'.e($x['vs']).'</div>';
      if (! empty($x['filas'])) {
          $h .= '<div class="db-rows">';
          foreach ($x['filas'] as $f) {
              $h .= '<div><span>'.e($f[0]).'</span><b'.(! empty($f[2]) ? ' style="color:'.$tono[$f[2]].'"' : '').'>'.e($f[1]).'</b></div>';
          }
          $h .= '</div>';
      }
      if (! empty($x['nota'])) {
          $h .= '<div class="db-nota">'.e($x['nota']).'</div>';
      }

      return $h.'</a>';
  };
@endphp
<div class="db">
  <div class="db-tit"><span class="lbl-mono">PANEL DE {{ mb_strtoupper($ctx->nombre) }}</span><span style="font-size:12.5px;color:#6A6A65">Datos a {{ Formato::fecha(now()) }} · pulsa un cuadro para ir al detalle</span></div>

  <div class="db-grid">
    {{-- Modelos fiscales --}}
    @php $f = $C['fiscal']; @endphp
    <a class="db-card db-w2 {{ ($f['tono'] ?? null) === 'mal' ? 'alerta' : '' }}" href="{{ $f['url'] }}">
      <div class="db-h"><span class="db-ico">%</span><span class="db-t">{{ $f['t'] }}</span><span class="db-go">→</span></div>
      <div class="db-v">{{ $f['v'] }}</div><div class="db-vs">{{ $f['vs'] }}</div>
      @if($f['mods'])
        <div class="db-mods">
          @foreach($f['mods'] as $m)
            <span class="db-mod {{ $m['cerca'] ? 'cerca' : '' }}"><b>{{ $m['code'] }}</b><span>{{ $m['per'] }}</span><em>{{ $m['dias'] === 0 ? 'Vence hoy' : 'Vence en '.$m['dias'].' '.($m['dias'] === 1 ? 'día' : 'días') }} · {{ Formato::fecha($m['plazo']) }}</em></span>
          @endforeach
        </div>
      @endif
    </a>
    {!! $card('avisos', '!') !!}

    {!! $card('ventas', '€') !!}
    {!! $card('compras', '↓') !!}
    {!! $card('resultado', '=') !!}

    {{-- Evolución del año --}}
    <a class="db-card db-w2" href="{{ route('contabilidad.pyg') }}">
      <div class="db-h"><span class="db-ico">▮</span><span class="db-t">Ingresos y gastos de {{ $P['anio'] }} por meses</span><span class="db-go">→</span></div>
      <div class="db-ley"><span><i style="background:#0B0B0B"></i>Ingresos</span><span><i style="background:#C8F031"></i>Compras y gastos</span></div>
      <div class="db-chart">
        @foreach($P['meses'] as $m => $x)
          <div class="db-col {{ $m === $P['mesActual'] ? 'hoy' : '' }}" title="{{ Nomina::MESES[$m - 1] }}: ingresos {{ Formato::eur($x['v']) }} · gastos {{ Formato::eur($x['g']) }}">
            <div class="db-bars"><i style="height:{{ round($x['v'] / $P['max'] * 100, 1) }}%;background:#0B0B0B"></i><i style="height:{{ round($x['g'] / $P['max'] * 100, 1) }}%;background:#C8F031"></i></div>
            <span>{{ mb_substr(Nomina::MESES[$m - 1], 0, 3) }}</span>
          </div>
        @endforeach
      </div>
    </a>
    {!! $card('laboral', '☰') !!}

    {!! $card('crm', '◎') !!}
    {!! $card('terceros', '◉') !!}
    {!! $card('docs', '▤') !!}
  </div>
</div>
