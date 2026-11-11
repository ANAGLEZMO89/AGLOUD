@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; $facturas = $lista->where('tipo', '!=', 'Documento'); $docs = $lista->where('tipo', 'Documento'); @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')

  @unless($ej->cerrado())
  <form method="POST" action="{{ route('contabilidad.bandeja.subir') }}" enctype="multipart/form-data" data-band-subir>
    @csrf
    <label class="band-drop" data-band-drop>
      <input type="file" name="archivos[]" multiple accept=".pdf,image/*" hidden data-band-files>
      <span class="band-ocr">OCR</span>
      <span style="flex:1 1 260px;display:flex;flex-direction:column;gap:3px;min-width:0"><span style="font-size:15px;font-weight:700">Arrastra aquí facturas o tiques</span><span style="font-size:13px;color:#6A6A65;line-height:1.45">PDF, JPG o PNG, uno o varios a la vez. El OCR lee proveedor, NIF, número, fecha e importes; tú revisas y contabilizas.</span></span>
      <span class="btn" style="background:#0B0B0B;color:#fff">Seleccionar archivos</span>
    </label>
  </form>
  @endunless
  @if($errors->has('archivos') || $errors->has('archivos.0'))<div class="ferr">{{ $errors->first('archivos') ?: $errors->first('archivos.0') }}</div>@endif

  @if($lista->isEmpty())
    <div class="card"><div class="empty"><b>Bandeja vacía</b><span>Todas las facturas de {{ $ej->anio }} están contabilizadas.</span></div></div>
  @else
  <span style="font-size:13.5px;color:#3C3C3C">Abre cada factura para revisar sus datos con el documento delante y pulsa «Contabilizar». Las emitidas en Facturación y las ya registradas en Gestión también puedes contabilizarlas en bloque.</span>
  @if($docs->count())
    <div class="card" style="padding:8px">
      <span style="font-size:13px;font-weight:700;padding:8px 12px 4px">Documentos subidos pendientes de revisar · {{ $docs->count() }}</span>
      @foreach($docs as $x)
        <a class="band-doc {{ ($rev['k'] ?? '') === $x['k'] ? 'on' : '' }}" href="{{ $x['url'] }}"><span style="display:flex;flex-direction:column;gap:2px;min-width:0"><b class="ell" style="font-size:14px">{{ $x['ter'] }}</b><small style="color:#6A6A65">{{ Formato::fecha($x['fecha']) }} · {{ $x['origen'] }}</small></span><span class="band-tag">REVISAR</span></a>
      @endforeach
    </div>
  @endif
  @if($facturas->count())
  <form method="POST" action="{{ route('contabilidad.contabilizar') }}" class="card" style="padding:6px 18px 14px;overflow-x:auto;gap:0" data-bandeja>
    @csrf
    <div style="min-width:1040px">
      <div class="band-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><label class="chk"><input type="checkbox" data-band-todas checked style="accent-color:#0B0B0B"></label><span>Tipo</span><span>Fecha</span><span>Nº</span><span>Cliente / proveedor</span><span style="text-align:right">Base</span><span style="text-align:right">IVA</span><span style="text-align:right">Total</span><span>Cuenta</span></div>
      @foreach($facturas as $x)
        @php $col = ['Emitida' => ['#E6F6A8', '#2E3A00'], 'Compra' => ['#FCEBCB', '#6E4300'], 'Gasto' => ['#ECECE7', '#3C3C3C']][$x['tipo']]; @endphp
        <div class="band-cols" style="padding:10px 0;border-bottom:1px solid #F1F1EC;font-size:13.5px;{{ ($rev['k'] ?? '') === $x['k'] ? 'background:#F7F7F3' : '' }}">
          <label class="chk"><input type="checkbox" name="k[]" value="{{ $x['k'] }}" checked data-band-k style="accent-color:#0B0B0B"></label>
          <span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $col[0] }};color:{{ $col[1] }}">{{ $x['tipo'] }}</span></span>
          <span class="mono" style="font-size:12.5px">{{ Formato::fecha($x['fecha']) }}</span>
          <a class="mono ell" style="font-size:12.5px;font-weight:600" href="{{ route('contabilidad.bandeja', ['revisar' => $x['k']]) }}" title="Abrir para revisar">{{ $x['num'] }}</a>
          <a class="ell" style="color:inherit" href="{{ route('contabilidad.bandeja', ['revisar' => $x['k']]) }}">{{ $x['ter'] }}@if(isset($x['deducible']) && ! $x['deducible']) <small style="color:#8A1C1C">· IVA no deducible</small>@endif</a>
          <span class="mono" style="text-align:right">{{ Formato::num($x['base']) }}</span><span class="mono" style="text-align:right">{{ Formato::num($x['iva']) }}</span><span class="mono" style="text-align:right;font-weight:700">{{ Formato::num($x['total']) }}</span>
          @if($x['tipo'] === 'Emitida')
            <span class="mono ell" style="font-size:12px" title="{{ Contabilidad::nombre($x['cuenta']) }}">{{ $x['cuenta'] }} · {{ Contabilidad::nombre($x['cuenta']) }}</span>
          @else
            <input class="inp mono" name="cta[{{ $x['k'] }}]" value="{{ $x['cuenta'] }}" maxlength="9" list="ctas6" style="height:32px;font-size:12.5px" title="{{ Contabilidad::nombre($x['cuenta']) }}">
          @endif
        </div>
      @endforeach
      <datalist id="ctas6">@foreach(config('pgc') as $k => $n)@if(in_array(((string) $k)[0], ['6', '2'], true) && strlen((string) $k) === 3)<option value="{{ Contabilidad::a9($k) }}">{{ $n }}</option>@endif @endforeach</datalist>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:14px">
        <span style="font-size:12.5px;color:#6A6A65">Pulsa el número o el nombre para abrir la factura y revisarla. Emitidas: 430 al debe; 705, 477 y suplidos al haber. Recibidas: gasto o compra y 472 al debe; 4751 y 400/410 al haber.</span>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff" @disabled($ej->cerrado())>Contabilizar las marcadas</button>
      </div>
    </div>
  </form>
  @endif
  @endif
