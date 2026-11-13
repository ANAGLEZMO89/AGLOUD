@extends('layouts.app')
@php
    use App\Http\Controllers\TerceroController as TC;
    use App\Models\Tercero;
    use App\Support\Formato;
    [$plural, $sing] = Tercero::CLASES[$clase];
    $f = $editar;
    $v = fn ($k, $def = '') => old($k, $f ? ($f->$k ?? $def) : $def);
    $acr = $clase === 'acreedor';
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('gestion._cabecera', ['tituloH' => $plural, 'volver' => $nd ? '/acreedores/no-deducibles' : '/'.$mod.'/facturas', 'modulo' => $mod, 'actual' => $nd ? 'acreedores.nodeducibles' : $mod.'.facturas'])
  @if($nd)<div class="msg" style="font-weight:600;background:#F4F4F0;color:#3C3C3C">Gastos con IVA no deducible (art. 95 y 96 LIVA: p. ej. atenciones a clientes, gastos no afectos, tiques sin factura completa). El IVA se suma al gasto y no entra en el modelo 303.</div>@endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach($kpis as [$l, $val, $s])<div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>@endforeach
  </div>

  <form method="GET" action="{{ route($nd ? 'acreedores.nodeducibles' : $mod.'.facturas') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px" data-autoform>
    @foreach(['y' => $y, 'per' => $per === 'Año' ? null : $per, 'chip' => $chip ?: null] as $k => $val)@if($val)<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif @endforeach
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar" style="flex:1;min-width:180px;max-width:300px;height:38px" data-autosubmit>
    <select class="sel" style="width:auto;height:38px" data-nav>@foreach($anios as $a)<option value="{{ $url(['y' => $a]) }}" @selected($a === $y)>{{ $a }}</option>@endforeach</select>
    <div class="chipbar" style="border-radius:11px">@foreach(['Año', '1T', '2T', '3T', '4T'] as $p)<a class="{{ $per === $p ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ $url(['per' => $p === 'Año' ? null : $p]) }}">{{ $p }}</a>@endforeach</div>
    @unless($nd)<div class="chipbar" style="border-radius:11px">@foreach($chips as $i => $l)<a class="{{ $chip === $i ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ $url(['chip' => $i ?: null]) }}">{{ $l }}</a>@endforeach</div>@endunless
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
      <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ $url(['exportar' => 'csv']) }}">Excel / CSV</a></div>
      <a class="btn" href="{{ $url(['nuevo' => 1]) }}" style="background:#0B0B0B;color:#fff">+ Nueva factura {{ $nd ? 'de gasto no deducible' : ($acr ? 'de gasto' : 'de compra') }}</a>
    </div>
  </form>

  @if($form)
    @if($terceros->isEmpty())
      <div class="msg warn">Primero da de alta al menos un {{ mb_strtolower($sing) }} en la pestaña «{{ $plural }}».</div>
    @else
    <form method="POST" action="{{ $f ? route($mod.'.facturas.update', $f) : route($mod.'.facturas.store') }}" enctype="multipart/form-data" class="card" style="padding:18px 20px;gap:14px;border:1.5px solid #0B0B0B" data-rec-form data-ter='@json($terceros->mapWithKeys(fn ($t) => [$t->id => ['ret' => (float) $t->retencion, 'fp' => $t->forma_pago, 'dias' => $t->pago_dias]]))'>
      @csrf
      @if($nd)<input type="hidden" name="volver" value="nd">@endif
      <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">{{ $f ? 'Editar factura '.$f->num : ($nd ? 'Nuevo gasto con IVA no deducible' : ($acr ? 'Nueva factura de gasto' : 'Nueva factura de compra')) }}</span><a href="{{ $url() }}" style="font-weight:600;font-size:13.5px">Cancelar</a></div>
      @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
        <label class="lbl-s">{{ $sing }} *<select class="sel" name="tercero_id" data-rec-ter>@foreach($terceros as $t)<option value="{{ $t->id }}" @selected((string) $v('tercero_id') === (string) $t->id)>{{ $t->nombre }}</option>@endforeach</select></label>
        <label class="lbl-s">Nº de factura *<input class="inp mono" name="num" value="{{ $v('num') }}" maxlength="60" required></label>
        <label class="lbl-s">Fecha de la factura *<input class="inp" type="date" name="fecha" value="{{ old('fecha', $f?->fecha?->toDateString() ?? $hoy->toDateString()) }}" max="{{ $hoy->toDateString() }}" required></label>
        <label class="lbl-s" style="grid-column:1 / -1">Concepto *<input class="inp" name="concepto" value="{{ $v('concepto') }}" maxlength="255" required></label>
        <label class="lbl-s">Base imponible (€) *<input class="inp mono" name="base" value="{{ old('base', $f ? Formato::num($f->base) : '') }}" inputmode="decimal" required data-rec-base></label>
        <label class="lbl-s">IVA %<select class="sel" name="iva_pct" data-rec-iva>@foreach(['21', '10', '5', '4', '0'] as $x)<option @selected((string) (0 + $v('iva_pct', 21)) === $x)>{{ $x }}</option>@endforeach</select></label>
        @if($acr)
          <label class="lbl-s">Retención IRPF %<select class="sel" name="ret_pct" data-rec-ret>@foreach(['0', '1', '7', '15', '19'] as $x)<option @selected((string) (0 + $v('ret_pct', 0)) === $x)>{{ $x }}</option>@endforeach</select></label>
          <label class="chk" style="gap:8px;align-self:end;padding-bottom:10px"><input type="checkbox" name="deducible" value="1" @checked(old('deducible', $f ? $f->deducible : ! $nd)) style="accent-color:#0B0B0B">IVA deducible</label>
        @endif
        <label class="lbl-s">Forma de pago<select class="sel" name="forma_pago" data-rec-fp>@foreach(TC::PAGOS as $x)<option @selected($v('forma_pago') === $x)>{{ $x }}</option>@endforeach</select></label>
        <label class="lbl-s">Vencimiento<input class="inp" type="date" name="vencimiento" value="{{ old('vencimiento', $f?->vencimiento?->toDateString()) }}"><span class="hint muted">Vacío = según el {{ mb_strtolower($sing) }}.</span></label>
        <label class="lbl-s">Factura (PDF o foto){{ $f && $f->documento_id ? ' · ya adjunta' : '' }}<input class="inp" type="file" name="archivo" accept="application/pdf,image/jpeg,image/png" style="padding-top:8px"></label>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font:600 13px var(--mono)" data-rec-tot></span>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button>
      </div>
    </form>
    @if($f)<form method="POST" action="{{ route($mod.'.facturas.destroy', $f) }}" data-confirm="¿Eliminar la factura {{ $f->num }}?">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">Eliminar factura</button></form>@endif
    @endif
  @endif

  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:1000px">
      <div class="rec-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Nº</span><span>Fecha</span><span>{{ $sing }}</span><span>Concepto</span><span style="text-align:right">Base</span><span style="text-align:right">IVA</span><span style="text-align:right">{{ $acr ? 'Retención' : 'Vence' }}</span><span style="text-align:right">A pagar</span><span>Estado</span></div>
      @forelse($lista as $x)
        @php [$st, $bg, $fg] = $x->estadoChip(); @endphp
        <div class="rec-cols" style="padding:10px 0;border-bottom:1px solid #F1F1EC;font-size:13.5px">
          <a class="mono" style="font-size:12.5px;font-weight:600" href="{{ $url(['editar' => $x->id]) }}">{{ $x->num }}</a><span class="mono" style="font-size:12.5px;color:#3C3C3C">{{ Formato::fecha($x->fecha) }}</span><span class="ell">{{ $x->tercero?->nombre }}</span>
          <span class="ell c3">{{ $x->concepto }}@if($x->documento_id) · <a href="{{ route('documentos.ver', $x->documento_id) }}" target="_blank" rel="noopener">PDF</a>@endif</span>
          <span class="mono" style="text-align:right">{{ Formato::num($x->base) }}</span><span class="mono" style="text-align:right">{{ Formato::num($x->cuota) }}</span>
          <span class="mono" style="text-align:right">{{ $acr ? ((float) $x->retencion ? Formato::num($x->retencion) : '—') : Formato::fecha($x->vencimiento) }}</span>
          <span class="mono" style="text-align:right;font-weight:700">{{ Formato::num($x->total) }}</span>
          <form method="POST" action="{{ route($mod.'.facturas.pagar', $x) }}">@csrf<button type="submit" title="{{ $x->pagada_at ? 'Anular el pago' : 'Marcar como pagada' }}" style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $bg }};color:{{ $fg }};border:0;cursor:pointer;font-family:inherit">{{ $acr && $x->pagada_at === null ? ($x->deducible ? 'Pendiente' : 'Pend. · no deduc.') : $st }}</button></form>
        </div>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">No hay facturas en este periodo con estos filtros.</div>
      @endforelse
      @if($lista->count())<div style="padding:10px 0 2px;font-size:12px;color:#6A6A65">{{ Formato::entero($lista->count()) }} facturas · pulsa el número para editarla y el estado para marcarla como pagada</div>@endif
    </div>
  </div>
</div>
@endsection
