@extends('layouts.app')
@php
  use App\Services\Nomina; use App\Support\Formato; use App\Support\Validacion;
  $mesL = Nomina::MESES[$mes - 1].' '.$anio;
  $selOld = old('t');
  $fechaDef = old('fecha', \Illuminate\Support\Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString());
@endphp
@section('content')
<div class="page" style="gap:16px">
  @include('laboral._cabecera')
  @include('laboral._meses', ['ruta' => 'laboral.remesa'])
  <form method="POST" action="{{ route('laboral.remesa.generar') }}" style="display:flex;flex-direction:column;gap:16px" data-lb-remesa>
    @csrf<input type="hidden" name="anio" value="{{ $anio }}"><input type="hidden" name="mes" value="{{ $mes }}">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:15px;font-weight:700">Remesa de transferencias · nóminas de {{ mb_strtolower($mesL) }}</span>@if($ultima)<span class="lb-pill" style="background:#E6F6A8;color:#2E3A00">Última remesa generada el {{ Formato::fechaHora($ultima) }}</span>@endif</div>
      <span style="font-size:12.5px;color:#6A6A65">Genera el fichero de transferencias SEPA (XML ISO 20022 · pain.001.001.03, categoría «SALA») con el líquido de cada nómina, para subirlo a la banca electrónica de la empresa.</span>
      @if(! $bancos)
        <div class="msg warn" style="font-weight:500">{{ $c->nombre }} no tiene cuentas bancarias. Añádelas en Clientes › Datos bancarios.</div>
      @else
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px">
        <label class="lb-f"><span class="lb-l">Cuenta de cargo</span><select class="sel lb-in" name="cuenta">@foreach($bancos as $i => $bk)<option value="{{ $i }}" @selected((string) old('cuenta', collect($bancos)->search(fn ($x) => ! empty($x['principal'])) ?: 0) === (string) $i)>{{ ($bk['entidad'] ?? '') ?: 'Cuenta' }} · {{ Formato::iban($bk['iban'] ?? '') }}</option>@endforeach</select></label>
        <label class="lb-f"><span class="lb-l">Fecha de ejecución</span><input class="inp lb-in" type="date" name="fecha" value="{{ $fechaDef }}"></label>
        <label class="lb-f" style="grid-column:span 2"><span class="lb-l">Concepto</span><input class="inp lb-in" name="concepto" value="{{ old('concepto', 'Nómina '.mb_strtolower($mesL)) }}" maxlength="140"></label>
      </div>
      @endif
    </div>
    <div class="card" style="padding:6px 18px 10px;overflow-x:auto">
      <div style="min-width:760px">
        <div class="lb-grid lb-rem lb-head"><span><input type="checkbox" data-lb-todos checked aria-label="Marcar todos" style="accent-color:#0B0B0B"></span><span>Trabajador</span><span>IBAN</span><span>Banco</span><span class="r">Líquido</span></div>
        @php $tot = 0; @endphp
        @forelse($noms as $x)
          @php $iban = strtoupper(preg_replace('/\s/', '', (string) $x['w']['iban'])); $ok = Validacion::iban($iban); $on = $ok && ($selOld === null || in_array((string) $x['w']['id'], (array) $selOld, true)); if ($on) $tot += $x['t']['liq']; @endphp
          <label class="lb-grid lb-rem lb-row" style="cursor:{{ $ok ? 'pointer' : 'default' }}">
            <span><input type="checkbox" name="t[]" value="{{ $x['w']['id'] }}" data-liq="{{ round($x['t']['liq'], 2) }}" @checked($on) @disabled(! $ok) style="accent-color:#0B0B0B;width:17px;height:17px"></span>
            <span class="ell" style="font-weight:600">{{ $x['w']['nombre'] }}</span>
            <span class="mono12">@if($ok){{ Formato::iban($iban) }}@else<a href="{{ route('laboral.ficha', [$x['w']['id'], 'sec' => 'personal']) }}" style="color:#8A1C1C;font-family:inherit">Falta el IBAN en la ficha</a>@endif</span>
            <span class="ell" style="font-size:12.5px;color:#3C3C3C">{{ $ok ? (Validacion::banco($iban)[0] ?? '') : '' }}</span>
            <span class="r m" style="font-weight:700">{{ Formato::eur($x['t']['liq']) }}</span>
          </label>
        @empty
          <div style="padding:24px 0;text-align:center;font-size:14px;color:#6A6A65">No hay nóminas este mes.</div>
        @endforelse
        <div class="lb-grid lb-rem" style="padding:12px 0 4px;font-weight:700"><span></span><span>Total de la remesa</span><span></span><span></span><span class="r m" data-lb-remtot>{{ Formato::eur($tot) }}</span></div>
      </div>
    </div>
    @if($bancos && $noms)<div style="display:flex;justify-content:flex-end"><button class="btn lg" type="submit">Generar fichero de la remesa</button></div>@endif
  </form>
</div>
@endsection
@section('overlay')
@if($con) @include('laboral._conexion') @endif
@endsection