</div>
@endsection

@section('overlay')
@if($rev)
@php
  $old = fn ($k, $d = '') => old($k, $rev[$k] ?? $d);
  $esE = $rev['clase'] === 'Emitida' && $rev['fija'];
@endphp
<div class="drawer" role="dialog" aria-modal="true" aria-label="Revisar factura">
  <a class="shade" href="{{ route('contabilidad.bandeja') }}" aria-label="Cerrar"></a>
  <div class="panel" style="width:min(1180px,100%)">
    <div class="ph"><div><b>{{ $esE ? 'Factura emitida '.$rev['num'] : (isset($rev['nuevo']) ? 'Nuevo documento · '.($rev['d']->documento?->nombre_original ?? '') : 'Factura '.$rev['num'].' · '.$rev['nombre']) }}</b><small>Revisa los datos con el documento delante y pulsa «Contabilizar».</small></div><a class="x" href="{{ route('contabilidad.bandeja') }}" aria-label="Cerrar">×</a></div>
    <div class="pb" style="align-items:stretch">
      <div class="rev-doc">
        @if($rev['docUrl'])
          @if($rev['esPdf'])<iframe src="{{ $rev['docUrl'] }}" title="Documento"></iframe>@else<img src="{{ $rev['docUrl'] }}" alt="Documento">@endif
        @else
          <div class="rev-nodoc">Esta factura no tiene el documento adjunto. Puedes añadirlo desde Gestión al editarla.</div>
        @endif
      </div>
      <form method="POST" action="{{ route('contabilidad.bandeja.revisar', $rev['k']) }}" class="rev-form" data-rev data-doc="{{ $rev['docUrl'] }}" data-nif-empresa="{{ $c->nif }}" data-terceros='@json($terceros->mapWithKeys(fn ($t) => [$t->nif ?: '_'.$t->id => [$t->id, $t->clase, $t->cuenta]]))' data-cta-def='@json($ctaDefecto)' data-cuentas='@json($catalogo)'>
        @csrf
        @if($errors->has('rev'))<div class="ferr">{{ $errors->first('rev') }}</div>@endif
        @if($esE)
          <div class="msg" style="background:#F4F4F0;color:#3C3C3C">Factura emitida desde AG Cloud: sus datos vienen del registro de facturación y no se pueden cambiar. Comprueba el asiento y contabiliza.</div>
          @foreach([['Cliente', $rev['nombre'] ?: 'Factura simplificada'], ['NIF', $rev['nif'] ?: '—'], ['Fecha', Formato::fecha($rev['fecha'])], ['Base imponible', Formato::eur($rev['base'])], ['IVA', Formato::eur($rev['iva'])], ['Retención', Formato::eur($rev['ret'])], ['Total', Formato::eur($rev['total'])], ['Cuenta de ingreso', $rev['cuenta'].' · '.Contabilidad::nombre($rev['cuenta'])]] as [$l, $v])
            <div class="rev-dato"><span>{{ $l }}</span><b>{{ $v }}</b></div>
          @endforeach
          <input type="hidden" name="clase" value="Emitida">
        @else
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
            @if(isset($rev['nuevo']))
              <div class="ap-seg">@foreach(['Recibida', 'Emitida'] as $t)<button type="button" class="{{ old('clase', 'Recibida') === $t ? 'on' : '' }}" data-rev-clase="{{ $t }}">{{ $t }}</button>@endforeach</div>
            @else<span class="band-tag" style="background:#ECECE7;color:#3C3C3C">RECIBIDA</span>@endif
            @if($rev['docUrl'])<button type="button" class="btn lime sm" data-rev-ocr>Leer con OCR</button>@endif
          </div>
          <input type="hidden" name="clase" value="{{ old('clase', 'Recibida') }}" data-rev-clase-v>
          <span class="hint" data-rev-msg></span>
          <div class="rev-grid">
            <label class="lbl-s full" data-solo="Recibida">Proveedor o acreedor
              <select class="sel" name="tercero_id" data-rev-ter>
                <option value="">{{ isset($rev['nuevo']) ? '— Nuevo (escribe nombre y NIF) —' : 'Elige…' }}</option>
                @foreach($terceros as $t)<option value="{{ $t->id }}" @selected((string) old('tercero_id', $rev['tercero_id'] ?? '') === (string) $t->id)>{{ $t->nombre }}{{ $t->nif ? ' · '.$t->nif : '' }} · {{ $t->clase === 'proveedor' ? 'Proveedor' : 'Acreedor' }}</option>@endforeach
              </select>
            </label>
            <label class="lbl-s full" data-rev-nuevo>{{ isset($rev['nuevo']) ? 'Nombre del tercero (si es nuevo)' : 'Nombre' }}<input class="inp" name="nombre" value="{{ $old('nombre') }}" maxlength="255" data-r="nombre"></label>
            <label class="lbl-s">NIF<input class="inp mono upper" name="nif" value="{{ $old('nif') }}" maxlength="20" data-r="nif"></label>
            <label class="lbl-s" data-solo="Recibida" data-rev-nuevo>Si es nuevo, es<select class="sel" name="tipo_ter"><option value="acreedor">Acreedor (servicios, 410)</option><option value="proveedor" @selected(old('tipo_ter') === 'proveedor')>Proveedor (mercaderías, 400)</option></select></label>
            <label class="lbl-s">Nº de factura *<input class="inp mono" name="num" value="{{ $old('num') }}" maxlength="60" required data-r="num"></label>
            <label class="lbl-s">Fecha *<input class="inp" type="date" name="fecha" value="{{ $old('fecha') }}" max="{{ now()->toDateString() }}" required data-r="fecha"></label>
            <label class="lbl-s full">Concepto<input class="inp" name="concepto" value="{{ $old('concepto') }}" maxlength="255" data-r="concepto"></label>
            <label class="lbl-s">Base imponible *<input class="inp mono" name="base" value="{{ old('base', ($rev['base'] ?? 0) ? Formato::num($rev['base']) : '') }}" inputmode="decimal" required style="text-align:right" data-r="base"></label>
            <label class="lbl-s">IVA<select class="sel" name="iva_pct" data-r="iva">@foreach(['21', '10', '5', '4', '0'] as $x)<option value="{{ $x }}" @selected((string) (0 + old('iva_pct', $rev['iva_pct'] ?? 21)) === $x)>{{ $x }} %</option>@endforeach</select></label>
            <label class="lbl-s">Retención IRPF<select class="sel" name="ret_pct" data-r="ret">@foreach(['0', '1', '7', '15', '19'] as $x)<option value="{{ $x }}" @selected((string) (0 + old('ret_pct', $rev['ret_pct'] ?? 0)) === $x)>{{ $x }} %</option>@endforeach</select></label>
            <label class="lbl-s">Cuenta de <span data-rev-cta-l>gasto</span><input class="inp mono" name="cuenta" value="{{ $old('cuenta') }}" list="rev-ctas" maxlength="9" required data-r="cuenta"><span class="hint muted ell" data-rev-cta-n></span></label>
            <label class="chk full" data-solo="Recibida" style="gap:8px"><input type="hidden" name="deducible" value="0"><input type="checkbox" name="deducible" value="1" @checked(old('deducible', $rev['deducible'] ?? true)) style="accent-color:#0B0B0B">IVA deducible</label>
          </div>
          <datalist id="rev-ctas">@foreach($catalogo as $k => $n)@if(in_array(((string) $k)[0], ["2", "6", "7"], true))<option value="{{ $k }}">{{ $n }}</option>@endif @endforeach</datalist>
          <div class="ap-tot"><div><span>Base</span><b data-rt="base">0,00</b></div><div><span>IVA</span><b data-rt="iva">0,00</b></div><div><span>Retención</span><b data-rt="ret">0,00</b></div><div><span>Total</span><b data-rt="tot">0,00</b></div></div>
        @endif
        <div class="rev-pie">
          @if(isset($rev['nuevo']))<button class="lnk red" type="submit" form="rev-descartar" style="font-size:12.5px">Quitar de la bandeja</button>@else<span></span>@endif
          <div style="display:flex;gap:10px"><a class="btn soft" href="{{ route('contabilidad.bandeja') }}">Cerrar</a><button class="btn lime" type="submit" @disabled($ej->cerrado())>Contabilizar</button></div>
        </div>
      </form>
      @if(isset($rev['nuevo']))<form id="rev-descartar" method="POST" action="{{ route('contabilidad.bandeja.descartar', $rev['d']) }}" data-confirm="¿Quitar este documento de la bandeja? Seguirá en Documentos.">@csrf @method('DELETE')</form>@endif
    </div>
  </div>
</div>
<script src="{{ asset('js/ocr.js') }}?v={{ config('app.version', '1') }}"></script>
@endif
@endsection
