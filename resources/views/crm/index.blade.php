@extends('layouts.app')
@php
    use App\Http\Controllers\FacturaController as FC;
    use App\Models\Actividad;
    use App\Models\Oportunidad;
    use App\Models\Presupuesto;
    use App\Support\Formato;
    $etapas = array_keys(Oportunidad::ETAPAS);
@endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">Clientes potenciales, presupuestos y seguimiento</span><h1 class="ttl">CRM</h1></div>
    <div class="row" style="min-width:0;max-width:100%">
      <a class="btn" href="{{ route('crm.index', ['nueva' => 1]) }}" style="background:#0B0B0B;color:#fff"><span style="color:#C8F031">+</span>Oportunidad</a>
      @include('partials.empresa', ['volver' => '/crm'])
    </div>
  </div>
  @if(session('mailto'))<div class="msg" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><span>Prepara el correo y adjunta el PDF del presupuesto.</span><a class="pillbtn wh" style="height:32px" href="{{ session('mailto') }}">Abrir el correo</a></div>@endif
  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
    @foreach($kpis as [$l, $v, $s])
      <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $v }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>

  @if($nuevaOp)
  <form method="POST" action="{{ route('crm.op.store') }}" class="card" style="padding:18px 20px;gap:12px;border:1.5px solid #0B0B0B">
    @csrf
    <span style="font-size:16px;font-weight:700">Nueva oportunidad</span>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:10px">
      <input class="inp" name="nombre" value="{{ old('nombre') }}" placeholder="Cliente o empresa *" maxlength="255" required>
      <input class="inp" name="titulo" value="{{ old('titulo') }}" placeholder="Qué le ofreces *" maxlength="255" required>
      <input class="inp mono" name="importe" value="{{ old('importe') }}" placeholder="Importe estimado €" inputmode="decimal">
      <select class="sel" name="etapa">@foreach($etapas as $e)<option @selected(old('etapa', 'Nuevo') === $e)>{{ $e }}</option>@endforeach</select>
      <input class="inp" type="date" name="fecha" value="{{ old('fecha', $hoy->addDays(30)->toDateString()) }}" title="Cierre previsto">
      <select class="sel" name="origen">@foreach(Oportunidad::ORIGENES as $o)<option @selected(old('origen', 'Web') === $o)>{{ $o }}</option>@endforeach</select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px"><a class="btn soft" href="{{ route('crm.index') }}">Cancelar</a><button class="btn lime" type="submit">Crear</button></div>
  </form>
  @endif

  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div class="chipbar" style="border-radius:11px">
      @foreach([['embudo', 'Embudo'], ['pres', 'Presupuestos'], ['agenda', 'Agenda'.($pendientes ? ' · '.$pendientes : '')]] as [$id, $l])
        <a class="{{ $tab === $id ? 'on' : '' }}" style="padding:7px 14px;border-radius:8px" href="{{ route('crm.index', $id === 'embudo' ? [] : ['tab' => $id]) }}">{{ $l }}</a>
      @endforeach
    </div>
    <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ route('crm.index', ['exportar' => 'csv']) }}">Excel / CSV</a></div>
  </div>

  {{-- EMBUDO --}}
  @if($tab === 'embudo')
  <div style="overflow-x:auto"><div style="min-width:1200px;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:start">
    @foreach($cols as $col)
      @php $dark = $col['et'] === 'Ganado'; $ei = array_search($col['et'], $etapas, true); @endphp
      <div style="display:flex;flex-direction:column;gap:8px;background:{{ $dark ? '#0B0B0B' : '#EAEAE5' }};color:{{ $dark ? '#F4F4F0' : '#0B0B0B' }};border-radius:16px;padding:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 6px"><b style="font-size:13px">{{ $col['et'] }} · {{ $col['items']->count() }}</b><span style="font:600 11.5px var(--mono);color:{{ $dark ? '#C8F031' : '#3C3C3C' }}">{{ Formato::eur($col['items']->sum('importe'), 0) }}</span></div>
        @foreach($col['items'] as $o)
          @php $late = ! $o->cerrada() && $o->fecha->lt($hoy); @endphp
          <div style="background:#fff;color:#0B0B0B;border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,.05)">
            <span style="font-size:14px;font-weight:600;line-height:1.3">{{ $o->nombre }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $o->titulo }}</span>
            <div style="display:flex;justify-content:space-between;align-items:center"><span style="font:600 13px var(--mono)">{{ Formato::eur($o->importe, 0) }}</span><span style="font-size:11px;color:#6A6A65">{{ $o->origen }}</span></div>
            <span style="font-size:11.5px;color:#6A6A65">{{ $o->cerrada() ? 'Cerrada' : 'Cierre' }} {{ Formato::fecha($o->fecha) }}@if($late)<b style="color:#B42318"> · fuera de plazo</b>@endif</span>
            <div style="display:flex;gap:4px;flex-wrap:wrap;padding-top:2px">
              @if($ei > 0)<form method="POST" action="{{ route('crm.op.etapa', $o) }}">@csrf<input type="hidden" name="etapa" value="{{ $etapas[$ei - 1] }}"><button class="pillbtn" style="height:26px;padding:0 9px;font-size:12px" type="submit" title="Volver a {{ $etapas[$ei - 1] }}">‹</button></form>@endif
              @if($ei < count($etapas) - 1)<form method="POST" action="{{ route('crm.op.etapa', $o) }}">@csrf<input type="hidden" name="etapa" value="{{ $etapas[$ei + 1] }}"><button class="pillbtn" style="height:26px;padding:0 9px;font-size:12px" type="submit" title="Pasar a {{ $etapas[$ei + 1] }}">Avanzar ›</button></form>@endif
              <a class="pillbtn" style="height:26px;padding:0 9px;font-size:12px" href="{{ route('crm.index', ['tab' => 'pres', 'presupuesto' => 1, 'op' => $o->id]) }}">Presupuesto</a>
              <form method="POST" action="{{ route('crm.op.destroy', $o) }}" data-confirm="¿Eliminar la oportunidad «{{ $o->nombre }}»?">@csrf @method('DELETE')<button class="pillbtn" style="height:26px;padding:0 9px;font-size:12px" type="submit" aria-label="Eliminar">×</button></form>
            </div>
          </div>
        @endforeach
        @if($col['items']->isEmpty())<div style="padding:14px 8px;text-align:center;font-size:12.5px;color:{{ $dark ? '#8A8A85' : '#6A6A65' }}">Sin oportunidades</div>@endif
      </div>
    @endforeach
  </div></div>
  @endif

  {{-- PRESUPUESTOS --}}
  @if($tab === 'pres')
    @if($presNuevo)
      @php $lin0 = $presNuevo->exists ? [['d' => $presNuevo->titulo, 'q' => '1', 'p' => Formato::num($presNuevo->importe), 'iva' => '21']] : [['d' => '', 'q' => '1', 'p' => '', 'iva' => '21']]; $lin0 = old('lineas', $lin0); @endphp
      <form method="POST" action="{{ route('crm.pres.store') }}" class="card" style="padding:18px 20px;gap:12px;border:1.5px solid #0B0B0B" data-pr-form data-catalogo='@json($catalogo)'>
        @csrf
        @if($presNuevo->exists)<input type="hidden" name="oportunidad_id" value="{{ $presNuevo->id }}">@endif
        <span style="font-size:16px;font-weight:700">Nuevo presupuesto</span>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 180px 170px;gap:10px">
          <input class="inp" name="cli" value="{{ old('cli', $presNuevo->nombre) }}" placeholder="Cliente *" maxlength="255" required>
          <input class="inp mono upper" name="nif" value="{{ old('nif', $presNuevo->nif) }}" placeholder="NIF (opcional)" maxlength="20">
          <select class="sel" name="validez">@foreach([15, 30, 60, 90] as $v)<option value="{{ $v }}" @selected((string) old('validez', '30') === (string) $v)>Válido {{ $v }} días</option>@endforeach</select>
        </div>
        <datalist id="pr-prods">@foreach($catalogo as $p)<option value="{{ $p['nombre'] }}">{{ Formato::num($p['precio']) }} €</option>@endforeach</datalist>
        <div data-fc-lines style="display:flex;flex-direction:column;gap:8px">
          @foreach(array_values($lin0) as $i => $l)
            <div class="pr-lin" data-fc-line>
              <input class="inp" list="pr-prods" name="lineas[{{ $i }}][d]" value="{{ $l['d'] }}" placeholder="Descripción" maxlength="255" data-k="d">
              <input class="inp mono" name="lineas[{{ $i }}][q]" value="{{ $l['q'] }}" inputmode="decimal" data-k="q">
              <input class="inp mono" name="lineas[{{ $i }}][p]" value="{{ $l['p'] }}" inputmode="decimal" placeholder="Precio" data-k="p">
              <select class="sel" name="lineas[{{ $i }}][iva]" data-k="iva">@foreach(['21', '10', '4', '0'] as $v)<option value="{{ $v }}" @selected((string) $l['iva'] === $v)>{{ $v }} %</option>@endforeach</select>
              <span class="fc-imp" data-imp>0,00</span>
              <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-fc-del aria-label="Quitar línea">×</button>
            </div>
          @endforeach
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><button type="button" class="pillbtn" style="height:34px" data-fc-add>+ Línea</button><span style="font:600 13px var(--mono)" data-fc-tot></span></div>
        <div style="display:flex;justify-content:flex-end;gap:8px"><a class="btn soft" href="{{ route('crm.index', ['tab' => 'pres']) }}">Cancelar</a><button class="btn lime" type="submit">Guardar borrador</button></div>
        <template data-pr-tpl>
          <div class="pr-lin" data-fc-line>
            <input class="inp" list="pr-prods" placeholder="Descripción" maxlength="255" data-k="d">
            <input class="inp mono" value="1" inputmode="decimal" data-k="q">
            <input class="inp mono" inputmode="decimal" placeholder="Precio" data-k="p">
            <select class="sel" data-k="iva">@foreach(['21', '10', '4', '0'] as $v)<option value="{{ $v }}">{{ $v }} %</option>@endforeach</select>
            <span class="fc-imp" data-imp>0,00</span>
            <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-fc-del aria-label="Quitar línea">×</button>
          </div>
        </template>
      </form>
    @else
      <div><a class="btn" href="{{ route('crm.index', ['tab' => 'pres', 'presupuesto' => 1]) }}" style="background:#0B0B0B;color:#fff">+ Nuevo presupuesto</a></div>
    @endif
    <div class="card" style="padding:6px 18px 10px;gap:0">
      @forelse($pres as $p)
        @php $st = $p->estadoVisible(); [$bg, $fg] = Presupuesto::COLORES[$st]; @endphp
        <div style="display:grid;grid-template-columns:minmax(0,1.4fr) auto auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #F1F1EC">
          <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span class="ell" style="font-size:14px;font-weight:600">{{ $p->receptor_nombre }}</span><span style="font:500 12px var(--mono);color:#6A6A65">{{ $p->num }} · {{ Formato::fecha($p->fecha) }} · Válido hasta {{ Formato::fecha($p->vence()) }}</span></div>
          <div style="display:flex;align-items:center;gap:10px"><span style="font:700 14px var(--mono)">{{ Formato::eur($p->total) }}</span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }}">{{ $st }}</span></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
            <a class="pillbtn" style="height:32px;font-size:12.5px" href="{{ route('crm.pres.documento', [$p, 'imprimir' => 1]) }}" target="_blank" rel="noopener">PDF</a>
            @if($p->estado === 'Borrador')<form method="POST" action="{{ route('crm.pres.estado', $p) }}">@csrf<input type="hidden" name="estado" value="Enviado"><button class="pillbtn ink" style="height:32px;font-size:12.5px" type="submit">Enviar</button></form>@endif
            @if($p->estado === 'Enviado')
              <form method="POST" action="{{ route('crm.pres.estado', $p) }}">@csrf<input type="hidden" name="estado" value="Aceptado"><button class="pillbtn lime" style="height:32px;font-size:12.5px" type="submit">Aceptado</button></form>
              <form method="POST" action="{{ route('crm.pres.estado', $p) }}">@csrf<input type="hidden" name="estado" value="Rechazado"><button class="pillbtn" style="height:32px;font-size:12.5px" type="submit">Rechazado</button></form>
            @endif
            @if(in_array($p->estado, ['Aceptado', 'Enviado'], true))<a class="pillbtn ink" style="height:32px;font-size:12.5px" href="{{ route('facturacion.create', ['presupuesto' => $p->id]) }}">Convertir en factura</a>@endif
            @if($p->estado === 'Facturado' && $p->factura_id)<a class="pillbtn" style="height:32px;font-size:12.5px" href="{{ route('facturacion.show', $p->factura_id) }}">Ver factura</a>@endif
          </div>
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">Aún no hay presupuestos.</div>
      @endforelse
    </div>
  @endif

  {{-- AGENDA --}}
  @if($tab === 'agenda')
    <form method="POST" action="{{ route('crm.act.store') }}" class="card" style="padding:14px 18px;gap:10px">
      @csrf
      <div style="display:grid;grid-template-columns:130px minmax(0,1fr) 150px 110px minmax(0,1fr) auto;gap:8px;align-items:center" class="ag-grid">
        <select class="sel" name="tipo">@foreach(Actividad::TIPOS as $t)<option>{{ $t }}</option>@endforeach</select>
        <input class="inp" name="texto" placeholder="Qué hay que hacer" maxlength="255" required>
        <input class="inp" type="date" name="fecha" value="{{ $hoy->toDateString() }}" required>
        <input class="inp" type="time" name="hora">
        <select class="sel" name="oportunidad_id"><option value="">Sin oportunidad</option>@foreach($abiertas as $o)<option value="{{ $o->id }}">{{ $o->nombre }} · {{ $o->titulo }}</option>@endforeach</select>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Añadir</button>
      </div>
    </form>
    <div class="card" style="padding:6px 18px 10px;gap:0">
      @forelse($acts as $a)
        @php $late = ! $a->hecha && $a->fecha->lt($hoy); @endphp
        <div style="display:grid;grid-template-columns:24px 90px minmax(0,1fr) auto;gap:12px;align-items:center;padding:11px 0;border-bottom:1px solid #F1F1EC">
          <form method="POST" action="{{ route('crm.act.hecha', $a) }}">@csrf<button type="submit" class="chkbox {{ $a->hecha ? 'on' : '' }}" aria-label="{{ $a->hecha ? 'Marcar como pendiente' : 'Marcar como hecha' }}">{{ $a->hecha ? '✓' : '' }}</button></form>
          <span style="font-size:12px;font-weight:600;padding:3px 9px;border-radius:10px;background:#F4F4F0;justify-self:start">{{ $a->tipo }}</span>
          <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell" style="font-size:14px;{{ $a->hecha ? 'color:#6A6A65;text-decoration:line-through' : '' }}">{{ $a->texto }}</span><span style="font-size:12px;color:#6A6A65">{{ $a->oportunidad?->nombre }}</span></div>
          <span style="font:500 12.5px var(--mono);color:#3C3C3C;white-space:nowrap">{{ $a->fecha->isSameDay($hoy) ? 'Hoy' : Formato::fecha($a->fecha) }}{{ $a->hora ? ' · '.$a->hora : '' }}@if($late)<b style="color:#B42318"> · atrasada</b>@endif</span>
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">No hay actividades en la agenda.</div>
      @endforelse
    </div>
  @endif
</div>
@endsection
