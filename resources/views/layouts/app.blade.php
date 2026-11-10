<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
@isset($nCaducan)<meta name="agc-caducan" content="{{ $nCaducan }}">@endisset
<title>{{ $titulo ?? 'AG Cloud' }} · AG Cloud</title>
<link rel="icon" href="{{ asset('img/logo-ga-128.png') }}">
<link rel="preload" href="{{ asset('fonts/onest-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('css/agcloud.css') }}?v={{ config('app.version', '1') }}">
</head>
<body>
@php
    $u = auth()->user();
    $portal = $u->esPortal();
    $menu = $portal ? config('agcloud.menu_portal') : config('agcloud.menu');
    $activo = $activo ?? '';
@endphp
<div class="app" id="app">
  <aside class="side">
    <a class="brand" href="{{ $portal ? route('portal.inicio') : route('escritorio') }}">
      <img src="{{ asset('img/logo-ga-128.png') }}" alt="GA">
      <div><b>AG CLOUD</b><small>{{ $portal ? 'Portal del cliente' : 'por GA AGOPYME' }}</small></div>
    </a>
    <nav class="nav">
      @if($portal)
        <a class="nav-it home {{ $activo === 'inicio' ? 'on' : '' }}" href="{{ route('portal.inicio') }}"><span>Inicio</span></a>
      @else
        <a class="nav-it home {{ $activo === 'escritorio' ? 'on' : '' }}" href="{{ route('escritorio') }}"><span>Escritorio</span></a>
      @endif
      @foreach($menu as $g)
        @if($portal)
          <div class="nav-grp">
            <div class="nav-lbl">{{ $g['label'] }}</div>
            @foreach($g['items'] as [$id, $l, $ruta])
              <a class="nav-it {{ $activo === $id ? 'on' : '' }} {{ $ruta ? '' : 'off' }}" href="{{ $ruta ? route($ruta) : route('portal.preparacion', $id) }}"><span>{{ $l }}</span></a>
            @endforeach
          </div>
        @else
          <div class="nav-grp" data-grp="{{ $g['label'] }}">
            <div class="nav-head">
              <a class="nav-it" href="{{ $g['label'] === 'Gestión' ? route('clientes.index') : '#' }}" data-toggle-grp>{{ $g['label'] }}</a>
              <button class="chev" type="button" data-toggle-grp aria-label="Plegar {{ $g['label'] }}">▾</button>
            </div>
            <div class="nav-sub">
              @foreach($g['items'] as [$id, $l, $ruta])
                <a class="nav-it {{ $activo === $id ? 'on' : '' }} {{ $ruta ? '' : 'off' }}" href="{{ $ruta ? route($ruta) : route('preparacion', $id) }}"><span>{{ $l }}</span>@if($id === 'documentos' && ($nRevisar ?? 0))<span class="nbadge">{{ $nRevisar }}</span>@endif @if($id === 'notificaciones' && ($nNotif ?? 0))<span class="nbadge">{{ $nNotif }}</span>@endif</a>
              @endforeach
            </div>
          </div>
        @endif
      @endforeach
      @if($u->esAdmin())
        <div class="nav-grp">
          <div class="nav-lbl">Despacho</div>
          <a class="nav-it {{ $activo === 'equipo' ? 'on' : '' }}" href="{{ route('admin.equipo') }}"><span>Equipo y accesos</span></a>
          <a class="nav-it {{ $activo === 'auditoria' ? 'on' : '' }}" href="{{ route('admin.auditoria') }}"><span>Registro de accesos</span></a>
          <a class="nav-it {{ $activo === 'copias' ? 'on' : '' }}" href="{{ route('admin.copias') }}"><span>Copias de seguridad</span></a>
        </div>
      @endif
    </nav>
    <div class="side-foot">
      <a class="ucard" href="{{ route('cuenta') }}" title="Mi cuenta y seguridad">
        <div class="av">{{ $portal ? ($ctx?->iniciales() ?? $u->iniciales()) : $u->iniciales() }}</div>
        <div class="tx"><b>{{ $portal ? ($ctx?->nombre ?? $u->name) : $u->name }}</b><small>{{ $portal ? ($ctx?->nif ?? $u->email) : ($u->cargo ?: $u->rolTexto()) }}</small></div>
      </a>
      <form method="POST" action="{{ route('logout') }}">@csrf<button class="side-btn" type="submit">Cerrar sesión</button></form>
    </div>
  </aside>

  <main class="main">
    @hasSection('topbar')
      @yield('topbar')
    @else
      <div class="topbar mbar"><button class="menu-btn" type="button" data-menu aria-label="Menú">☰</button></div>
    @endif
    @if(session('ok'))<div class="page" style="padding-bottom:0"><div class="msg">{{ session('ok') }}</div></div>@endif
    @if(session('aviso'))<div class="page" style="padding-bottom:0"><div class="msg warn">{{ session('aviso') }}</div></div>@endif
    @yield('content')
  </main>
</div>
@yield('overlay')
<script src="{{ asset('js/agcloud.js') }}?v={{ config('app.version', '1') }}"></script>
@stack('scripts')
</body>
</html>
