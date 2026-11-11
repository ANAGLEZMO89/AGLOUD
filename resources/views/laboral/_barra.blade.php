{{-- Barra de exportación: Excel, CSV e imprimir/PDF --}}
<div class="lb-exp no-print"><span class="t">Exportar</span><a href="{{ request()->fullUrlWithQuery(['exportar' => 'excel']) }}">Excel</a><a href="{{ request()->fullUrlWithQuery(['exportar' => 'csv']) }}">CSV</a><button type="button" data-print>PDF</button></div>
