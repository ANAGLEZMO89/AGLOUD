@extends('layouts.app')
@section('content')
<div style="flex:1;padding:28px;display:flex;align-items:center;justify-content:center">
  <div class="card" style="max-width:440px;padding:32px;align-items:flex-start">
    <span class="lbl-mono">MÓDULO EN PREPARACIÓN</span>
    <h1 class="ttl" style="font-size:26px">{{ $nombre }}</h1>
    <p style="margin:0;font-size:14px;color:#3C3C3C;line-height:1.5">Lo activamos en una próxima fase. Por ahora están disponibles {{ $portal ? 'el gestor documental y tu cuenta' : 'Clientes y Documentos' }}.</p>
    <a class="btn lime" style="margin-top:6px" href="{{ $portal ? route('portal.documentos') : route('clientes.index') }}">{{ $portal ? 'Ir a Documentos' : 'Ir a Clientes' }}</a>
  </div>
</div>
@endsection
