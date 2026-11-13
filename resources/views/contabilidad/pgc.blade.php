@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    @foreach([['Cuentas del plan', Formato::entero($total - $nBajas), 'PGC-PYMES a 9 dígitos'], ['Subcuentas de la empresa', Formato::entero($nProp), 'Clientes, proveedores, acreedores, bancos'], ['Dadas de baja', Formato::entero($nBajas), 'No se pueden usar en asientos']] as [$l, $val, $s])
      <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">{{ $l }}</span><span style="font:600 26px/1.1 var(--mono);letter-spacing:-.03em">{{ $val }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $s }}</span></div>
    @endforeach
  </div>

  <div class="card" style="padding:16px 18px;gap:12px">
    <form method="GET" action="{{ route('contabilidad.pgc') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      @if($ver !== 'todas')<input type="hidden" name="ver" value="{{ $ver }}">@endif
      <input class="inp" type="search" name="q" value="{{ $q }}" placeholder="Buscar cuenta por número o nombre" style="flex:1;min-width:220px;height:40px">
      <select class="sel" name="g" style="width:auto;height:40px" data-autosubmit-select>
        <option value="">Todos los grupos</option>
        @foreach(['1' => 'Financiación básica', '2' => 'Activo no corriente', '3' => 'Existencias', '4' => 'Acreedores y deudores', '5' => 'Cuentas financieras', '6' => 'Compras y gastos', '7' => 'Ventas e ingresos'] as $k => $l)<option value="{{ $k }}" @selected($grupo === (string) $k)>{{ $k }} · {{ $l }}</option>@endforeach
      </select>
      <button class="pillbtn ink" type="submit">Buscar</button>
      <a class="btn" href="{{ route('contabilidad.pgc', ['nuevo' => 1]) }}" style="background:#0B0B0B;color:#fff">+ Nueva cuenta</a>
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
      <div class="chipbar" style="border-radius:11px;background:#F4F4F0">
        @foreach(['todas' => 'Todas', 'propias' => 'Subcuentas de la empresa', 'bajas' => 'Dadas de baja'] as $k => $l)<a class="{{ $ver === $k ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ route('contabilidad.pgc', array_filter(['ver' => $k === 'todas' ? null : $k, 'q' => $q ?: null, 'g' => $grupo ?: null])) }}">{{ $l }}</a>@endforeach
      </div>
      <div style="display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#F4F4F0"><span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span><a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">Excel / CSV</a></div>
    </div>

    @if($nuevo || $errors->has('pgc'))
      <form method="POST" action="{{ route('contabilidad.pgc.guardar') }}" style="border-radius:14px;border:1.5px solid #0B0B0B;padding:16px;display:flex;flex-direction:column;gap:12px">
        @csrf
        <span style="font-size:14px;font-weight:700">Nueva cuenta contable</span>
        @if($errors->has('pgc'))<div class="ferr">{{ $errors->first('pgc') }}</div>@endif
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
          <label class="lbl-s">Código (9 dígitos)<input class="inp mono" name="codigo" value="{{ old('codigo') }}" placeholder="572000001" maxlength="12" style="width:150px" required></label>
          <label class="lbl-s" style="flex:1;min-width:220px">Nombre<input class="inp" name="nombre" value="{{ old('nombre') }}" maxlength="255" placeholder="Ej. Banco Santander c/c 4821" required></label>
          <a class="btn soft" href="{{ route('contabilidad.pgc') }}">Cancelar</a>
          <button class="btn lime" type="submit">Añadir</button>
        </div>
        <span class="hint muted">Escribe 572.1 y se completa como 572000001. Tiene que colgar de una cuenta del PGC (las 3 primeras cifras). Los clientes (430…), proveedores (400…), acreedores (410…) y las cuentas bancarias de la ficha (572…) se crean solos.</span>
      </form>
    @endif

    <div>
      <div class="pgc-row pgc-head"><span>Cuenta</span><span>Nombre</span><span>Origen</span><span></span></div>
      @if($errors->has('pgcEd'))<div class="ferr" style="margin:8px 0">{{ $errors->first('pgcEd') }}</div>@endif
      @forelse($lista as $f)
        @php $ed = $editarPgc === (string) $f['c']; @endphp
        <div class="pgc-row {{ $f['tipo'] === 'PGC' && strlen(rtrim($f['c'], '0')) <= 3 ? 'pgc-base' : '' }}">
          <a class="mono" href="{{ route('contabilidad.mayor', ['c' => $f['c']]) }}" title="Ver el extracto" style="color:inherit;font-weight:600">{{ $f['c'] }}</a>
          <span class="ell">{{ $f['n'] }}</span>
          <span style="font-size:12px;color:#6A6A65" class="ell">{{ $f['origen'] ?: ($f['tipo'] === 'PGC' ? 'Plan General Contable' : '') }}{{ $f['usada'] ? ' · con movimientos' : '' }}</span>
          <span style="text-align:right;display:flex;gap:12px;justify-content:flex-end;align-items:center">
            @if($f['oculta'])
              <form method="POST" action="{{ route('contabilidad.pgc.reactivar', $f['c']) }}">@csrf<button class="lnk" type="submit" style="font-size:12.5px">Reactivar</button></form>
            @else
              @unless($ed)<a class="lnk" href="{{ request()->fullUrlWithQuery(['editar' => $f['c']]) }}" style="font-size:12.5px">Modificar</a>@endunless
              @if(! $f['usada'])
                <form method="POST" action="{{ route('contabilidad.pgc.borrar', $f['c']) }}" data-confirm="{{ ! empty($f['base']) ? '¿Dar de baja la cuenta '.$f['c'].' · '.$f['n'].'?' : '¿Eliminar la cuenta '.$f['c'].' · '.$f['n'].'?'.(! empty($f['tercero']) ? ' También se elimina de Gestión.' : '') }}">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12.5px">{{ ! empty($f['base']) ? 'Dar de baja' : 'Eliminar' }}</button></form>
              @else
                <span style="font-size:11.5px;color:#9A9A94" title="Tiene facturas o apuntes: cámbiala con «Modificar» antes de eliminarla">Con movimientos</span>
              @endif
            @endif
          </span>
        </div>
        @if($ed)
          <form method="POST" action="{{ route('contabilidad.pgc.editar', $f['c']) }}" class="pgc-edit">
            @csrf
            <label class="lbl-s">Código @if(! empty($f['base']))<input class="inp mono" value="{{ $f['c'] }}" disabled title="Las cuentas del PGC mantienen su código">@else<input class="inp mono" name="codigo" value="{{ old('codigo', $f['c']) }}" maxlength="12">@endif</label>
            <label class="lbl-s" style="flex:1;min-width:220px">Nombre<input class="inp" name="nombre" value="{{ old('nombre', $f['n']) }}" maxlength="255" required></label>
            <div style="display:flex;gap:8px;align-items:flex-end"><a class="btn soft sm" href="{{ request()->fullUrlWithQuery(['editar' => null]) }}">Cancelar</a><button class="btn sm" type="submit">Guardar</button></div>
            @if($f['usada'] && empty($f['base']))<span style="flex-basis:100%;font-size:12px;color:#6A6A65">Si cambias el código, todos sus apuntes pasan a la cuenta nueva.</span>@endif
          </form>
        @endif
      @empty
        <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">Ninguna cuenta coincide.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection
