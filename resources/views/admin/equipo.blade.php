@extends('layouts.app')
@php use App\Support\Formato; @endphp
@section('content')
<div class="page">
  <div class="page-h"><div class="t"><span class="crumb">Despacho</span><h1 class="ttl">Equipo y accesos</h1></div></div>
  <div class="kpis">
    <div class="kpi"><span>Personas del despacho activas</span><b>{{ $equipo->where('activo', true)->count() }}</b></div>
    <div class="kpi"><span>Con verificación en dos pasos</span><b>{{ $equipo->where('activo', true)->filter->tiene2fa()->count() }}</b></div>
    <div class="kpi"><span>Usuarios del portal activos</span><b>{{ Formato::entero($nPortal) }}</b></div>
  </div>
  <div class="g2">
    <div class="card" style="gap:6px;overflow-x:auto">
      <span class="ct" style="padding-bottom:6px">Equipo</span>
      @foreach($equipo as $u)
        <div style="display:grid;grid-template-columns:minmax(0,1.5fr) 170px auto;gap:12px;align-items:center;padding:11px 0;border-top:1px solid #F1F1EC;font-size:13.5px;min-width:560px;{{ $u->activo ? '' : 'opacity:.55' }}">
          <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><b class="ell" style="font-weight:600">{{ $u->name }}{{ $u->id === auth()->id() ? ' (tú)' : '' }}</b><span class="mono ell" style="font-size:12px;color:#6A6A65">{{ $u->email }}</span>
            <span style="font-size:12px;color:#6A6A65">{{ ! $u->activo ? 'Desactivado' : ($u->must_change_password ? 'Invitado · pendiente de entrar' : 'Último acceso '.(Formato::fechaHora($u->ultimo_acceso_at) ?: '—')) }} · 2FA {{ $u->tiene2fa() ? 'sí' : 'no' }}</span></div>
          <form method="POST" action="{{ route('admin.equipo.rol', $u) }}">@csrf<select class="sel" name="role" data-change-submit @disabled($u->id === auth()->id())>@foreach(['admin_gestoria' => 'Administración', 'asesor' => 'Asesor'] as $r => $l)<option value="{{ $r }}" @selected($u->role === $r)>{{ $l }}</option>@endforeach</select></form>
          <div class="row" style="gap:12px;justify-content:flex-end">
            @if($u->id !== auth()->id())
              <form method="POST" action="{{ route('admin.equipo.reenviar', $u) }}" data-confirm="Se generará una contraseña provisional nueva y se enviará por correo. ¿Continuar?">@csrf<button class="lnk" type="submit">Reenviar acceso</button></form>
              <form method="POST" action="{{ route('admin.equipo.activo', $u) }}">@csrf<button class="lnk {{ $u->activo ? 'red' : '' }}" type="submit">{{ $u->activo ? 'Desactivar' : 'Reactivar' }}</button></form>
            @endif
          </div>
        </div>
      @endforeach
    </div>
    <form class="card" method="POST" action="{{ route('admin.equipo.store') }}" style="gap:14px">
      @csrf
      <span class="ct">Añadir persona al equipo</span>
      <label class="fld"><span>Nombre y apellidos</span><input class="inp" name="name" value="{{ old('name') }}" required></label>
      <label class="fld"><span>Correo</span><input class="inp" type="email" name="email" value="{{ old('email') }}" required></label>
      <label class="fld"><span>Rol</span><select class="sel" name="role"><option value="asesor">Asesor · trabaja con todos los clientes</option><option value="admin_gestoria">Administración · además gestiona el equipo y ve el registro de accesos</option></select></label>
      <label class="fld"><span>Cargo (opcional)</span><input class="inp" name="cargo" value="{{ old('cargo') }}" placeholder="Asesora fiscal · Huelva"></label>
      @if($errors->any())<span class="hint err">{{ $errors->first() }}</span>@endif
      <button class="btn" type="submit" style="align-self:flex-start">Enviar invitación</button>
    </form>
  </div>
</div>
@endsection
