@extends('layouts.app')
@php
  use App\Http\Controllers\ProductoController as PC;
  use App\Support\Formato;
  $e = $editar;
  $v = fn ($k, $def = '') => old($k, $e[$k] ?? $def);
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('gestion._cabecera', ['tituloH' => 'Productos', 'volver' => '/productos', 'modulo' => 'productos', 'actual' => 'productos.index'])

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach([['Productos y servicios', Formato::entero($total), 'De '.$c->nombre], ['Productos', Formato::entero($nProd), 'Artículos con precio fijo'], ['Servicios', Formato::entero($nServ), 'Horas, cuotas, trabajos']] as [$l, $val, $s])
      <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>

  <form method="GET" action="{{ route('productos.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <input class="inp" type="search" name="q" value="{{ $q }}" placeholder="Buscar por referencia, nombre o descripción" style="flex:1;min-width:200px;max-width:340px;height:38px">
    <div class="chipbar" style="border-radius:11px">
      @foreach([null => 'Todos', 'Producto' => 'Productos', 'Servicio' => 'Servicios'] as $k => $l)<a class="{{ $tipo === ($k ?: null) ? 'on' : '' }}" style="padding:7px 12px;border-radius:8px" href="{{ route('productos.index', array_filter(['tipo' => $k ?: null, 'q' => $q ?: null])) }}">{{ $l }}</a>@endforeach
    </div>
    <a class="btn" href="{{ route('productos.index', ['nuevo' => 1]) }}" style="margin-left:auto;background:#0B0B0B;color:#fff">+ Nuevo producto o servicio</a>
  </form>

  @if($form)
    <form method="POST" action="{{ route('productos.guardar') }}" class="card" style="padding:18px 20px;gap:14px;border:1.5px solid #0B0B0B">
      @csrf
      @if($e)<input type="hidden" name="i" value="{{ $e['i'] }}">@endif
      <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:16px;font-weight:700">{{ $e ? 'Editar «'.$e['nombre'].'»' : 'Nuevo producto o servicio' }}</span><a href="{{ route('productos.index') }}" style="font-weight:600;font-size:13.5px">Cancelar</a></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:12px">
        <label class="lbl-s">Tipo<select class="sel" name="tipo">@foreach(PC::TIPOS as $x)<option @selected($v('tipo', $par['tipo']) === $x)>{{ $x }}</option>@endforeach</select></label>
        <label class="lbl-s">Referencia<input class="inp mono" name="ref" value="{{ $v('ref') }}" maxlength="40" placeholder="Opcional"></label>
        <label class="lbl-s" style="grid-column:span 2">Nombre (sale en la factura) *<input class="inp" name="nombre" value="{{ $v('nombre') }}" maxlength="120" required></label>
        <label class="lbl-s">Precio sin IVA (€) *<input class="inp mono" name="precio" value="{{ old('precio', isset($e['precio']) ? Formato::num($e['precio']) : '') }}" inputmode="decimal" required></label>
        <label class="lbl-s">IVA %<select class="sel" name="iva">@foreach(PC::IVAS as $x)<option @selected((string) $v('iva', $par['iva']) === $x)>{{ $x }}</option>@endforeach</select></label>
        <label class="lbl-s" style="grid-column:1 / -1">Descripción<input class="inp" name="desc" value="{{ $v('desc') }}" maxlength="300" placeholder="Detalle interno o para el presupuesto"></label>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="hint muted">Al facturar, escribe el nombre en la línea y se rellenan el precio y el IVA.</span>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Guardar</button>
      </div>
    </form>
    @if($e)<form method="POST" action="{{ route('productos.borrar', $e['i']) }}" data-confirm="¿Eliminar «{{ $e['nombre'] }}»?">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">Eliminar</button></form>@endif
  @endif

  <div class="card" style="padding:16px 18px;gap:0">
    <div class="pr-row pr-head"><span>Ref.</span><span>Nombre</span><span>Tipo</span><span style="text-align:right">Precio</span><span>IVA</span><span style="text-align:right">Con IVA</span></div>
    @forelse($lista as $p)
      <a class="pr-row" href="{{ route('productos.index', ['editar' => $p['i']]) }}">
        <span class="mono" style="font-size:12px;color:#6A6A65">{{ $p['ref'] ?: '—' }}</span>
        <span style="display:flex;flex-direction:column;min-width:0"><b class="ell" style="font-size:13.5px">{{ $p['nombre'] }}</b>@if($p['desc'])<small class="ell" style="color:#6A6A65">{{ $p['desc'] }}</small>@endif</span>
        <span><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:10px;background:{{ $p['tipo'] === 'Producto' ? '#E6F6A8' : '#ECECE7' }}">{{ $p['tipo'] }}</span></span>
        <span class="mono" style="text-align:right">{{ Formato::eur($p['precio']) }}</span>
        <span class="mono">{{ $p['iva'] }} %</span>
        <span class="mono" style="text-align:right;font-weight:600">{{ Formato::eur(round($p['precio'] + round($p['precio'] * $p['iva'] / 100, 2), 2)) }}</span>
      </a>
    @empty
      <div style="padding:28px 0;text-align:center;font-size:14px;color:#6A6A65">@if($total) Ninguno coincide con la búsqueda. @else Aún no hay productos ni servicios. <a href="{{ route('productos.index', ['nuevo' => 1]) }}">Añade el primero</a>. @endif</div>
    @endforelse
  </div>
</div>
@endsection
