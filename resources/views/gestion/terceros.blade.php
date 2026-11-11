@extends('layouts.app')
@php
    use App\Http\Controllers\TerceroController as TC;
    use App\Models\Tercero;
    use App\Support\Formato;
    [$plural, $sing, $pre] = Tercero::CLASES[$clase];
    $t = $editar;
    $v = fn ($k, $def = '') => old($k, $t ? ($t->$k ?? $def) : $def);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('gestion._cabecera', ['tituloH' => $plural, 'volver' => $clase === 'cliente' ? '/clientes' : '/'.$mod, 'modulo' => $clase === 'cliente' ? 'clientes' : $mod, 'actual' => $clase === 'cliente' ? 'clientes.index' : $mod.'.index'])

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach($kpis as [$l, $val, $s])<div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>@endforeach
  </div>

  <form method="GET" action="{{ route($mod.'.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px" data-autoform>
    <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por nombre, NIF, cuenta o municipio" style="flex:1;min-width:220px;max-width:380px;height:38px" data-autosubmit>
    <div style="margin-left:auto;display:flex;gap:10px;align-items:center">
      <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#fff"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ route($mod.'.index', ['exportar' => 'csv']) }}">Excel / CSV</a></div>
      @if($clase === 'cliente' && ($sinAlta ?? 0))<button class="btn white" type="submit" form="ter-importar">Añadir {{ $sinAlta }} {{ $sinAlta === 1 ? 'cliente' : 'clientes' }} de sus facturas</button>@endif
      <a class="btn" href="{{ route($mod.'.index', ['nuevo' => 1]) }}" style="background:#0B0B0B;color:#fff">+ Nuevo {{ mb_strtolower($sing) }}</a>
    </div>
  </form>

  @if($clase === 'cliente' && ($sinAlta ?? 0))<form id="ter-importar" method="POST" action="{{ route('ventas.importar') }}">@csrf</form>@endif
  @if($form)
  <form method="POST" action="{{ $t ? route($mod.'.update', $t) : route($mod.'.store') }}" class="card" style="padding:18px 20px;gap:14px;border:1.5px solid #0B0B0B" data-tercero-form data-siguiente="{{ $siguiente }}">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">{{ $t ? 'Editar '.mb_strtolower($sing).' · '.$t->nombre : 'Nuevo '.mb_strtolower($sing) }}</span><a href="{{ route($mod.'.index') }}" style="font-weight:600;font-size:13.5px">Cancelar</a></div>
    @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:12px">
      <label class="lbl-s" style="grid-column:1 / -1">Nombre o razón social *<input class="inp" name="nombre" value="{{ $v('nombre') }}" maxlength="255" required></label>
      <label class="lbl-s">NIF / CIF<span class="nifwrap"><input class="inp mono upper" name="nif" value="{{ $v('nif') }}" maxlength="20" data-nif></span><span class="hint" data-nif-msg></span></label>
      <label class="lbl-s">Email<input class="inp" type="email" name="email" value="{{ $v('email') }}" maxlength="255"></label>
      <label class="lbl-s">Teléfono<input class="inp" name="telefono" value="{{ $v('telefono') }}" maxlength="30"></label>
      <label class="lbl-s" style="grid-column:1 / -1">Domicilio<input class="inp" name="domicilio" value="{{ $v('domicilio') }}" maxlength="255"></label>
      <label class="lbl-s">Código postal<input class="inp mono" name="cp" value="{{ $v('cp') }}" maxlength="5" inputmode="numeric"></label>
      <label class="lbl-s">Municipio<input class="inp" name="municipio" value="{{ $v('municipio') }}" maxlength="80"></label>
      <label class="lbl-s">Provincia<select class="sel" name="provincia"><option value="">Según el código postal</option>@foreach(config('agcloud.provincias') as $pv)<option @selected($v('provincia') === $pv)>{{ $pv }}</option>@endforeach</select></label>
      @if($clase === 'acreedor')
        <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(Tercero::TIPOS_ACREEDOR as $x)<option @selected($v('tipo', $par['tipo']) === $x)>{{ $x }}</option>@endforeach</select></label>
        <label class="lbl-s">Retención IRPF %<select class="sel" name="retencion">@foreach(['0', '7', '15', '19'] as $x)<option @selected((string) (0 + $v('retencion', $par['retencion'])) === $x)>{{ $x }}</option>@endforeach</select><span class="hint muted">Profesionales 15 % (7 % los 3 primeros años) · alquileres 19 %.</span></label>
      @endif
      <label class="lbl-s">Forma de pago habitual<select class="sel" name="forma_pago">@foreach(TC::PAGOS as $x)<option @selected($v('forma_pago', $par['forma_pago']) === $x)>{{ $x }}</option>@endforeach</select></label>
      <label class="lbl-s">Vencimiento habitual<select class="sel" name="pago_dias">@foreach(['0' => 'Al contado', '15' => '15 días', '30' => '30 días', '45' => '45 días', '60' => '60 días', '90' => '90 días'] as $k => $l)<option value="{{ $k }}" @selected((string) $v('pago_dias', $par['pago_dias']) === (string) $k)>{{ $l }}</option>@endforeach</select></label>
      <label class="lbl-s">IBAN<input class="inp mono" name="iban" value="{{ $v('iban') }}" maxlength="40" data-iban><span class="hint err" data-iban-hint></span></label>
      <div class="lbl-s" style="grid-column:1 / -1">Cuenta contable
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div class="seg" style="padding:3px">
            <label style="cursor:pointer"><input type="radio" name="cuenta_modo" value="auto" class="hidden" data-seg data-cta-modo @checked(old('cuenta_modo', 'auto') === 'auto')><span class="segopt">Automática</span></label>
            <label style="cursor:pointer"><input type="radio" name="cuenta_modo" value="manual" class="hidden" data-seg data-cta-modo @checked(old('cuenta_modo') === 'manual')><span class="segopt">Manual</span></label>
          </div>
          <input class="inp mono" name="cuenta" value="{{ old('cuenta', $t?->cuenta ?? $siguiente) }}" maxlength="9" inputmode="numeric" style="width:140px" data-cta>
          <span class="hint muted">{{ $t ? 'Cuenta actual: '.$t->cuenta.'.' : 'Se asignará la siguiente cuenta libre del grupo '.$pre.'.' }}</span>
        </div>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span></span>
      <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button>
    </div>
  </form>
  @if($t)
    <form method="POST" action="{{ route($mod.'.destroy', $t) }}" data-confirm="¿Eliminar a {{ $t->nombre }}?">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">Eliminar {{ mb_strtolower($sing) }}</button></form>
  @endif
  @endif

  <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
    <div style="min-width:920px">
      <div class="ter-cols" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65;padding:12px 0 10px;border-bottom:1px solid #E6E6E0"><span>Cuenta</span><span>Nombre</span><span>NIF</span><span>{{ $clase === 'acreedor' ? 'Tipo' : 'Email' }}</span><span>{{ $clase === 'acreedor' ? 'Retención' : 'Municipio' }}</span><span>Forma de pago</span><span style="text-align:right">{{ $clase === 'acreedor' ? 'Gastos' : ($clase === 'cliente' ? 'Ventas' : 'Compras') }} {{ $anio }}</span></div>
      @forelse($lista as $x)
        <a class="ter-cols rowhov" href="{{ route($mod.'.index', ['editar' => $x->id]) }}" style="padding:11px 8px;margin:0 -8px;border-radius:8px;border-bottom:1px solid #F1F1EC;font-size:13.5px;text-decoration:none;color:var(--ink)">
          <span class="mono" style="font-size:12.5px">{{ $x->cuenta }}</span><span class="ell" style="font-weight:600">{{ $x->nombre }}</span><span class="mono" style="font-size:12.5px">{{ $x->nif }}</span>
          @if($clase === 'acreedor')<span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $x->tipo === 'Servicios' ? '#ECECE7' : '#FCEBCB' }};color:{{ $x->tipo === 'Servicios' ? '#3C3C3C' : '#6E4300' }}">{{ $x->tipo }}</span></span><span class="mono">{{ (float) $x->retencion ? Formato::num($x->retencion, 0).' %' : '—' }}</span>
          @else<span class="ell c3">{{ $x->email }}</span><span class="ell c3">{{ $x->municipio }}</span>@endif
          <span class="ell c3">{{ $x->forma_pago }}</span><span class="mono" style="text-align:right;font-weight:600">{{ Formato::num($comprasPor[$x->id] ?? 0) }}</span>
        </a>
      @empty
        <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">@if($q === '') Aún no hay {{ mb_strtolower($plural) }}. <a href="{{ route($mod.'.index', ['nuevo' => 1]) }}">Da de alta el primero</a>. @else No hay {{ mb_strtolower($plural) }} con esa búsqueda. @endif</div>
      @endforelse
      @if($lista->count())<div style="padding:10px 0 2px;font-size:12px;color:#6A6A65">{{ Formato::entero($lista->count()) }} {{ mb_strtolower($plural) }} · Pulsa una fila para abrirla y editarla</div>@endif
    </div>
  </div>
</div>
@endsection
