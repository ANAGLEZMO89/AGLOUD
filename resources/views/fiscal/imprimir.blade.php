@php use App\Support\Formato; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modelo {{ $code }} · {{ $perLbl }} · {{ $c->nombre }}</title>
<style>
  body{font-family:Geist,system-ui,-apple-system,"Segoe UI",sans-serif;color:#0B0B0B;margin:32px;font-size:13px}
  h1{font-size:20px;margin:0 0 4px} .sub{color:#6A6A65;margin-bottom:18px}
  table{width:100%;border-collapse:collapse} td{padding:6px 4px;border-bottom:1px solid #E6E6E0;vertical-align:top}
  td.n{width:48px;font-family:"Geist Mono",monospace;color:#6A6A65} td.v{text-align:right;font-family:"Geist Mono",monospace;white-space:nowrap}
  tr.h td{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6A6A65;padding-top:14px}
  tr.r td{font-weight:700;background:#C8F031}
  .pie{margin-top:18px;font-size:11px;color:#6A6A65}
</style>
</head>
<body>
  <h1>Modelo {{ $code }} · {{ $nombre }}</h1>
  <div class="sub">{{ $c->nombre }} · {{ $c->nif ?: 'Sin NIF' }} · {{ $perLbl }}{{ $plazo ? ' · Plazo: '.Formato::fecha($plazo) : '' }}</div>
  <table>
    @foreach($calc['filas'] as $x)
      @if(isset($x['h']))<tr class="h"><td colspan="3">{{ $x['h'] }}</td></tr>
      @else<tr class="{{ $x['res'] ? 'r' : '' }}"><td class="n">{{ $x['n'] }}</td><td>{{ $x['l'] }}</td><td class="v">{{ $x['txt'] }}</td></tr>@endif
    @endforeach
    @if($calc['terc'])
      <tr class="h"><td colspan="3">Terceros declarados</td></tr>
      @foreach($calc['terc'] as $t)<tr><td class="n"></td><td>{{ $t[1] }} · {{ $t[0] }} · {{ $t[2] }}</td><td class="v">{{ Formato::eur($t[3]) }}</td></tr>@endforeach
    @endif
  </table>
  <div class="pie">Borrador de trabajo preparado con AG Cloud a partir del libro diario. No es la declaración presentada: la presentación se realiza en la Sede electrónica de la AEAT.</div>
  <script src="{{ asset('js/imprimir.js') }}"></script>
</body>
</html>
