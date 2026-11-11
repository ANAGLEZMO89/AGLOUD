
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Nómina {{ $w['nombre'] }} {{ $mesL }}</title>
<style>body{font:11px Helvetica,Arial,sans-serif;margin:28px;color:#000;background:#fff}table{width:100%;border-collapse:collapse}td,th{padding:4px 6px;vertical-align:top}.r{text-align:right}.b{font-weight:700}.box{border:1px solid #000}.mono{font-family:Courier,monospace}.h{font-size:10px;text-transform:uppercase;letter-spacing:.05em}@page{size:A4;margin:12mm}</style>
</head><body>@include('laboral._recibo-cuerpo')
@if($auto)<script src="{{ asset('js/imprimir.js') }}"></script>@endif
</body></html>
