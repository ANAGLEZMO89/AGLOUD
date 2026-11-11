@php
  use App\Services\Nomina;
  use App\Support\Formato;
  $red = $con === 'red';
  $missing = $red
    ? array_values(array_filter([! $P['red']['email'] ? 'correo electrónico del autorizado' : null, ! $P['red']['aut'] ? 'número de autorizado RED' : null, ! $P['red']['cert'] ? 'certificado digital' : null]))
    : array_values(array_filter([! $P['sepe']['aut'] ? 'usuario autorizado de Contrat@' : null, ! ($P['sepe']['mismoCert'] ? $P['red']['cert'] : $P['sepe']['cert']) ? 'certificado digital' : null]));
  $sinContrata = \App\Models\Trabajador::where('cliente_id', $c->id)->get()->filter(fn ($t) => ! collect($t->datos['docs'] ?? [])->contains('k', 'contrata'))->count();
  $rows = $red
    ? [['Autorizado RED', $P['red']['aut']], ['Correo del autorizado', $P['red']['email']], ['Certificado digital', $P['red']['cert'] ? $P['red']['cert'].($P['red']['certCad'] ? ' · caduca '.Formato::fecha($P['red']['certCad']) : '') : ''], ['Empresa', $c->nombre.' · '.$c->nif], ['CCC de la empresa', implode(' · ', $P['cccs'])], ['Movimientos pendientes', (string) $pendMov]]
    : [['Usuario Contrat@', $P['sepe']['aut']], ['Certificado digital', $P['sepe']['mismoCert'] ? $P['red']['cert'] : $P['sepe']['cert']], ['Empresa', $c->nombre.' · '.$c->nif], ['CCC de la empresa', implode(' · ', $P['cccs'])], ['Contratos sin comunicar', (string) $sinContrata]];
  $acts = $red ? [['Conectar con el Sistema RED', Nomina::URL['red']], ['Abrir el Sistema de Liquidación Directa', Nomina::URL['red']]] : [['Abrir Contrat@', Nomina::URL['sepe']], ['Abrir Certific@2', Nomina::URL['sepe']]];
  $cerrar = request()->fullUrlWithQuery(['con' => null]);
@endphp
<div class="drawer sm" role="dialog" aria-modal="true" aria-label="Conexión">
  <a class="shade" href="{{ $cerrar }}" aria-label="Cerrar"></a>
  <div class="panel" style="width:min(480px,100%)">
    <div class="ph"><div><b>{{ $red ? 'Seguridad Social · Sistema RED' : 'SEPE · Contrat@ y Certific@2' }}</b><small>{{ $c->nombre }}</small></div><a class="x" href="{{ $cerrar }}" aria-label="Cerrar">×</a></div>
    <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;overflow:auto">
      @if(! $missing)<div class="msg">Conexión configurada con el autorizado del despacho</div>@else<div class="msg warn">Falta configurar: {{ implode(', ', $missing) }}.</div>@endif
      <div class="card" style="gap:0;padding:6px 16px">
        @foreach($rows as [$l, $v])<div style="display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-top:{{ $loop->first ? '0' : '1px solid #F1F1EC' }};font-size:13.5px"><span style="color:#6A6A65">{{ $l }}</span><span style="font-weight:600;text-align:right;overflow-wrap:anywhere">{{ $v !== '' ? $v : '—' }}</span></div>@endforeach
      </div>
      @foreach($acts as [$l, $u])
        @if($missing)<span class="btn white lg" style="opacity:.55;cursor:not-allowed" title="Completa la conexión en Parámetros de laboral antes de conectar.">{{ $l }} ↗</span>
        @else<a class="btn white lg" href="{{ $u }}" target="_blank" rel="noopener">{{ $l }} ↗</a>@endif
      @endforeach
      @if($red && $pendMov && ! $missing)
        <form method="POST" action="{{ route('laboral.comunicar') }}" data-abrir="{{ Nomina::URL['red'] }}">@csrf<button class="btn lg block" type="submit">Comunicar {{ $pendMov }} movimientos pendientes</button></form>
      @endif
      <a class="btn soft" href="{{ route('laboral.parametros') }}">Configurar en Parámetros de laboral</a>
      <p style="margin:0;font-size:12px;color:#6A6A65;line-height:1.45">AG Cloud no envía ficheros a la {{ $red ? 'TGSS' : 'SEPE' }}: abre la sede oficial, donde haces el trámite con el certificado del autorizado, y deja constancia aquí.</p>
    </div>
  </div>
</div>
