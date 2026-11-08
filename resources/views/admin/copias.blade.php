@extends('layouts.app')
@php use App\Support\Formato; $ultima = $historial->first(); @endphp
@section('content')
<div class="page">
  <div class="page-h">
    <div class="t"><span class="crumb">Despacho · RGPD</span><h1 class="ttl">Copias de seguridad</h1></div>
    <form method="POST" action="{{ route('admin.copias.ahora') }}">@csrf<button class="btn lime" type="submit" @disabled(! $passOk)>Hacer copia ahora</button></form>
  </div>
  @unless($passOk)<div class="ferr">Falta BACKUP_ARCHIVE_PASSWORD en el archivo .env (mínimo 12 caracteres). Sin ella no se pueden cifrar las copias. Mira la guía de despliegue.</div>@endunless
  <div class="kpis">
    <div class="kpi"><span>Última copia</span><b style="font-size:18px">{{ $ultima ? Formato::fechaHora($ultima->created_at) : '—' }}</b></div>
    <div class="kpi"><span>Resultado</span><b style="font-size:18px;color:{{ $ultima && $ultima->accion === 'backup.error' ? '#B42318' : '#0B0B0B' }}">{{ ! $ultima ? 'Sin copias aún' : ($ultima->accion === 'backup.error' ? 'Error' : 'Correcta') }}</b></div>
    <div class="kpi"><span>Google Drive</span><b style="font-size:18px">{{ $drive->conectado() ? 'Conectado' : 'No conectado' }}</b></div>
    <div class="kpi"><span>Copias en el servidor</span><b>{{ count($locales) }}</b></div>
  </div>
  <div class="g2">
    <div class="col">
      <div class="card" style="gap:6px">
        <span class="ct" style="padding-bottom:6px">Historial</span>
        @forelse($historial as $h)
          <div class="tl"><i style="background:{{ $h->accion === 'backup.error' ? '#B42318' : '#0B0B0B' }}"></i><div><span style="font-size:13.5px">{{ $h->descripcion }}</span><small>{{ Formato::fechaHora($h->created_at) }}</small></div></div>
        @empty
          <span style="font-size:13.5px;color:#6A6A65">Aún no se ha hecho ninguna copia. La automática se ejecuta cada día a las 03:15 si la tarea cron está configurada.</span>
        @endforelse
      </div>
      <div class="card" style="gap:6px">
        <span class="ct" style="padding-bottom:6px">Copias en el servidor (últimos {{ config('agcloud.backup.dias_local') }} días)</span>
        @forelse($locales as $l)
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-top:1px solid #F1F1EC;font-size:13.5px">
            <span class="mono ell" style="font-size:12.5px">{{ $l['nombre'] }}</span>
            <span class="row" style="gap:12px"><span class="c2">{{ Formato::num($l['tamano'] / 1048576, 1) }} MB</span><a class="lnk" href="{{ route('admin.copias.descargar', $l['nombre']) }}">Descargar</a></span>
          </div>
        @empty
          <span style="font-size:13.5px;color:#6A6A65">Ninguna.</span>
        @endforelse
      </div>
    </div>
    <div class="col">
      <div class="card" style="gap:10px">
        <span class="ct">Copia fuera del servidor · Google Drive</span>
        <span style="font-size:13.5px;color:#3C3C3C;line-height:1.5">Cada noche la copia (base de datos + documentos, cifrada con AES-256) se sube a una carpeta «AG Cloud · Copias de seguridad» de tu Google Drive. Se conservan {{ config('agcloud.backup.dias_drive') }} días. AG Cloud solo puede ver los archivos que crea ella misma.</span>
        @if(! $drive->configurado())
          <div class="msg warn">Primero añade GOOGLE_DRIVE_CLIENT_ID y GOOGLE_DRIVE_CLIENT_SECRET al .env (guía, paso «Copias en Google Drive»).</div>
        @elseif($drive->conectado())
          <form method="POST" action="{{ route('admin.copias.drive.desconectar') }}" data-confirm="Las copias dejarán de subirse a Drive. ¿Continuar?">@csrf<button class="btn danger sm" type="submit">Desconectar Google Drive</button></form>
        @else
          <form method="POST" action="{{ route('admin.copias.drive.conectar') }}">@csrf<button class="btn" type="submit">Conectar con Google Drive</button></form>
        @endif
      </div>
      <div class="card dark" style="gap:8px">
        <span style="font:500 11px var(--mono);letter-spacing:.08em;color:#C8F031">IMPORTANTE</span>
        <span style="font-size:13.5px;line-height:1.5">Para restaurar una copia necesitas tres cosas que NO están dentro de ella: la contraseña de las copias (BACKUP_ARCHIVE_PASSWORD), la clave de documentos (DOCS_KEY) y la clave de la aplicación (APP_KEY). Guárdalas en tu gestor de contraseñas.</span>
      </div>
    </div>
  </div>
</div>
@endsection
