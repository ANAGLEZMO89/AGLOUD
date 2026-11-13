{{-- Barra común de los listados: filtros (slot), imprimir y exportar --}}
<div class="card no-print" style="padding:12px 16px;flex-direction:row;flex-wrap:wrap;align-items:center;gap:10px">
  {{ $slot ?? '' }}
  <div style="margin-left:auto;display:flex;align-items:center;gap:4px;padding:3px;border-radius:20px;background:#F4F4F0">
    <span style="padding:0 8px 0 10px;font-size:13px;font-weight:700">Exportar</span>
    <a class="pillbtn" style="height:30px;font-size:12.5px" href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">Excel / CSV</a>
    <button class="pillbtn" type="button" style="height:30px;font-size:12.5px" data-print>Imprimir / PDF</button>
  </div>
</div>
