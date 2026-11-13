@extends('layouts.app')
@php
    use App\Http\Controllers\FacturaController as FC;
    use App\Models\Factura;
    use App\Support\Formato;
    $lineas = array_values($dr['lineas']);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('facturacion._cabecera')
  @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif
  <datalist id="fc-clis">@foreach($receptores as $r)<option value="{{ $r['nombre'] }}">{{ $r['nif'] }}</option>@endforeach</datalist>
  <datalist id="fc-prods">@foreach($cfg->catalogo ?? [] as $p)<option value="{{ $p['nombre'] }}">{{ Formato::num($p['precio']) }} € · IVA {{ $p['iva'] }} %</option>@endforeach</datalist>

  <form method="POST" action="{{ route('facturacion.store') }}" id="fcForm" data-fc-form data-receptores='@json($receptores)' data-catalogo='@json($cfg->catalogo ?? [])' data-proximos='@json($proximos)' style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    @csrf
    <input type="hidden" name="rectifica_id" value="{{ $dr['rectifica_id'] }}">
    <input type="hidden" name="presupuesto_id" value="{{ $dr['presupuesto_id'] }}">
    <div style="flex:1 1 460px;min-width:0;display:flex;flex-direction:column;gap:16px">
      <div class="card" style="padding:18px 20px;gap:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:16px;font-weight:700">Datos de la factura</span><span style="font:500 12px var(--mono);color:#6A6A65" data-fc-next>Próximo número: {{ $proximos[$dr['serie']] ?? '' }}</span></div>
        @if($rect)<span style="padding:8px 12px;border-radius:10px;background:#FCEBCB;color:#6E4300;font-size:13px;font-weight:600">Rectificativa de {{ $rect->num }} · las cantidades en negativo restan de la original</span>@endif
        @if($dr['presupuesto_id'])<span style="padding:8px 12px;border-radius:10px;background:#E6F6A8;color:#2E3A00;font-size:13px;font-weight:600">Desde el presupuesto · al emitir, el presupuesto pasa a «Facturado»</span>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:12px">
          <label class="lbl-s">Serie<select class="sel" name="serie" data-fc-serie>@foreach($cfg->series as $s)<option value="{{ $s['cod'] }}" @selected($dr['serie'] === $s['cod'])>{{ $s['cod'] }} · {{ $s['nombre'] }}</option>@endforeach</select></label>
          <label class="lbl-s">Fecha de expedición<input class="inp" type="date" name="fecha" value="{{ $dr['fecha'] }}" max="{{ $hoy }}" required></label>
          <label class="lbl-s">Retención IRPF %<select class="sel" name="ret_pct">@foreach(FC::RETS as $r)<option @selected((string) $dr['ret_pct'] === $r)>{{ $r }}</option>@endforeach</select></label>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
          <label class="lbl-s">Cliente · escribe o elige de la lista<input class="inp" list="fc-clis" name="receptor_nombre" value="{{ $dr['receptor_nombre'] }}" placeholder="Nombre o razón social" maxlength="255" data-fc-cli autocomplete="off"><span class="hint" style="color:#6E4300;display:none" data-fc-cli-nuevo>Cliente nuevo: completa el NIF y la dirección</span></label>
          <label class="lbl-s">NIF del cliente<input class="inp mono upper" name="receptor_nif" value="{{ $dr['receptor_nif'] }}" maxlength="20" data-fc-nif></label>
          <label class="lbl-s">Dirección del cliente<input class="inp" name="receptor_dir" value="{{ $dr['receptor_dir'] }}" maxlength="255" data-fc-dir></label>
        </div>
        @if($cfg->modo === 'electronica')
        <div style="display:flex;flex-direction:column;gap:10px;padding:12px;border-radius:12px;background:#F4F4F0">
          <span style="font-size:12.5px;font-weight:700">Factura electrónica · datos del cliente</span>
          <div style="display:grid;grid-template-columns:110px minmax(0,1fr) minmax(0,1fr);gap:10px">
            <label class="lbl-s">C.P.<input class="inp mono" name="fe_cp" value="{{ $dr['fe_cp'] }}" maxlength="5" inputmode="numeric" data-fc-cp></label>
            <label class="lbl-s">Municipio<input class="inp" name="fe_municipio" value="{{ $dr['fe_municipio'] }}" maxlength="50" data-fc-mun></label>
            <label class="lbl-s">Provincia<input class="inp" name="fe_provincia" value="{{ $dr['fe_provincia'] }}" maxlength="20" data-fc-prov placeholder="Se deduce del C.P."></label>
          </div>
          <span style="font-size:12px;color:#6A6A65">Solo si facturas a una Administración Pública (FACe): códigos DIR3 que te haya dado.</span>
          <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
            <label class="lbl-s">Oficina contable<input class="inp mono upper" name="dir3_oc" value="{{ $dr['dir3_oc'] }}" maxlength="10"></label>
            <label class="lbl-s">Órgano gestor<input class="inp mono upper" name="dir3_og" value="{{ $dr['dir3_og'] }}" maxlength="10"></label>
            <label class="lbl-s">Unidad tramitadora<input class="inp mono upper" name="dir3_ut" value="{{ $dr['dir3_ut'] }}" maxlength="10"></label>
          </div>
        </div>
        @endif
        <div style="display:flex;flex-direction:column;gap:8px">
          <div class="fc-lin fc-lin-h"><span>Descripción</span><span>Cant.</span><span>Precio</span><span>IVA %</span><span>Suplido</span><span style="text-align:right">Importe</span><span></span></div>
          <div data-fc-lines style="display:flex;flex-direction:column;gap:8px">
            @foreach($lineas as $i => $l)
              <div class="fc-lin" data-fc-line>
                <input class="inp" list="fc-prods" name="lineas[{{ $i }}][d]" value="{{ $l['d'] }}" placeholder="Producto, servicio o concepto" maxlength="255" data-k="d">
                <input class="inp mono" name="lineas[{{ $i }}][q]" value="{{ $l['q'] }}" inputmode="decimal" data-k="q">
                <input class="inp mono" name="lineas[{{ $i }}][p]" value="{{ $l['p'] }}" inputmode="decimal" placeholder="0,00" data-k="p">
                <select class="sel" name="lineas[{{ $i }}][iva]" data-k="iva" @if(! empty($l['sup'])) style="display:none" @endif>@foreach(FC::IVAS as $v)<option @selected((string) $l['iva'] === $v)>{{ $v }}</option>@endforeach</select>
                <span class="fc-nosuj" @if(empty($l['sup'])) style="display:none" @endif>No sujeto</span>
                <label class="fc-sup"><input type="checkbox" name="lineas[{{ $i }}][sup]" value="1" @checked(! empty($l['sup'])) data-k="sup"><span>Suplido</span></label>
                <span class="fc-imp" data-imp>0,00</span>
                <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-fc-del aria-label="Quitar línea">×</button>
              </div>
            @endforeach
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="pillbtn" style="height:34px" data-fc-add>+ Añadir línea</button><button type="button" class="pillbtn" style="height:34px" data-fc-add-sup>+ Añadir suplido</button></div>
          <span class="hint muted">Los suplidos son gastos pagados en nombre del cliente: no forman parte de la base imponible ni llevan IVA (art. 78.Tres.3º LIVA).</span>
        </div>
        <label class="lbl-s">Observaciones<input class="inp" name="observaciones" value="{{ $dr['observaciones'] }}" placeholder="Opcional" maxlength="500"></label>
      </div>

      <div class="card" style="padding:18px 20px;gap:14px">
        <span style="font-size:16px;font-weight:700">Forma de pago</span>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
          <label class="lbl-s">Forma de pago<select class="sel" name="forma_pago" data-fc-fp>@foreach(Factura::FORMAS_PAGO as $fp)<option @selected($dr['forma_pago'] === $fp)>{{ $fp }}</option>@endforeach</select></label>
          <label class="lbl-s" data-fc-dias>Vencimiento<select class="sel" name="pago_dias">@foreach(FC::DIAS as $v => $l)<option value="{{ $v }}" @selected((string) $dr['pago_dias'] === (string) $v)>{{ $l }}</option>@endforeach</select></label>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr));gap:12px" data-fc-bank>
          <label class="lbl-s" data-fc-cli-bank>Cuenta del cliente · cargo SEPA<select class="sel" data-fc-iban-sel><option value="">Elegir una cuenta del cliente…</option></select></label>
          <label class="lbl-s" data-fc-cli-bank>IBAN del cliente · puedes cambiarlo a mano<input class="inp mono" name="iban" value="{{ $dr['iban'] }}" placeholder="ES00 0000 0000 0000 0000 0000" maxlength="40" data-iban data-fc-iban><span class="hint err" data-iban-hint></span></label>
          <label class="lbl-s" data-fc-em>Cuenta de la empresa · el cliente ingresa aquí
            <select class="sel" name="iban_emisor">
              @forelse($emIbans as $ib)<option value="{{ $ib }}" @selected($dr['iban_emisor'] === $ib)>{{ trim(chunk_split($ib, 4, ' ')) }}</option>@empty<option value="">Añade una cuenta en los datos de alta de la empresa</option>@endforelse
            </select>
          </label>
        </div>
      </div>

      <div class="card" style="padding:16px 20px;flex-direction:row;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap">
        <span style="font:600 13px var(--mono);margin-right:auto" data-fc-tot></span>
        <a class="btn soft" href="{{ route('facturacion.index') }}">Descartar</a>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff" @disabled(! $c->nif)>Emitir y registrar</button>
      </div>
    </div>
    <div style="flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:14px;font-weight:700">Vista previa</span><button class="pillbtn wh" style="height:32px;font-size:12.5px" type="submit" formaction="{{ route('facturacion.borrador') }}" formtarget="fcPrev" formnovalidate data-fc-prev>Actualizar</button></div>
      <iframe name="fcPrev" title="Vista previa de la factura" style="width:100%;height:1000px;border:0;background:#fff;border-radius:6px;box-shadow:0 1px 0 #E2E2DC,0 10px 30px rgba(11,11,11,.08)"></iframe>
    </div>
  </form>
  <template data-fc-tpl>
    <div class="fc-lin" data-fc-line>
      <input class="inp" list="fc-prods" placeholder="Producto, servicio o concepto" maxlength="255" data-k="d">
      <input class="inp mono" value="1" inputmode="decimal" data-k="q">
      <input class="inp mono" inputmode="decimal" placeholder="0,00" data-k="p">
      <select class="sel" data-k="iva">@foreach(FC::IVAS as $v)<option>{{ $v }}</option>@endforeach</select>
      <span class="fc-nosuj" style="display:none">No sujeto</span>
      <label class="fc-sup"><input type="checkbox" value="1" data-k="sup"><span>Suplido</span></label>
      <span class="fc-imp" data-imp>0,00</span>
      <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-fc-del aria-label="Quitar línea">×</button>
    </div>
  </template>
</div>
@endsection
