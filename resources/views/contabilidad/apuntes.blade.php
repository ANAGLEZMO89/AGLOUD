@extends('layouts.app')
@php
  use App\Http\Controllers\ContabilidadController as CC;
  use App\Services\Contabilidad;
  use App\Support\Formato;
  $tipo = in_array($tipo, ['Manual', 'Factura', 'Nómina'], true) && ! $a ? $tipo : 'Manual';
  $propias = Contabilidad::propias($c);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <datalist id="ctas">@foreach($catalogo as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</datalist>
  @if($ej->cerrado())
    <div class="card"><div class="empty"><b>Ejercicio cerrado</b><span>No admite asientos nuevos. Cambia al ejercicio abierto.</span></div></div>
  @else
  <form method="POST" action="{{ route('contabilidad.apuntes.guardar') }}" enctype="multipart/form-data" data-asiento data-cuentas='@json($catalogo)' data-cfg='@json($cfg)' data-plantillas='@json(CC::PLANTILLAS)' data-nomina='@json(CC::NOMINA)' data-terceros='@json($terceros)' style="display:flex;flex-direction:column;gap:14px">
    @csrf
    @if($a)<input type="hidden" name="editar" value="{{ $a->id }}">@endif
    <input type="hidden" name="tipo" value="{{ $tipo }}" data-ap-tipo>
    <input type="hidden" name="concepto" value="{{ old('concepto', $a?->concepto) }}" data-ap-conc-final>

    <div class="card" style="padding:14px 18px;flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <a class="ap-btn" href="{{ route('contabilidad.apuntes') }}">+ Nuevo</a>
        <button class="ap-btn ink" type="submit"><span class="dot"></span>Guardar</button>
        @if($a)<span class="ap-badge warn">EDITANDO ASIENTO {{ $a->numero }}</span>@else<span class="ap-badge">NUEVO</span>@endif
      </div>
      <span style="font-size:15px;font-weight:700">Asientos contables</span>
    </div>
    @if($a && ! $a->origen)
      <div style="display:flex;justify-content:flex-end;margin-top:-6px"><button class="lnk red" type="submit" form="ap-borrar" style="font-size:12.5px">Eliminar asiento {{ $a->numero }}</button></div>
    @endif
    @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif

    <div class="card" style="padding:16px 18px;flex-direction:row;flex-wrap:wrap;align-items:flex-end;gap:14px">
      <label class="lbl-s">Fecha<input class="inp" type="date" name="fecha" value="{{ old('fecha', $a?->fecha?->toDateString() ?? ($ej->anio == now()->year ? now()->toDateString() : $ej->anio.'-01-01')) }}" min="{{ $ej->anio }}-01-01" max="{{ $ej->anio }}-12-31" required data-ap-fecha></label>
      <div style="display:flex;flex-direction:column;gap:6px"><span class="flbl" style="font-size:12.5px;font-weight:600">Tipo</span>
        <div class="ap-seg" role="tablist">@foreach(['Manual', 'Factura', 'Nómina'] as $t)<button type="button" class="{{ $tipo === $t ? 'on' : '' }}" data-ap-set="{{ $t }}" @disabled($a && $t !== 'Manual')>{{ $t }}</button>@endforeach</div>
      </div>
      <label class="lbl-s" data-ap-only="Manual" style="min-width:230px">Plantillas predefinidas
        <select class="sel" data-ap-plantilla><option value="">Elige una plantilla…</option>@foreach(CC::PLANTILLAS as $i => [$l])<option value="{{ $i }}">{{ $l }}</option>@endforeach</select>
      </label>
      <label class="lbl-s" data-ap-only="Manual" style="flex:1;min-width:220px">Concepto del asiento<input class="inp" value="{{ old('concepto', $a?->concepto) }}" maxlength="255" placeholder="Ej. Pago de la cuota de autónomos" data-ap-conc></label>
    </div>

    {{-- ===== Manual ===== --}}
    <div class="card" style="padding:16px 18px;gap:12px" data-ap-only="Manual">
      <div style="overflow-x:auto">
        <div class="ap9 ap9-head"><span>#</span><span>Cuenta contable</span><span>Concepto</span><span style="text-align:right">Debe</span><span style="text-align:right">Haber</span><span>Documento</span><span></span></div>
        <div data-ap-lines>
          @foreach($lineas as $i => $l)
            <div class="ap9" data-ap-line>
              <span class="mono ap-n" style="font-size:12px;color:#6A6A65">{{ $i + 1 }}</span>
              <span style="display:flex;align-items:center;gap:8px;min-width:0"><input class="inp mono" name="l[{{ $i }}][c]" value="{{ $l['c'] }}" list="ctas" maxlength="12" data-k="c" placeholder="000000000" style="width:118px;flex:none"><span class="ell" style="font-size:12.5px;color:#3C3C3C" data-ap-nom></span></span>
              <input class="inp" name="l[{{ $i }}][t]" value="{{ $l['t'] }}" maxlength="255" data-k="t" placeholder="Igual que el asiento">
              <input class="inp mono" name="l[{{ $i }}][d]" value="{{ $l['d'] }}" inputmode="decimal" data-k="d" placeholder="0,00" style="text-align:right">
              <input class="inp mono" name="l[{{ $i }}][h]" value="{{ $l['h'] }}" inputmode="decimal" data-k="h" placeholder="0,00" style="text-align:right">
              <input class="inp mono" name="l[{{ $i }}][doc]" value="{{ $l['doc'] ?? '' }}" maxlength="40" data-k="doc" placeholder="Nº doc." style="font-size:12.5px">
              <button type="button" class="ap-x" data-ap-del aria-label="Quitar">×</button>
            </div>
          @endforeach
        </div>
        <template data-ap-tpl><div class="ap9" data-ap-line><span class="mono ap-n" style="font-size:12px;color:#6A6A65"></span><span style="display:flex;align-items:center;gap:8px;min-width:0"><input class="inp mono" list="ctas" maxlength="12" data-k="c" placeholder="000000000" style="width:118px;flex:none"><span class="ell" style="font-size:12.5px;color:#3C3C3C" data-ap-nom></span></span><input class="inp" maxlength="255" data-k="t" placeholder="Igual que el asiento"><input class="inp mono" inputmode="decimal" data-k="d" placeholder="0,00" style="text-align:right"><input class="inp mono" inputmode="decimal" data-k="h" placeholder="0,00" style="text-align:right"><input class="inp mono" maxlength="40" data-k="doc" placeholder="Nº doc." style="font-size:12.5px"><button type="button" class="ap-x" data-ap-del aria-label="Quitar">×</button></div></template>
        <div style="padding:10px 4px"><button type="button" class="lnk" style="font-size:13px;font-weight:600" data-ap-add>+ Añadir apunte</button></div>
      </div>
      <span class="hint muted">Las cuentas son siempre de 9 dígitos. Escribe 572.1 y al salir del campo se completa como 572000001; 4751 se completa como 475100000. Pulsa «=» en un importe para poner la diferencia que falta para cuadrar.</span>
    </div>

    {{-- ===== Factura ===== --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;align-items:start" data-ap-only="Factura" hidden>
      <div class="card" style="padding:18px 20px;gap:14px" data-fac>
        <div class="ap-seg" style="align-self:flex-start">@foreach(['Recibida', 'Emitida'] as $t)<button type="button" class="{{ $t === 'Recibida' ? 'on' : '' }}" data-fac-clase="{{ $t }}">{{ $t }}</button>@endforeach</div>
        <label class="lbl-s">Cuenta del tercero<span style="display:flex;align-items:center;gap:10px;min-width:0"><input class="inp mono" list="ctas" data-f="ter" placeholder="410000001" style="width:130px;flex:none"><span class="ell" style="font-size:12.5px;color:#3C3C3C" data-f-nom="ter"></span></span></label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,140px),1fr));gap:12px">
          <label class="lbl-s">NIF<input class="inp mono upper" data-f="nif" maxlength="15"></label>
          <label class="lbl-s">Nº de factura<input class="inp mono" data-f="num" maxlength="40"></label>
          <label class="lbl-s">Fecha de la factura<input class="inp" type="date" data-f="ffecha"></label>
          <label class="lbl-s">Base imponible<input class="inp mono" data-f="base" inputmode="decimal" placeholder="0,00" style="text-align:right"></label>
          <label class="lbl-s">IVA<select class="sel" data-f="iva"><option value="21">21 %</option><option value="10">10 %</option><option value="5">5 %</option><option value="4">4 %</option><option value="0">Exento</option></select></label>
          <label class="lbl-s">Retención IRPF<select class="sel" data-f="ret"><option value="0">Sin retención</option><option value="1">1 %</option><option value="7">7 %</option><option value="15">15 %</option><option value="19">19 %</option></select></label>
        </div>
        <label class="lbl-s">Cuenta de gasto o ingreso<span style="display:flex;align-items:center;gap:10px;min-width:0"><input class="inp mono" list="ctas" data-f="cta" style="width:130px;flex:none"><span class="ell" style="font-size:12.5px;color:#3C3C3C" data-f-nom="cta"></span></span></label>
        <label class="chk" style="gap:8px"><input type="checkbox" data-f="nodeduc" style="accent-color:#0B0B0B">IVA no deducible (se suma al gasto)</label>
        <div class="ap-tot"><div><span>Base</span><b data-ft="base">0,00</b></div><div><span>IVA</span><b data-ft="iva">0,00</b></div><div><span>Retención</span><b data-ft="ret">0,00</b></div><div><span>Total</span><b data-ft="tot">0,00</b></div></div>
      </div>
      <label class="card ap-drop" style="padding:18px;gap:12px;cursor:pointer" data-ocr-drop="factura">
        <input type="file" name="archivo" accept=".pdf,image/*" hidden data-ocr-file>
        <div class="ap-dz">
          <span style="font-size:15px;font-weight:700" data-dz-t>Arrastra aquí la factura</span>
          <span style="font-size:13px;color:#6A6A65" data-dz-s>PDF o imagen · o haz clic para elegir el archivo</span>
          <span style="font:500 11px var(--mono);color:#6A6A65">El OCR rellena los datos; puedes corregirlos a mano</span>
        </div>
        <span style="font-size:12.5px;color:#6A6A65">Se guarda como asiento de tipo Factura y el archivo queda en Documentos.</span>
      </label>
    </div>

    {{-- ===== Nómina ===== --}}
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start" data-ap-only="Nómina" hidden>
      <div class="card" style="flex:999 1 560px;min-width:0;padding:16px 18px;overflow-x:auto" data-nom>
        <div class="nom-row" style="padding:6px 0 12px"><span style="font-size:12.5px;font-weight:600">Concepto</span><input class="inp" data-nom-conc value="Nóminas" style="grid-column:span 3"></div>
        <div class="nom-row ap9-head" style="border-bottom:1px solid #EDEDE8"><span>Partida</span><span style="text-align:right">Importe</span><span>Cta. contable</span><span></span></div>
        @foreach(CC::NOMINA as [$k, $l, $par, $lado])
          <div class="nom-row" data-nom-row="{{ $k }}">
            <span style="font-size:13.5px">{{ $l }}</span>
            @if($k === 'neto')<span class="nom-auto mono" data-nom-neto>0,00</span>@else<input class="inp mono" data-nom-v="{{ $k }}" inputmode="decimal" placeholder="0,00" style="text-align:right">@endif
            <input class="inp mono" list="ctas" data-nom-c="{{ $k }}" value="{{ $cfg['nomina'][$k] ?? '' }}" placeholder="Sin cuenta">
            <span class="ell" style="font-size:12.5px;color:#3C3C3C" data-nom-n="{{ $k }}"></span>
          </div>
        @endforeach
        <span class="hint muted" style="padding-top:10px">Las cuentas salen de Parámetros › Cuentas laborales. El salario neto se calcula solo.</span>
      </div>
      <label class="card ap-drop" style="flex:1 1 260px;padding:18px;gap:12px;cursor:pointer" data-ocr-drop="nomina">
        <span style="font-size:14px;font-weight:700">Importar desde nóminas</span>
        <input type="file" name="archivo" accept=".pdf,image/*" hidden data-ocr-file disabled>
        <div class="ap-dz" style="min-height:200px">
          <span style="font-size:15px;font-weight:700" data-dz-t>Arrastra aquí la nómina</span>
          <span style="font-size:13px;color:#6A6A65" data-dz-s>PDF o imagen · o haz clic para elegirla</span>
        </div>
        <span style="font-size:12.5px;color:#6A6A65">Se guarda como asiento de tipo Nómina y la nómina queda en Documentos.</span>
      </label>
    </div>

    <div data-ap-gen hidden></div>

    {{-- ===== Asiento haciéndose / últimos ===== --}}
    <div class="card" style="padding:14px 18px;gap:12px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="ap-seg soft">
          <button type="button" class="on" data-ap-vt="prev">{{ $guardado ? 'Asiento guardado' : 'Vista previa del asiento' }}</button>
          <button type="button" data-ap-vt="ult">Últimos asientos</button>
        </div>
        <span data-ap-cuadre></span>
      </div>
      <div data-ap-v="prev">
        @if($guardado)
          <div class="ap-prev saved">
            <div class="ap-prev-h">✓ ASIENTO {{ $guardado->numero }} GUARDADO · {{ Formato::fecha($guardado->fecha) }} · {{ mb_strtoupper($guardado->concepto) }}</div>
            @foreach($guardado->apuntes as $p)<div class="ap-prev-r"><span>{{ $p->cuenta }}</span><span class="ell">{{ Contabilidad::nombre($p->cuenta, $propias) }}</span><span>{{ (float) $p->debe ? Formato::num($p->debe) : '' }}</span><span>{{ (float) $p->haber ? Formato::num($p->haber) : '' }}</span></div>@endforeach
            <div class="ap-prev-r tot"><span></span><span>Totales</span><span>{{ Formato::num($guardado->apuntes->sum('debe')) }}</span><span>{{ Formato::num($guardado->apuntes->sum('haber')) }}</span></div>
            <div style="padding-top:8px;font-family:inherit;font-size:12.5px"><a href="{{ route('contabilidad.diario', ['q' => $guardado->numero]) }}">Ver en el libro diario</a> · <a href="{{ route('contabilidad.apuntes', ['editar' => $guardado->id]) }}">Editar</a></div>
          </div>
        @endif
        <div class="ap-prev" data-ap-prev>
          <div class="ap-prev-h" data-ap-prev-h>ASIENTO NUEVO</div>
          <div data-ap-prev-rows><div style="text-align:center;font-size:12.5px;color:#6A6A65;padding:10px 0">Los apuntes aparecerán aquí a medida que los introduzcas.</div></div>
          <div class="ap-prev-r tot"><span></span><span>Totales</span><span data-ap-sd>0,00</span><span data-ap-sh>0,00</span></div>
        </div>
        <span class="hint muted" data-ap-hint style="display:block;padding-top:6px"></span>
      </div>
      <div data-ap-v="ult" hidden>
        @forelse($ultimos as $u)
          <a class="ap-ult" href="{{ route('contabilidad.apuntes', ['editar' => $u->id]) }}"><span class="mono" style="font-weight:600">{{ $u->numero }}</span><span class="mono" style="font-size:12px;color:#3C3C3C">{{ Formato::fecha($u->fecha) }}</span><span class="ell">{{ $u->concepto }}</span><span style="font-size:11.5px;color:#6A6A65">{{ $u->tipo }}</span><span class="mono" style="text-align:right">{{ Formato::num($u->apuntes->sum('debe')) }}</span></a>
        @empty
          <span style="font-size:13px;color:#6A6A65;padding:12px 0;display:block">Todavía no hay asientos en {{ $ej->anio }}.</span>
        @endforelse
      </div>
    </div>
  </form>
  @if($a && ! $a->origen)<form id="ap-borrar" method="POST" action="{{ route('contabilidad.borrar', $a) }}" data-confirm="¿Eliminar el asiento {{ $a->numero }}?">@csrf @method('DELETE')</form>@endif
  @endif
</div>
<script src="{{ asset('js/ocr.js') }}?v={{ config('app.version', '1') }}"></script>
<script src="{{ asset('js/apuntes.js') }}?v={{ config('app.version', '1') }}"></script>
@endsection
