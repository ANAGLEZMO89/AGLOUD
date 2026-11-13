<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $titulo ?? 'Acceso' }} · AG Cloud</title>
<link rel="icon" href="{{ asset('img/logo-ga-128.png') }}">
<link rel="stylesheet" href="{{ asset('css/agcloud.css') }}?v={{ @filemtime(public_path('css/agcloud.css')) }}">
</head>
<body>
<div class="auth">
  <div class="auth-card">
    <div class="hd"><img src="{{ asset('img/logo-ga-128.png') }}" alt="GA"><div><b>AG CLOUD</b><small>{{ $sub ?? 'GA AGOPYME' }}</small></div></div>
    @yield('content')
  </div>
</div>
<script src="{{ asset('js/agcloud.js') }}?v={{ @filemtime(public_path('js/agcloud.js')) }}"></script>
</body>
</html>
