{{-- Pastilla «empresa con la que trabajas» + Cambiar empresa --}}
<div style="display:flex;align-items:center;gap:10px;padding:6px 8px 6px 6px;border-radius:22px;background:#fff;min-width:0;max-width:100%">
  <span style="width:32px;height:32px;flex:none;border-radius:50%;background:#0B0B0B;color:#C8F031;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">{{ $c->iniciales() }}</span>
  <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell" style="font-size:13.5px;font-weight:700">{{ $c->nombre }}</span><span class="ell" style="font:500 11.5px var(--mono);color:#6A6A65">{{ $c->es_despacho ? (($c->nif ?: 'Sin NIF').' · Despacho propio') : (($c->nif ?: 'Sin NIF').' · '.$c->tipo) }}</span></div>
  <a href="{{ route('escritorio', ['cambiar' => 1, 'volver' => $volver]) }}" style="margin-left:6px;padding:6px 12px;border-radius:14px;background:#F4F4F0;font-size:12.5px;font-weight:600;white-space:nowrap;text-decoration:none">Cambiar empresa</a>
</div>
