@extends('layouts.app')
@php
  use App\Models\ParametroNormativo;
  use App\Support\Formato;
  $N = fn ($v, $d = 2) => $v === null ? '' : Formato::num((float) $v, $d);
  $Pc = fn ($v) => $v === null ? '' : Formato::num((float) $v * 100, 2);
  $col = ['pendiente' => ['#FCEBCB', '#6E4300'], 'resumen' => ['#E3ECFF', '#1D3A8A'], 'verificado' => ['#E6F6A8', '#2E3A00']];
  $TIPOS = ['cc' => 'Contingencias comunes', 'mei' => 'MEI', 'des_ind' => 'Desempleo (indefinido)', 'des_tmp' => 'Desempleo (duración determinada)', 'fp' => 'Formación profesional', 'fog' => 'FOGASA'];
  $o = fn ($k, $def) => old($k, $def);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  <div class="card" style="gap:6px">
    <span style="font-size:15px;font-weight:700">Parámetros normativos de nómina</span>
    <span style="font-size:13px;color:#3C3C3C;line-height:1.5">Bases y tipos de cotización, SMI, retenciones de IRPF y prestación de IT con su periodo de vigencia y la norma que los fija. Cada nómina usa los vigentes en su periodo; las ya emitidas conservan los suyos. Son comunes a todas las empresas del despacho: solo la administración puede cambiarlos.</span>
    <span style="font-size:12.5px;color:#6A6A65">Estados: <b>Pendiente de verificar</b> (copiados o cambiados sin comprobar) · <b>Contrastado con resúmenes</b> (coinciden con varios resúmenes publicados de la norma, sin cotejar con el BOE) · <b>Verificado en el BOE</b> (alguien del despacho lo ha comprobado en la norma).</span>
  </div>
  @if($errors->any())<div class="ferr" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif

  @foreach(ParametroNormativo::CLAVES as $clave => $titulo)
    <div class="card" style="gap:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:15px;font-weight:700">{{ $titulo }}</span>
        @if($admin)
          <form method="POST" action="{{ route('laboral.normativa.nuevo') }}" style="display:flex;gap:6px;align-items:flex-end">@csrf<input type="hidden" name="clave" value="{{ $clave }}">
            <label class="lbl-s">Nuevo periodo desde<input class="inp" type="date" name="desde" required></label><button class="btn soft sm" type="submit">Crear</button></form>
        @endif
      </div>
      @forelse($params[$clave] ?? [] as $p)
        @php $v = $p->valor; [$bg, $fg] = $col[$p->estado] ?? $col['pendiente']; $editando = $admin && $edit === $p->id; @endphp
        <div style="display:flex;flex-direction:column;gap:6px;padding-top:10px;border-top:1px solid #F1F1EC">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <b class="mono12">{{ Formato::fecha($p->desde) }} – {{ $p->hasta ? Formato::fecha($p->hasta) : 'en adelante' }}</b>
            <span class="lb-pill" style="background:{{ $bg }};color:{{ $fg }}">{{ ParametroNormativo::ESTADOS[$p->estado] ?? $p->estado }}{{ $p->verificado_at ? ' · '.Formato::fecha($p->verificado_at) : '' }}</span>
            @if($admin && ! $editando)<a class="btn white sm" href="{{ route('laboral.normativa', ['editar' => $p->id]) }}">Editar o verificar</a>@endif
          </div>
          <span style="font-size:12.5px;color:#3C3C3C"><b>Fuente:</b> {{ $p->fuente }}</span>
          @if($p->notas)<span style="font-size:12px;color:#6A6A65">{{ $p->notas }}</span>@endif
          @unless($editando)
            <div style="font-size:13px;line-height:1.55">
              @if($clave === 'cotizacion')
                Base máxima {{ Formato::eur($v['base_max']) }} · mínimas: @foreach($v['base_min'] as $g => $b)grupo {{ $g }} {{ Formato::eur($b) }}@if(! $loop->last), @endif @endforeach · grupos 8 a 11: {{ Formato::eur(reset($v['base_min_dia'])) }} al día.<br>
                Tipos (trabajador + empresa): @foreach($v['tipos'] as $k => [$t, $e]){{ $TIPOS[$k] }} {{ $Pc($t) }} % + {{ $Pc($e) }} %@if(! $loop->last) · @endif @endforeach.<br>
                Solidaridad: {{ collect($v['solidaridad'] ?? [])->map(fn ($x) => ($x[0] === null ? 'más del 50 %' : 'hasta el '.$N($x[0] * 100, 0).' %').' del exceso: '.$N($x[1]).' %')->implode(' · ') }}.
                @isset($v['temporal_corto'])<br>Contratos de duración determinada inferiores a 30 días: {{ Formato::eur($v['temporal_corto']) }} a cargo de la empresa al terminar.@endisset
              @elseif($clave === 'smi')
                {{ Formato::eur($v['anual']) }} al año · {{ Formato::eur($v['mensual']) }} al mes (14 pagas) · {{ Formato::eur($v['diario']) }} al día.
              @elseif($clave === 'irpf')
                Escala: {{ collect($v['escala'])->map(fn ($x) => ($x[0] === null ? 'resto' : 'hasta '.Formato::eur($x[0])).' al '.$N($x[1], 0).' %')->implode(' · ') }}.<br>
                Límites excluyentes (sin hijos / 1 / 2 o más): @foreach($v['limites'] as $s => $l)situación {{ $s }}: {{ implode(' / ', array_map(fn ($x) => Formato::eur($x, 0), $l)) }}@if(! $loop->last) · @endif @endforeach.<br>
                Reducción por rendimientos del trabajo: {{ Formato::eur($v['reduccion']['max']) }} hasta {{ Formato::eur($v['reduccion']['r1']) }}; se anula a partir de {{ Formato::eur($v['reduccion']['r3']) }}. Tipo mínimo en contratos inferiores a un año: {{ $N($v['tipo_min_temporal'], 0) }} %.
                @isset($v['minimos'])<br>Mínimos: personal {{ Formato::eur($v['minimos']['personal']) }} (+{{ Formato::eur($v['minimos']['edad65']) }} desde 65 años, +{{ Formato::eur($v['minimos']['edad75']) }} desde 75) · descendientes {{ implode(' / ', array_map(fn ($x) => Formato::eur($x), $v['minimos']['desc'])) }} (+{{ Formato::eur($v['minimos']['desc3']) }} menores de 3 años) · ascendientes {{ Formato::eur($v['minimos']['asc65']) }} (+{{ Formato::eur($v['minimos']['asc75']) }} desde 75) · discapacidad {{ Formato::eur($v['minimos']['disc33']) }} / {{ Formato::eur($v['minimos']['disc65']) }} y asistencia {{ Formato::eur($v['minimos']['asistencia']) }}.<br>
                Gastos: {{ Formato::eur($v['gastos']['general']) }} (+{{ Formato::eur($v['gastos']['movilidad']) }} por movilidad geográfica; discapacidad {{ Formato::eur($v['gastos']['disc33']) }} / {{ Formato::eur($v['gastos']['disc65']) }}) · límite del {{ $N($v['limite43']['pct'], 0) }} % hasta {{ Formato::eur($v['limite43']['hasta']) }} de retribución · préstamo de vivienda: {{ $N($v['vivienda']['pct'], 0) }} % si la retribución es inferior a {{ Formato::eur($v['vivienda']['limite']) }}.@endisset
              @elseif($clave === 'atep')
                @php $nc = count($v['cnae'] ?? []); $no = count($v['ocupaciones'] ?? []); @endphp
                @if(! $nc && ! $no)<span style="color:#8A1C1C">Sin cargar: mientras falte, cada empresa usa el tipo de AT y EP indicado a mano (Parámetros laboral y CCC).</span>
                @else {{ $nc }} códigos CNAE y {{ $no }} ocupaciones.
                  <details><summary style="cursor:pointer">Ver la tarifa</summary><div style="max-height:260px;overflow:auto;font-size:12px;margin-top:6px">@foreach(($v['ocupaciones'] ?? []) as $k => $x)<div>Ocupación {{ $k }} · {{ $x[0] }}: IT {{ $N($x[1]) }} + IMS {{ $N($x[2]) }} = {{ $N($x[1] + $x[2]) }} %</div>@endforeach @foreach(($v['cnae'] ?? []) as $k => $x)<div>CNAE {{ $k }} · {{ $x[0] }}: IT {{ $N($x[1]) }} + IMS {{ $N($x[2]) }} = {{ $N($x[1] + $x[2]) }} %</div>@endforeach</div></details>
                @endif
              @else
                Contingencias comunes: {{ collect($v['cc'])->map(fn ($x) => ($x[1] === null ? 'desde el día '.$x[0] : 'días '.$x[0].'-'.$x[1]).' al '.$N($x[2], 0).' % ('.($x[3] === 'empresa' ? 'a cargo de la empresa' : 'pago delegado').')')->implode(' · ') }} · profesionales: {{ $N($v['at'], 0) }} % desde el día siguiente a la baja.
              @endif
            </div>
          @else
            <form method="POST" action="{{ route('laboral.normativa.guardar', $p->id) }}" style="display:flex;flex-direction:column;gap:10px" data-un-envio>@csrf
              @if($clave === 'cotizacion')
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  <label class="lbl-s">Base máxima (€/mes)<input class="inp mono" name="v[base_max]" value="{{ $o('v.base_max', $N($v['base_max'])) }}"></label>
                  @foreach(range(1, 7) as $g)<label class="lbl-s">Mínima grupo {{ $g }}<input class="inp mono" name="v[base_min][{{ $g }}]" value="{{ $o("v.base_min.$g", $N($v['base_min'][$g] ?? null)) }}" style="width:110px"></label>@endforeach
                  @foreach(range(8, 11) as $g)<label class="lbl-s">Grupo {{ $g }} (€/día)<input class="inp mono" name="v[base_min_dia][{{ $g }}]" value="{{ $o("v.base_min_dia.$g", $N($v['base_min_dia'][$g] ?? null)) }}" style="width:110px"></label>@endforeach
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:8px">
                  @foreach($TIPOS as $k => $l)
                    <div style="display:flex;gap:6px;align-items:flex-end"><span style="flex:1;font-size:12.5px">{{ $l }} (%)</span>
                      <label class="lbl-s">Trabajador<input class="inp mono" name="v[tipos][{{ $k }}][0]" value="{{ $o("v.tipos.$k.0", $Pc($v['tipos'][$k][0] ?? null)) }}" style="width:80px"></label>
                      <label class="lbl-s">Empresa<input class="inp mono" name="v[tipos][{{ $k }}][1]" value="{{ $o("v.tipos.$k.1", $Pc($v['tipos'][$k][1] ?? null)) }}" style="width:80px"></label></div>
                  @endforeach
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(['Solidaridad: exceso hasta el 10 % (%)', 'del 10 % al 50 % (%)', 'más del 50 % (%)'] as $i => $l)<label class="lbl-s">{{ $l }}<input class="inp mono" name="v[sol][{{ $i }}]" value="{{ $o("v.sol.$i", $N($v['solidaridad'][$i][1] ?? null)) }}" style="width:110px"></label>@endforeach
                  <label class="lbl-s">Contratos de menos de 30 días (€)<input class="inp mono" name="v[temporal_corto]" value="{{ $o('v.temporal_corto', $N($v['temporal_corto'] ?? null)) }}" style="width:110px"></label>
                </div>
              @elseif($clave === 'smi')
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(['anual' => 'Anual (€)', 'mensual' => 'Mensual en 14 pagas (€)', 'diario' => 'Diario (€)'] as $k => $l)<label class="lbl-s">{{ $l }}<input class="inp mono" name="v[{{ $k }}]" value="{{ $o("v.$k", $N($v[$k] ?? null)) }}"></label>@endforeach
                </div>
              @elseif($clave === 'irpf')
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(range(0, 5) as $i)
                    <span style="display:flex;gap:4px;align-items:flex-end">@if($i < 5)<label class="lbl-s">Tramo {{ $i + 1 }} hasta (€)<input class="inp mono" name="v[escala][{{ $i }}][0]" value="{{ $o("v.escala.$i.0", $N($v['escala'][$i][0] ?? null)) }}" style="width:100px"></label>@endif
                      <label class="lbl-s">{{ $i < 5 ? 'Tipo' : 'Resto: tipo' }} (%)<input class="inp mono" name="v[escala][{{ $i }}][1]" value="{{ $o("v.escala.$i.1", $N($v['escala'][$i][1] ?? null)) }}" style="width:70px"></label></span>
                  @endforeach
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(['1', '2', '3'] as $s)@foreach(['sin hijos', '1 hijo', '2 o más'] as $h => $lh)<label class="lbl-s">Situación {{ $s }} · {{ $lh }}<input class="inp mono" name="v[limites][{{ $s }}][{{ $h }}]" value="{{ $o("v.limites.$s.$h", $N($v['limites'][$s][$h] ?? null)) }}" style="width:110px"></label>@endforeach @endforeach
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(['max' => 'Reducción máxima', 'r1' => 'Hasta rendimiento', 'f1' => 'Coeficiente 1', 'r2' => 'Segundo umbral', 'b2' => 'Reducción en el umbral 2', 'f2' => 'Coeficiente 2', 'r3' => 'Rendimiento sin reducción'] as $k => $l)<label class="lbl-s">{{ $l }}<input class="inp mono" name="v[reduccion][{{ $k }}]" value="{{ $o("v.reduccion.$k", $N($v['reduccion'][$k] ?? null)) }}" style="width:120px"></label>@endforeach
                  <label class="lbl-s">Tipo mínimo temporales (%)<input class="inp mono" name="v[tipo_min_temporal]" value="{{ $o('v.tipo_min_temporal', $N($v['tipo_min_temporal'] ?? null)) }}" style="width:90px"></label>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(['personal' => 'Mínimo personal', 'edad65' => '+ desde 65 años', 'edad75' => '+ desde 75 años', 'desc3' => '+ descendiente menor de 3', 'asc65' => 'Ascendiente', 'asc75' => '+ ascendiente desde 75', 'disc33' => 'Discapacidad 33-64 %', 'disc65' => 'Discapacidad 65 % o más', 'asistencia' => 'Asistencia'] as $k => $l)<label class="lbl-s">{{ $l }}<input class="inp mono" name="v[minimos][{{ $k }}]" value="{{ $o("v.minimos.$k", $N($v['minimos'][$k] ?? null)) }}" style="width:110px"></label>@endforeach
                  @foreach(range(0, 3) as $i)<label class="lbl-s">Descendiente {{ $i < 3 ? $i + 1 : '4 y siguientes' }}<input class="inp mono" name="v[minimos][desc][{{ $i }}]" value="{{ $o("v.minimos.desc.$i", $N($v['minimos']['desc'][$i] ?? null)) }}" style="width:110px"></label>@endforeach
                  @foreach(['general' => 'Gastos generales', 'movilidad' => '+ movilidad geográfica', 'disc33' => '+ discapacidad 33-64 %', 'disc65' => '+ discapacidad 65 % o movilidad reducida'] as $k => $l)<label class="lbl-s">{{ $l }}<input class="inp mono" name="v[gastos][{{ $k }}]" value="{{ $o("v.gastos.$k", $N($v['gastos'][$k] ?? null)) }}" style="width:110px"></label>@endforeach
                  <label class="lbl-s">Límite 43 %: hasta retribución<input class="inp mono" name="v[limite43][hasta]" value="{{ $o('v.limite43.hasta', $N($v['limite43']['hasta'] ?? null)) }}" style="width:110px"></label>
                  <label class="lbl-s">Porcentaje<input class="inp mono" name="v[limite43][pct]" value="{{ $o('v.limite43.pct', $N($v['limite43']['pct'] ?? null)) }}" style="width:70px"></label>
                  <label class="lbl-s">Vivienda: retribución inferior a<input class="inp mono" name="v[vivienda][limite]" value="{{ $o('v.vivienda.limite', $N($v['vivienda']['limite'] ?? null)) }}" style="width:110px"></label>
                  <label class="lbl-s">Porcentaje<input class="inp mono" name="v[vivienda][pct]" value="{{ $o('v.vivienda.pct', $N($v['vivienda']['pct'] ?? null)) }}" style="width:70px"></label>
                </div>
              @elseif($clave === 'atep')
                <label class="lbl-s">Tabla de la tarifa: pega las filas del BOE (cuadro I por CNAE y cuadro II por ocupación), una por línea: código, descripción, IT, IMS y total
                  <textarea class="txa mono" name="tabla" rows="10" placeholder="0111 Cultivo de cereales 1,50 1,10 2,60&#10;a Personal en trabajos exclusivos de oficina 0,65 0,85 1,50" style="font-size:12px">{{ old('tabla') }}</textarea></label>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                  <label class="chk" style="font-weight:500"><input type="radio" name="modo" value="anadir" checked> Añadir o actualizar las filas pegadas</label>
                  <label class="chk" style="font-weight:500"><input type="radio" name="modo" value="sustituir"> Sustituir toda la tarifa por la pegada</label>
                </div>
                <span style="font-size:12px;color:#6A6A65">Se comprueba que IT + IMS sume el total de cada fila; si alguna fila no se entiende, no se guarda nada y se te dice cuál. El ejemplo del recuadro solo muestra el formato: no son cifras oficiales.</span>
              @else
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                  @foreach(range(0, 2) as $i)
                    <span style="display:flex;gap:4px;align-items:flex-end;padding:6px;border:1px solid #E4E4DE;border-radius:9px">
                      <label class="lbl-s">Desde día<input class="inp mono" name="v[cc][{{ $i }}][0]" value="{{ $o("v.cc.$i.0", $v['cc'][$i][0] ?? '') }}" style="width:60px"></label>
                      <label class="lbl-s">Hasta día<input class="inp mono" name="v[cc][{{ $i }}][1]" value="{{ $o("v.cc.$i.1", $v['cc'][$i][1] ?? '') }}" style="width:60px"></label>
                      <label class="lbl-s">%<input class="inp mono" name="v[cc][{{ $i }}][2]" value="{{ $o("v.cc.$i.2", $N($v['cc'][$i][2] ?? null, 0)) }}" style="width:60px"></label>
                      <label class="lbl-s">A cargo de<select class="sel" name="v[cc][{{ $i }}][3]"><option value="empresa" @selected(($v['cc'][$i][3] ?? '') === 'empresa')>Empresa</option><option value="delegado" @selected(($v['cc'][$i][3] ?? '') !== 'empresa')>Pago delegado</option></select></label></span>
                  @endforeach
                  <label class="lbl-s">Contingencias profesionales (%)<input class="inp mono" name="v[at]" value="{{ $o('v.at', $N($v['at'] ?? null, 0)) }}" style="width:90px"></label>
                </div>
              @endif
              <label class="lbl-s">Norma que lo fija (BOE, número y fecha)<input class="inp" name="fuente" value="{{ $o('fuente', $p->fuente) }}" maxlength="500"></label>
              <label class="lbl-s">Notas<input class="inp" name="notas" value="{{ $o('notas', $p->notas) }}" maxlength="2000"></label>
              <label class="chk"><input type="checkbox" name="verificado" value="1"> He comprobado estas cifras en el BOE (se marca como verificado con mi nombre y la fecha)</label>
              <div style="display:flex;gap:8px;justify-content:flex-end"><a class="btn white" href="{{ route('laboral.normativa') }}">Cancelar</a><button class="btn lime" type="submit">Guardar</button></div>
            </form>
          @endunless
          @if($p->editor)<span style="font-size:11.5px;color:#6A6A65">Último cambio: {{ $p->editor->name }} · {{ Formato::fechaHora($p->updated_at) }}</span>@endif
        </div>
      @empty
        <span class="ferr">No hay parámetros cargados: las nóminas no se pueden calcular.</span>
      @endforelse
    </div>
  @endforeach
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
