@extends('layouts.app')
@php use App\Services\Contabilidad; use App\Support\Formato; $dif = $saldoBanco !== null ? round($saldoBanco - $saldoConta, 2) : null; @endphp
@section('content')
<div class="page" style="gap:16px">
  @include('contabilidad._cabecera')
  <datalist id="ctas">@foreach($catalogo as $k => $n)@unless(str_starts_with($k, '572'))<option value="{{ $k }}">{{ $n }}</option>@endunless @endforeach</datalist>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">Movimientos importados · {{ $ej->anio }}</span><span style="font:600 26px/1.1 var(--mono)">{{ Formato::entero($nMovs) }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $bancos[$cta] ?? $cta }}</span></div>
    <div class="card" style="padding:16px 18px;gap:6px;background:{{ $nPend ? '#FCEBCB' : '#E6F6A8' }}"><span style="font-size:13px">Sin conciliar</span><span style="font:600 26px/1.1 var(--mono)">{{ Formato::entero($nPend) }}</span><span style="font-size:12.5px">{{ $nPend ? 'Pendientes de revisar' : 'Todo conciliado' }}</span></div>
    <div class="card" style="padding:16px 18px;gap:6px"><span style="font-size:13px;color:#6A6A65">Saldo en contabilidad ({{ $cta }})</span><span style="font:600 26px/1.1 var(--mono)">{{ Formato::eur($saldoConta) }}</span><span style="font-size:12.5px;color:#3C3C3C">{{ $saldoBanco !== null ? 'Banco a '.Formato::fecha($fechaSaldo).': '.Formato::eur($saldoBanco).($dif ? ' · diferencia '.Formato::eur($dif) : ' · cuadra') : 'El extracto no trae saldo' }}</span></div>
  </div>

  <div class="card no-print" style="padding:14px 18px;gap:12px">
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between">
      <label class="lbl-s">Cuenta bancaria
        <select class="sel" data-nav style="min-width:280px">@foreach($bancos as $k => $l)<option value="{{ route('contabilidad.conciliador', ['cta' => $k, 'ver' => $ver]) }}" @selected($k === $cta)>{{ $k }} · {{ $l }}</option>@endforeach</select>
      </label>
      <form method="POST" action="{{ route('contabilidad.conciliador.importar') }}" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
        @csrf<input type="hidden" name="cta" value="{{ $cta }}">
        <label class="lbl-s">Importar extracto (Excel .xlsx o CSV)<input class="inp" type="file" name="extracto" accept=".xlsx,.csv,.txt" required style="padding-top:8px;min-width:260px"></label>
        <button class="btn" type="submit" style="background:#0B0B0B;color:#fff">Importar</button>
      </form>
      <form method="POST" action="{{ route('contabilidad.conciliador.auto') }}">@csrf<input type="hidden" name="cta" value="{{ $cta }}"><button class="btn lime" type="submit">Conciliar automáticamente</button></form>
    </div>
    @if($errors->has('extracto'))<div class="ferr">{{ $errors->first('extracto') }}</div>@endif
    <span class="hint muted">El extracto necesita una fila de cabecera con «Fecha», «Concepto» e «Importe» (o «Cargo» y «Abono»); «Saldo» es opcional. Los movimientos ya importados no se duplican. La conciliación automática busca un apunte del banco con el mismo importe (±{{ \App\Http\Controllers\ConciliadorController::DIAS }} días) y, si no lo hay, una factura pendiente de cobro o de pago por el mismo total.</span>
  </div>

  <div class="card" style="padding:16px 20px;gap:6px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:8px;flex-wrap:wrap">
      <span style="font-size:14px;font-weight:700">Extracto bancario · cuenta {{ $cta }} · {{ $nPend }} sin conciliar</span>
      <div class="chipbar" style="border-radius:11px;background:#F4F4F0">@foreach(['pendientes' => 'Sin conciliar', 'conciliados' => 'Conciliados', 'todos' => 'Todos'] as $k => $l)<a class="{{ $ver === $k ? 'on' : '' }}" style="padding:6px 12px;border-radius:8px" href="{{ route('contabilidad.conciliador', ['cta' => $cta, 'ver' => $k]) }}">{{ $l }}</a>@endforeach</div>
    </div>
    @forelse($lista as $m)
      <div class="conc-row">
        <div style="display:flex;flex-direction:column;gap:3px;min-width:220px;flex:1"><span class="mono" style="font-size:13px">{{ $m->concepto }}</span><span style="font-size:12px;color:#6A6A65">{{ Formato::fecha($m->fecha) }}{{ $m->saldo !== null ? ' · saldo '.Formato::num($m->saldo) : '' }}</span></div>
        <span class="mono" style="font-weight:700;font-size:14px;min-width:110px;text-align:right;color:{{ $m->importe > 0 ? '#2E3A00' : '#0B0B0B' }}">{{ Formato::eur($m->importe) }}</span>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;min-width:300px">
          @if($m->apunte_id)
            @php $as = $m->apunte?->asiento_id ? \App\Models\Asiento::find($m->apunte->asiento_id) : null; @endphp
            <span class="conc-ok">✓ {{ ['automatica' => 'Conciliado automáticamente', 'manual' => 'Conciliado', 'asiento' => 'Asiento creado', 'cobro' => 'Cobro de factura', 'pago' => 'Pago de factura'][$m->como] ?? 'Conciliado' }}{{ $as ? ' · asiento '.$as->numero : '' }}</span>
            <form method="POST" action="{{ route('contabilidad.conciliador.deshacer', $m) }}">@csrf<button class="lnk" type="submit" style="font-size:12px">Deshacer</button></form>
          @else
            @foreach($sug[$m->id]['apuntes'] ?? [] as $ap)
              <form method="POST" action="{{ route('contabilidad.conciliador.conciliar', $m) }}" style="display:flex;align-items:center;gap:6px">@csrf<input type="hidden" name="apunte_id" value="{{ $ap->id }}">
                <span style="font-size:12px;color:#6A6A65">Asiento {{ $ap->numero }} · {{ Formato::fecha(substr((string) $ap->fecha, 0, 10)) }}</span><button class="conc-btn lime" type="submit">Conciliar</button></form>
            @endforeach
            <form method="POST" action="{{ route('contabilidad.conciliador.asiento', $m) }}" style="display:flex;align-items:center;gap:6px">@csrf
              <input class="inp mono" name="contra" list="ctas" value="{{ ($sug[$m->id]['regla'] ?? null) ? Contabilidad::a9($sug[$m->id]['regla']) : '' }}" placeholder="Cuenta…" style="width:130px;height:32px;font-size:12.5px" required>
              <button class="conc-btn" type="submit">Crear asiento</button>
            </form>
            <form method="POST" action="{{ route('contabilidad.conciliador.borrar', $m) }}" data-confirm="¿Eliminar este movimiento del extracto importado?">@csrf @method('DELETE')<button class="lnk red" type="submit" style="font-size:12px" title="Eliminar del extracto">×</button></form>
          @endif
        </div>
      </div>
    @empty
      <div style="padding:24px 0;text-align:center;font-size:13.5px;color:#6A6A65">{{ $nMovs ? 'No hay movimientos en esta vista.' : 'Importa el extracto del banco para empezar a conciliar.' }}</div>
    @endforelse
  </div>
</div>
@endsection
