@extends('layouts.app')
@php
  use App\Http\Controllers\ControlHorarioController as CH;
  use App\Support\Formato;
  $TABS = [['hoy', 'Hoy'], ['reg', 'Registro diario'], ['res', 'Resumen mensual'], ['aus', 'Ausencias y vacaciones']];
  $selMes = fn ($extra = []) => '<select class="sel" style="width:auto;height:38px" data-nav aria-label="Mes">'.collect($meses)->map(fn ($m) => '<option value="'.e(route('horario.index', ['tab' => $tab, 'ym' => $m[0]] + $extra)).'"'.($m[0] === $ym ? ' selected' : '').'>'.e($m[1]).'</option>')->implode('').'</select>';
  $dif = fn ($m) => ($m > 0 ? '+' : '').CH::hm($m);
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Registro de jornada · art. 34.9 del Estatuto de los Trabajadores</span><h1 class="ttl">Control horario</h1></div>
    <div class="row" style="min-width:0;max-width:100%">
      <span style="font:600 13px var(--mono);padding:10px 14px;border-radius:20px;background:#fff">Hoy {{ Formato::fecha($ahora) }} · {{ $ahora->format('H:i') }}</span>
      @include('partials.empresa', ['volver' => '/control-horario'])
    </div>
  </div>
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  @if($W->isEmpty() && $tab !== 'aus')
    <div class="card" style="padding:28px;text-align:center;font-size:14px;color:#6A6A65">Esta empresa no tiene trabajadores dados de alta. Dalos de alta en <a href="{{ route('laboral.index') }}">Laboral y SS › Trabajadores</a>.</div>
  @endif

  <div class="chipbar" style="border-radius:11px">
    @foreach($TABS as [$id, $l])<a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 13px;border-radius:8px" href="{{ route('horario.index', ['tab' => $id] + ($id === 'hoy' || $id === 'aus' ? [] : ['ym' => $ym])) }}">{{ $l }}</a>@endforeach
  </div>

  @if($tab === 'hoy' && $W->isNotEmpty())
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
      @foreach([['En la empresa', $hoyL->filter(fn ($x) => str_starts_with($x['st'], 'Trabajando'))->count().' de '.$W->count()], ['En pausa', (string) $hoyL->filter(fn ($x) => str_starts_with($x['st'], 'En pausa'))->count()], ['Ausentes', (string) $hoyL->where('aus', true)->count()], ['Sin fichar', (string) $hoyL->where('st', 'Sin fichar')->count()]] as [$l, $v])
        <div class="card" style="gap:6px;padding:16px 18px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 24px/1.1 var(--mono)">{{ $v }}</span></div>
      @endforeach
    </div>
    <div class="card" style="padding:6px 18px">
      @foreach($hoyL as $x)
        <div class="ho-row">
          <div style="display:flex;align-items:center;gap:12px;min-width:0"><span style="width:10px;height:10px;border-radius:50%;flex:none;background:{{ $x['dot'] }}"></span><div style="display:flex;flex-direction:column;min-width:0"><span class="ell" style="font-weight:700;font-size:14px">{{ $x['w']['nombre'] }}</span><span class="ell" style="font-size:12px;color:#6A6A65">{{ $x['w']['convCat'] ?: $x['w']['cat'] }} · {{ $x['w']['jorTxt'] }}</span></div></div>
          <div style="display:flex;flex-direction:column;min-width:0"><span style="font-size:13.5px;font-weight:600">{{ $x['st'] }}</span><span class="ell" style="font:500 12px var(--mono);color:#6A6A65">{{ $x['tramos'] }} · {{ $x['horas'] }}</span></div>
          <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
            @foreach([['in', 'entrada', 'Entrada', 'lime'], ['pause', 'pausa', 'Pausa', 'soft'], ['resume', 'reanudar', 'Reanudar', 'lime'], ['out', 'salida', 'Salida', '']] as [$k, $acc, $l, $cls])
              @if($x[$k])<form method="POST" action="{{ route('horario.fichar', [$x['w']['id'], $acc]) }}">@csrf<button class="btn sm {{ $cls }}" type="submit">{{ $l }}</button></form>@endif
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
    <span style="font-size:12.5px;color:#6A6A65;line-height:1.5">La empresa debe registrar cada día la hora de inicio y fin de la jornada de cada trabajador, conservar el registro 4 años y tenerlo a disposición de los trabajadores, sus representantes y la Inspección de Trabajo.</span>
  @endif

  @if($tab === 'reg' && $W->isNotEmpty())
    @php $tot = array_sum(array_column($days, 'mins')); $teo = array_sum(array_column($days, 'teo')); @endphp
    <div class="no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
      <select class="sel" style="width:auto;height:38px" data-nav aria-label="Trabajador">@foreach($W as $x)<option value="{{ route('horario.index', ['tab' => 'reg', 'ym' => $ym, 't' => $x['id']]) }}" @selected($x['id'] === $w['id'])>{{ $x['nombre'] }}</option>@endforeach</select>
      {!! $selMes(['t' => $w['id']]) !!}
      <span style="font:600 13px var(--mono);color:#3C3C3C">Total {{ CH::hm($tot) }} · teóricas {{ CH::hm($teo) }} · diferencia {{ $dif($tot - $teo) }}</span>
      <div style="margin-left:auto" class="lb-exp"><span class="t">Exportar</span><a href="{{ request()->fullUrlWithQuery(['exportar' => 'excel']) }}">Excel</a><button type="button" data-print>PDF</button></div>
    </div>
    <div class="print-only" style="font-size:13px"><b>Registro de jornada</b> · {{ $c->nombre }} ({{ $c->nif }}) · {{ $w['nombre'] }} ({{ $w['nif'] }}) · {{ collect($meses)->firstWhere(0, $ym)[1] ?? $ym }}</div>
    <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
      <div style="min-width:760px">
        <div class="lb-grid ho-reg lb-head"><span>Día</span><span>Entradas y salidas</span><span class="r">Horas</span><span class="r">Jornada</span><span class="r">Dif.</span><span></span></div>
        @foreach($days as $d)
          @php $cs = $corr->get($d['f']); $ed = $edit === $d['f']; $df = $d['mins'] - $d['teo']; @endphp
          <div style="border-bottom:1px solid #F1F1EC;background:{{ ! $d['lab'] || $d['aus'] ? '#FAFAF7' : '#fff' }};margin:0 -8px;padding:0 8px">
            <div class="lb-grid ho-reg" style="padding:10px 0;font-size:13.5px">
              <span class="mono12">{{ CH::DOW[$d['wd']] }} {{ substr($d['f'], 8) }}/{{ substr($d['f'], 5, 2) }}</span>
              <span style="display:flex;flex-direction:column;min-width:0"><span class="ell" style="font:500 13px var(--mono);color:{{ $d['tramos'] ? '#0B0B0B' : '#6A6A65' }}">{{ CH::tramosTxt($d['tramos']) ?: ($d['aus'] ? $d['aus']->tipo : (! $d['lab'] ? ($d['fest'] ?? 'Descanso') : 'Sin registro')) }}</span>@if($cs)<span class="ell" style="font-size:11.5px;color:#6E4300">Corregido: {{ $cs->last()->motivo }}</span>@endif</span>
              <span class="r m">{{ $d['mins'] ? CH::hm($d['mins']) : '—' }}</span><span class="r m" style="color:#6A6A65">{{ $d['teo'] ? CH::hm($d['teo']) : '—' }}</span>
              <span class="r m" style="color:{{ $df < -15 ? '#8A1C1C' : ($df > 15 ? '#2E3A00' : '#6A6A65') }}">{{ $d['teo'] || $d['mins'] ? $dif($df) : '' }}</span>
              <span class="no-print" style="text-align:right">@if(! $ed)<a href="{{ route('horario.index', ['tab' => 'reg', 'ym' => $ym, 't' => $w['id'], 'editar' => $d['f']]) }}" style="font-size:12.5px;font-weight:600">Corregir</a>@endif</span>
            </div>
            @if($ed)
              <form method="POST" action="{{ route('horario.corregir', $w['id']) }}" class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:0 0 12px">
                @csrf<input type="hidden" name="fecha" value="{{ $d['f'] }}">
                <input class="inp lb-in mono" name="txt" value="{{ old('txt', str_replace('–', '-', implode(', ', array_map(fn ($x) => $x['in'].'-'.($x['out'] ?? ''), $d['tramos'])))) }}" placeholder="08:00-14:00, 15:00-17:00" style="flex:1 1 220px">
                <input class="inp lb-in" name="motivo" value="{{ old('motivo') }}" placeholder="Motivo de la corrección *" style="flex:1 1 220px" required>
                <a class="btn soft sm" href="{{ route('horario.index', ['tab' => 'reg', 'ym' => $ym, 't' => $w['id']]) }}">Cancelar</a><button class="btn sm" type="submit">Guardar</button>
              </form>
              @if($cs)<div style="padding:0 0 12px;display:flex;flex-direction:column;gap:3px">@foreach($cs as $x)<span style="font-size:11.5px;color:#6A6A65">{{ Formato::fechaHora($x->created_at) }} · «{{ $x->antes ?: 'sin registro' }}» → «{{ $x->despues ?: 'sin registro' }}» · {{ $x->motivo }}</span>@endforeach</div>@endif
            @endif
          </div>
        @endforeach
        <div class="lb-grid ho-reg" style="padding:12px 0 4px;font-weight:700"><span></span><span>Total del mes</span><span class="r m">{{ CH::hm($tot) }}</span><span class="r m">{{ CH::hm($teo) }}</span><span class="r m">{{ $dif($tot - $teo) }}</span><span></span></div>
      </div>
    </div>
    <div class="print-only"><div style="display:flex;justify-content:space-between;gap:40px;margin-top:40px;font-size:12px"><span>Firma de la empresa:</span><span>Firma del trabajador:</span></div></div>
  @endif

  @if($tab === 'res' && $W->isNotEmpty())
    <div class="no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">{!! $selMes() !!}<div style="margin-left:auto" class="lb-exp"><span class="t">Exportar</span><a href="{{ request()->fullUrlWithQuery(['exportar' => 'excel']) }}">Excel</a><button type="button" data-print>PDF</button></div></div>
    <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
      <div style="min-width:760px">
        <div class="lb-grid ho-res lb-head"><span>Trabajador</span><span class="r">Días</span><span class="r">Horas</span><span class="r">Teóricas</span><span class="r">Dif.</span><span class="r">Ausencias</span></div>
        @foreach($res as $r)
          @php $df = $r['mins'] - $r['teo']; @endphp
          <a class="lb-grid ho-res lb-row" href="{{ route('horario.index', ['tab' => 'reg', 'ym' => $ym, 't' => $r['w']['id']]) }}"><span style="display:flex;flex-direction:column;min-width:0"><b class="ell">{{ $r['w']['nombre'] }}</b><span style="font-size:12px;color:#6A6A65">{{ $r['w']['jorTxt'] }}</span></span>
            <span class="r m">{{ $r['dias'] }}</span><span class="r m">{{ CH::hm($r['mins']) }}</span><span class="r m">{{ CH::hm($r['teo']) }}</span><span class="r m" style="color:{{ $df < -30 ? '#8A1C1C' : ($df > 30 ? '#2E3A00' : '#3C3C3C') }}">{{ $dif($df) }}</span><span class="r m">{{ $r['aus'] }}</span></a>
        @endforeach
      </div>
    </div>
  @endif

  @if($tab === 'aus')
    <form class="card" method="POST" action="{{ route('horario.ausencia') }}">
      @csrf
      <span style="font-size:15px;font-weight:700">Registrar ausencia</span>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,170px),1fr));gap:10px;align-items:end">
        <label class="lb-f"><span class="lb-l">Trabajador</span><select class="sel lb-in" name="trabajador">@foreach($todos->where('estado', 'Alta') as $x)<option value="{{ $x['id'] }}" @selected((int) old('trabajador') === $x['id'])>{{ $x['nombre'] }}</option>@endforeach</select></label>
        <label class="lb-f"><span class="lb-l">Tipo</span><select class="sel lb-in" name="tipo">@foreach(CH::TIPOS as $x)<option @selected(old('tipo') === $x)>{{ $x }}</option>@endforeach</select></label>
        <label class="lb-f"><span class="lb-l">Desde</span><input class="inp lb-in" type="date" name="desde" value="{{ old('desde', $ahora->toDateString()) }}"></label>
        <label class="lb-f"><span class="lb-l">Hasta</span><input class="inp lb-in" type="date" name="hasta" value="{{ old('hasta', $ahora->toDateString()) }}"></label>
        <button class="btn lime" type="submit">Añadir</button>
      </div>
    </form>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:16px;align-items:start">
      <div class="card" style="gap:0;padding:6px 18px">
        @forelse($aus as $a)
          <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-top:{{ $loop->first ? '0' : '1px solid #F1F1EC' }}">
            <div style="display:flex;flex-direction:column;min-width:0;flex:1"><span class="ell" style="font-weight:600;font-size:13.5px">{{ $a->trabajador->datos['nombre'] ?? '' }} · {{ $a->tipo }}</span><span style="font:500 12px var(--mono);color:#6A6A65">{{ Formato::fecha($a->desde) }} – {{ Formato::fecha($a->hasta) }}</span><span style="font-size:11.5px;color:{{ ['confirmada' => '#2E3A00', 'descartada' => '#6A6A65'][$a->estado] ?? '#6E4300' }}">{{ ['confirmada' => 'En la nómina', 'descartada' => 'No va a la nómina'][$a->estado] ?? 'Pendiente de revisar en la nómina' }}</span></div>
            @php $nd = (int) $a->desde->diffInDays($a->hasta) + 1; @endphp<span class="mono12">{{ $nd }} {{ $nd === 1 ? 'día' : 'días' }}</span>
            <form method="POST" action="{{ route('horario.ausencia.borrar', $a->id) }}" data-confirm="¿Eliminar esta ausencia?">@csrf @method('DELETE')<button class="lb-del" type="submit" aria-label="Eliminar">×</button></form>
          </div>
        @empty
          <div style="padding:22px 0;text-align:center;font-size:13.5px;color:#6A6A65">No hay ausencias registradas.</div>
        @endforelse
      </div>
      <div class="card">
        <span style="font-size:15px;font-weight:700">Vacaciones {{ $anio }}</span>
        @foreach($vac as $v)
          <div style="display:flex;flex-direction:column;gap:6px">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px"><b>{{ $v['w']['nombre'] }}</b><span style="font:500 12.5px var(--mono);color:#3C3C3C">{{ $v['n'] }} de {{ $v['max'] }} días naturales</span></div>
            <div style="height:8px;border-radius:4px;background:#F1F1EC;overflow:hidden"><div style="height:100%;width:{{ min(100, round($v['n'] / max(1, $v['max']) * 100)) }}%;background:{{ $v['n'] > $v['max'] ? '#D92D20' : '#C8F031' }}"></div></div>
          </div>
        @endforeach
      </div>
    </div>
  @endif
</div>
@endsection
