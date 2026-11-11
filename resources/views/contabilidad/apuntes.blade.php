@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <datalist id="ctas">@foreach($catalogo as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</datalist>
  @if($ej->cerrado())
    <div class="card"><div class="empty"><b>Ejercicio cerrado</b><span>No admite asientos nuevos. Cambia al ejercicio abierto.</span></div></div>
  @else
  <form method="POST" action="{{ route('contabilidad.apuntes.guardar') }}" class="card" style="padding:18px 20px;gap:14px" data-asiento data-cuentas='@json($catalogo)'>
    @csrf
    @if($a)<input type="hidden" name="editar" value="{{ $a->id }}">@endif
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:16px;font-weight:700">{{ $a ? 'Editar asiento '.$a->numero : 'Nuevo asiento' }}</span>@if($a)<a href="{{ route('contabilidad.apuntes') }}" style="font-weight:600;font-size:13.5px">Nuevo asiento</a>@endif</div>
    @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif
    <div style="display:grid;grid-template-columns:170px minmax(0,1fr);gap:12px">
      <label class="lbl-s">Fecha<input class="inp" type="date" name="fecha" value="{{ old('fecha', $a?->fecha?->toDateString() ?? ($ej->anio == now()->year ? now()->toDateString() : $ej->anio.'-01-01')) }}" min="{{ $ej->anio }}-01-01" max="{{ $ej->anio }}-12-31" required></label>
      <label class="lbl-s">Concepto *<input class="inp" name="concepto" value="{{ old('concepto', $a?->concepto) }}" maxlength="255" required placeholder="Ej.: Nóminas de noviembre"></label>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <div class="ap-lin" style="font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#6A6A65"><span>Cuenta</span><span>Nombre</span><span>Concepto del apunte</span><span style="text-align:right">Debe</span><span style="text-align:right">Haber</span><span></span></div>
      <div data-ap-lines style="display:flex;flex-direction:column;gap:6px">
        @foreach($lineas as $i => $l)
          <div class="ap-lin" data-ap-line>
            <input class="inp mono" name="l[{{ $i }}][c]" value="{{ $l['c'] }}" list="ctas" maxlength="12" data-k="c" placeholder="572">
            <span class="ell" style="font-size:12.5px;color:#3C3C3C" data-ap-nom></span>
            <input class="inp" name="l[{{ $i }}][t]" value="{{ $l['t'] }}" maxlength="255" data-k="t">
            <input class="inp mono" name="l[{{ $i }}][d]" value="{{ $l['d'] }}" inputmode="decimal" data-k="d" style="text-align:right">
            <input class="inp mono" name="l[{{ $i }}][h]" value="{{ $l['h'] }}" inputmode="decimal" data-k="h" style="text-align:right">
            <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-ap-del aria-label="Quitar">×</button>
          </div>
        @endforeach
      </div>
      <template data-ap-tpl><div class="ap-lin" data-ap-line><input class="inp mono" list="ctas" maxlength="12" data-k="c" placeholder="572"><span class="ell" style="font-size:12.5px;color:#3C3C3C" data-ap-nom></span><input class="inp" maxlength="255" data-k="t"><input class="inp mono" inputmode="decimal" data-k="d" style="text-align:right"><input class="inp mono" inputmode="decimal" data-k="h" style="text-align:right"><button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-ap-del aria-label="Quitar">×</button></div></template>
      <div class="ap-lin" style="font-weight:700;font-size:13.5px;padding-top:6px;border-top:1px solid #E6E6E0"><button type="button" class="pillbtn" style="height:32px;justify-self:start" data-ap-add>+ Apunte</button><span></span><span data-ap-cuadre style="font-size:12.5px"></span><span class="mono" style="text-align:right" data-ap-sd>0,00</span><span class="mono" style="text-align:right" data-ap-sh>0,00</span><span></span></div>
    </div>
    <span class="hint muted">Escribe la cuenta con 3 o 4 cifras (572, 4751) o la subcuenta de 9 (400000001). Las cuentas se completan con ceros hasta 9 cifras. Pulsa «=» en un importe para poner la diferencia que falta para cuadrar.</span>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn soft" href="{{ route('contabilidad.diario') }}">Cancelar</a>
      <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">{{ $a ? 'Guardar cambios' : 'Registrar asiento' }}</button>
    </div>
  </form>
  <form method="POST" action="{{ route('contabilidad.subcuentas') }}" class="card" style="padding:16px 18px;gap:10px">
    @csrf
    <b style="font-size:14px">Nueva subcuenta de la empresa</b>
    <div style="display:grid;grid-template-columns:160px minmax(0,1fr) auto;gap:10px;align-items:end">
      <label class="lbl-s">Código (9 cifras)<input class="inp mono" name="codigo" maxlength="9" placeholder="572000001" required></label>
      <label class="lbl-s">Nombre<input class="inp" name="nombre" maxlength="255" placeholder="CaixaBank c/c 1332" required></label>
      <button class="pillbtn ink" type="submit" style="height:40px">Guardar</button>
    </div>
    <span class="hint muted">Los proveedores (400…) y acreedores (410…) se crean en Gestión, y los clientes (430…) se crean solos al contabilizar sus facturas.</span>
  </form>
  @endif
</div>
@endsection
