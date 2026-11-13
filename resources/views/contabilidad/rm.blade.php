@extends('layouts.app')
@php
  use App\Http\Controllers\RegistroMercantilController as RM;
  use App\Support\Formato;
  $y = $ej->anio;
  $leg = $d['leg']['estado'] ?? 'pendiente';
  $d2 = $d['d2']['estado'] ?? 'preparacion';
  $libros = $d['libros'] ?? array_fill(0, count(RM::LIBROS), true);
  $docs = $d['d2docs'] ?? array_fill(0, count(RM::D2), false);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  @unless($soc)
    <div class="card" style="padding:36px 20px;text-align:center;font-size:14px;color:#3C3C3C">{{ $c->nombre }} no es una sociedad: no está obligada a legalizar libros ni a depositar cuentas en el Registro Mercantil.</div>
  @else

  @if($sec === 'plantillas')
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:16px;align-items:start">
      <div class="card" style="padding:18px 20px">
        <span style="font-size:12.5px;color:#6A6A65;padding-bottom:10px">Documentos del ejercicio {{ $y }} rellenados con los datos de {{ $c->nombre }}. Se abren listos para imprimir o guardar en PDF y firmar.</span>
        @foreach(RM::PLANTILLAS as $id => $l)
          <div class="rm-row"><span style="font-size:14px;font-weight:600">{{ $l }}</span>
            <span style="display:flex;align-items:center;gap:8px">@if($g = $d['gen'][$id] ?? null)<span style="font:500 11.5px var(--mono);color:#3C3C3C">Generado {{ Formato::fecha($g) }}</span>@endif
              <a class="conc-btn" href="{{ route('contabilidad.rm.doc', ['doc' => $id, 'ej' => $y]) }}" target="_blank" rel="noopener" style="text-decoration:none;color:inherit">{{ isset($d['gen'][$id]) ? 'Abrir' : 'Generar' }}</a></span>
          </div>
        @endforeach
      </div>
      <form method="POST" action="{{ route('contabilidad.rm.guardar', 'plantillas') }}" class="card" style="padding:18px 20px;gap:12px">
        @csrf<input type="hidden" name="anio" value="{{ $y }}">
        <span style="font-size:14px;font-weight:700">Junta general y aplicación del resultado</span>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
          <label class="lbl-s">Fecha de la junta<input class="inp" type="date" name="junta" value="{{ $d['junta'] ?? '' }}"></label>
          <label class="lbl-s">Junta<select class="sel" name="tipo_junta"><option value="universal">Universal</option><option value="convocada" @selected(($d['tipo_junta'] ?? '') === 'convocada')>Convocada</option></select></label>
          <label class="lbl-s">Acuerdo por<select class="sel" name="acuerdo"><option value="unanimidad">Unanimidad</option><option value="mayoría" @selected(($d['acuerdo'] ?? '') === 'mayoría')>Mayoría</option></select></label>
        </div>
        <div class="boxsoft" style="font-size:13px">Resultado del ejercicio {{ $y }} según la contabilidad: <b class="mono">{{ Formato::eur($res['resultado']) }}</b></div>
        @if($res['resultado'] > 0)
          <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
            <label class="lbl-s">A reserva legal<input class="inp mono" name="reserva_legal" value="{{ isset($d['aplic']['reserva_legal']) ? Formato::num($d['aplic']['reserva_legal']) : '' }}" inputmode="decimal"></label>
            <label class="lbl-s">A reservas voluntarias<input class="inp mono" name="voluntarias" value="{{ isset($d['aplic']['voluntarias']) ? Formato::num($d['aplic']['voluntarias']) : '' }}" inputmode="decimal"></label>
            <label class="lbl-s">A dividendos<input class="inp mono" name="dividendos" value="{{ isset($d['aplic']['dividendos']) ? Formato::num($d['aplic']['dividendos']) : '' }}" inputmode="decimal"></label>
          </div>
          <span class="hint muted">Reserva legal: el 10 % del beneficio hasta que alcance el 20 % del capital (art. 274 LSC). Si la dejas vacía, la propuesta lo lleva todo a reservas voluntarias.</span>
        @else
          <span class="hint muted">Con pérdidas, la propuesta las aplica a «Resultados negativos de ejercicios anteriores».</span>
        @endif
        <button class="btn" type="submit" style="align-self:flex-end;background:#0B0B0B;color:#fff">Guardar</button>
      </form>
    </div>
  @endif

  @if($sec === 'legalia')
    <form method="POST" action="{{ route('contabilidad.rm.guardar', 'legalia') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px;align-items:start">
      @csrf<input type="hidden" name="anio" value="{{ $y }}">
      <div class="card" style="padding:18px 20px;gap:4px">
        <span style="font-size:14px;font-weight:700;padding-bottom:8px">Libros a legalizar · ejercicio {{ $y }}</span>
        @foreach(RM::LIBROS as $i => $l)
          <label class="rm-chk"><input type="checkbox" name="libros[]" value="{{ $i }}" @checked($libros[$i] ?? true) @disabled($leg === 'presentado')><span>{{ $l }}</span>
            @if($i === 0)<a class="hint" style="margin-left:auto" href="{{ route('contabilidad.ldiario', ['ej' => $y]) }}" target="_blank" rel="noopener">Ver e imprimir</a>@endif
            @if($i === 1)<a class="hint" style="margin-left:auto" href="{{ route('contabilidad.anuales', ['ej' => $y]) }}" target="_blank" rel="noopener">Cuentas anuales</a>@endif
          </label>
        @endforeach
        <span class="hint muted" style="padding-top:8px">El libro diario y el de inventarios y cuentas anuales salen de la contabilidad (Listados contables › Listado diario, Balance de sumas y saldos por trimestres y Balances oficiales). Los de actas y socios los aporta la sociedad.</span>
      </div>
      <div style="background:#0B0B0B;color:#F4F4F0;border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:12px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">LEGALIZACIÓN TELEMÁTICA</span>
        <span style="font-size:14px;line-height:1.5;color:#D0D0CA">Plazo: hasta el <strong style="color:#fff">{{ Formato::fecha($plazos['legalia']) }}</strong> (cuatro meses desde el cierre del ejercicio, art. 18 Ley 14/2013).</span>
        @if($leg === 'pendiente')
          <button class="btn lime" name="accion" value="preparado" type="submit" style="align-self:flex-start">Marcar libros preparados</button>
          <span style="font-size:12.5px;color:#BDBDB7">Imprime cada libro en PDF desde los listados y cárgalos en el programa Legalia 2 del Colegio de Registradores, que genera el envío.</span>
        @elseif($leg === 'preparado')
          <span style="font:500 12px var(--mono);color:#C8F031">Preparados el {{ Formato::fecha($d['leg']['fecha']) }}</span>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input class="inp" type="date" name="fecha" value="{{ now()->toDateString() }}" style="height:38px;max-width:170px"><button class="btn white" name="accion" value="presentado" type="submit">Marcar presentado</button></div>
        @else
          <span style="align-self:flex-start;font-size:12.5px;font-weight:700;padding:5px 12px;border-radius:14px;background:#C8F031;color:#0B0B0B">✓ Presentado en el Registro Mercantil el {{ Formato::fecha($d['leg']['fecha']) }}</span>
        @endif
        @if($leg !== 'pendiente')<button class="lnk" name="accion" value="reabrir" type="submit" style="align-self:flex-start;color:#BDBDB7;font-size:12px">Volver a pendiente</button>@endif
        @if($leg === 'pendiente')<button class="lnk" type="submit" style="align-self:flex-start;color:#BDBDB7;font-size:12px">Guardar la selección de libros</button>@endif
      </div>
    </form>
  @endif

  @if($sec === 'd2')
    <form method="POST" action="{{ route('contabilidad.rm.guardar', 'd2') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px;align-items:start">
      @csrf<input type="hidden" name="anio" value="{{ $y }}">
      <div class="card" style="padding:18px 20px;gap:4px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:8px"><span style="font-size:14px;font-weight:700">Documentación del depósito · {{ $y }}</span><span class="mono" style="font-weight:600;font-size:12.5px">{{ count(array_filter($docs)) }}/{{ count(RM::D2) }}</span></div>
        @foreach(RM::D2 as $i => $l)
          <label class="rm-chk"><input type="checkbox" name="docs[]" value="{{ $i }}" @checked($docs[$i] ?? false) @disabled($d2 === 'depositado')><span>{{ $l }}</span>
            @php $link = [0 => route('contabilidad.anuales', ['ej' => $y]), 1 => route('contabilidad.anuales', ['ej' => $y]), 3 => route('contabilidad.rm.doc', ['doc' => 'cert', 'ej' => $y]), 4 => route('contabilidad.rm.doc', ['doc' => 'aplic', 'ej' => $y]), 6 => route('contabilidad.rm.doc', ['doc' => 'titular', 'ej' => $y])][$i] ?? null; @endphp
            @if($link)<a class="hint" style="margin-left:auto" href="{{ $link }}" target="_blank" rel="noopener">Abrir</a>@endif
          </label>
        @endforeach
        @if($d2 !== 'depositado')<button class="lnk" type="submit" style="align-self:flex-start;font-size:12.5px;padding-top:8px">Guardar lo marcado</button>@endif
      </div>
      <div style="background:#C8F031;border-radius:18px;padding:18px 20px;display:flex;flex-direction:column;gap:12px">
        <span style="font-size:15px;font-weight:700">Plazos</span>
        <span style="font-size:13.5px;line-height:1.5">Aprobación en junta: hasta el <strong>{{ Formato::fecha($plazos['aprob']) }}</strong> (art. 164 LSC).<br>Depósito en el Registro Mercantil: hasta el <strong>{{ Formato::fecha($plazos['deposito']) }}</strong> (un mes desde la aprobación, art. 279 LSC).</span>
        @if($d2 === 'depositado')
          <span style="align-self:flex-start;font-size:12.5px;font-weight:700;padding:5px 12px;border-radius:14px;background:#0B0B0B;color:#C8F031">✓ Cuentas depositadas el {{ Formato::fecha($d['d2']['fecha']) }}</span>
          <button class="lnk" name="accion" value="reabrir" type="submit" style="align-self:flex-start;font-size:12px">Volver a preparación</button>
        @else
          <span style="font-size:13px">Con todo marcado, genera el depósito con el programa D2 del Colegio de Registradores (o en su sede electrónica) usando estos documentos, y márcalo aquí.</span>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input class="inp" type="date" name="fecha" value="{{ now()->toDateString() }}" style="height:38px;max-width:170px"><button class="btn" name="accion" value="depositado" type="submit" style="background:#0B0B0B;color:#fff">Marcar depositadas</button></div>
        @endif
      </div>
    </form>
  @endif
  @endunless
</div>
@endsection
