@extends('layouts.app')
@php use App\Support\Formato; $subirRuta = $portal ? route('portal.documentos.subir') : route('documentos.subir'); @endphp
@section('content')
<div class="page" style="gap:16px">
  <div class="page-h">
    <div class="t"><span class="crumb">{{ $portal ? 'Documentos · Gestor documental' : 'Comunicación · Documentos' }}</span><h1 class="ttl">Documentos</h1></div>
    <div class="row" style="min-width:0;max-width:100%">
      <form method="POST" action="{{ $subirRuta }}" enctype="multipart/form-data" data-upload-form>
        @csrf
        <label class="btn lg" style="cursor:pointer"><span class="plus" style="font-size:14px">+</span>Subir documentos<input type="file" name="archivos[]" multiple class="hidden" data-autoupload></label>
      </form>
      <div style="display:flex;align-items:center;gap:10px;padding:6px 8px 6px 6px;border-radius:22px;background:#fff;min-width:0;max-width:100%">
        <span style="width:32px;height:32px;flex:none;border-radius:50%;background:#0B0B0B;color:#C8F031;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">{{ $c->iniciales() }}</span>
        <div style="display:flex;flex-direction:column;gap:1px;min-width:0"><span class="ell" style="font-size:13.5px;font-weight:700">{{ $c->nombre }}</span><span class="ell" style="font:500 11.5px var(--mono);color:#6A6A65">{{ $c->es_despacho ? 'Documentación interna del despacho' : ($c->nif.' · '.$c->tipo) }}</span></div>
        @unless($portal)<a href="{{ route('escritorio', ['cambiar' => 1, 'volver' => '/documentos']) }}" style="margin-left:6px;padding:6px 12px;border-radius:14px;background:#F4F4F0;font-size:12.5px;font-weight:600;white-space:nowrap;text-decoration:none">Cambiar empresa</a>@endunless
      </div>
    </div>
  </div>

  @if($errors->any())<div class="ferr">{{ $errors->first() }}</div>@endif

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px">
    @foreach($kpis as [$l, $v, $s])<div class="kpi2"><span>{{ $l }}</span><b>{{ $v }}</b><small>{{ $s }}</small></div>@endforeach
  </div>

  {{-- Clasificar antes de guardar --}}
  @if($borradores->count())
  <form class="upbox" method="POST" action="{{ $portal ? route('portal.documentos.confirmar') : route('documentos.confirmar') }}">
    @csrf
    <div style="display:flex;flex-direction:column;gap:2px"><span style="font-size:16px;font-weight:700">Clasificar antes de guardar</span><span style="font-size:12.5px;color:#6A6A65">Nombre propuesto con la nomenclatura del despacho (aaaammdd_tipo_número_remitente) y carpeta según el contenido. Cámbialos si hace falta.</span></div>
    @foreach($borradores as $b)
      @php $dup = \App\Models\Documento::where('cliente_id', $c->id)->gestor()->vivos()->where(fn ($w) => $w->where('sha256', $b->sha256)->orWhere('nombre', $b->nombre))->exists(); @endphp
      <div class="uprow" data-uprow>
        <label><span class="ell">{{ $b->nombre_original }} · {{ $b->kb() }}</span><input class="inp mono" style="font-size:12.5px" name="docs[{{ $b->id }}][nombre]" value="{{ $b->nombre }}" maxlength="180" required></label>
        <label><span>Carpeta</span><select class="sel" name="docs[{{ $b->id }}][carpeta]">@foreach($carpetas as $v => $l)<option value="{{ $v }}" @selected($b->carpeta === $v)>{{ $l }}</option>@endforeach</select></label>
        <button type="button" class="lnk" style="height:40px;font-size:18px;color:#6A6A65;text-decoration:none" data-uprow-del aria-label="Quitar">×</button>
        @if($dup)<span style="grid-column:1 / -1;font-size:12px;font-weight:600;color:#8A1C1C">Posible duplicado: ya hay un documento con el mismo nombre o contenido.</span>@endif
      </div>
    @endforeach
    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button class="btn soft sm" type="submit" form="cancelarSubida">Cancelar</button>
      <button class="btn lime sm" type="submit">{{ $portal ? 'Enviar a mi asesor' : 'Guardar en el gestor' }}</button>
    </div>
  </form>
  <form id="cancelarSubida" method="POST" action="{{ $portal ? route('portal.documentos.cancelar') : route('documentos.cancelar') }}">@csrf</form>
  @endif

  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
    {{-- Árbol de carpetas --}}
    <div class="dside">
      <a class="{{ $fold === 'all' ? 'on' : '' }}" href="{{ $rutaIndex(['f' => null, 'sel' => null]) }}"><span class="ell">Todos los documentos</span><small>{{ $nVivos }}</small></a>
      @foreach($grupos as $g)
        @php $open = $fold === $g['id'] || str_starts_with($fold, $g['id'].'/'); @endphp
        <div class="nav-grp {{ $open ? '' : 'closed' }}" data-dgrp>
          <div class="grp"><button class="tg" type="button" data-dgrp-tog>{{ $open ? '▾' : '▸' }}</button>
            <a class="{{ $fold === $g['id'] ? 'on' : '' }}" href="{{ $rutaIndex(['f' => $g['id'], 'sel' => null]) }}"><span class="ell"><b>{{ $g['l'] }}</b></span><small>{{ $g['n'] }}</small></a></div>
          <div class="nav-sub" style="margin:0;padding:0;border:0">
            @foreach($g['subs'] as $s)
              <a class="sub {{ $fold === $s['id'] ? 'on' : '' }}" href="{{ $rutaIndex(['f' => $s['id'], 'sel' => null]) }}"><span class="ell">{{ $s['l'] }}</span><small>{{ $s['n'] }}</small></a>
            @endforeach
          </div>
        </div>
      @endforeach
      <div style="margin-top:8px;padding-top:8px;border-top:1px solid #F1F1EC">
        <a class="{{ $fold === 'trash' ? 'on' : '' }}" href="{{ $rutaIndex(['f' => 'trash', 'sel' => null]) }}"><span class="ell">Papelera</span><small>{{ $nTrash }}</small></a>
      </div>
    </div>

    <div style="flex:1 1 460px;min-width:0;display:flex;flex-direction:column;gap:12px">
      <div class="row">
        <span style="font-size:17px;font-weight:700;margin-right:auto">{{ $foldTitle }}</span>
        @if($fold === 'trash' && ! $portal && $nTrash)
          <form method="POST" action="{{ route('documentos.vaciar') }}" data-confirm="Se borrarán DEFINITIVAMENTE los {{ $nTrash }} documentos de la papelera. Recuerda que la ley obliga a conservar la documentación fiscal (4 años), contable y mercantil (6 años) y laboral (4 años). ¿Continuar?">@csrf<button class="btn danger sm" type="submit">Vaciar papelera</button></form>
        @endif
        @unless($portal)
          <div style="display:flex;align-items:center;gap:4px"><span style="font-size:12.5px;font-weight:600;color:#6A6A65;padding-right:4px">Índice</span><a class="btn soft xs" href="{{ route('documentos.indice') }}">Excel</a></div>
        @endunless
      </div>
      <form method="GET" action="{{ $portal ? route('portal.documentos') : route('documentos.index') }}" class="row" style="gap:8px">
        @if($fold !== 'all')<input type="hidden" name="f" value="{{ $fold }}">@endif
        @if($oc)<input type="hidden" name="o" value="{{ $oc }}">@endif
        <input class="inp" name="q" value="{{ $q }}" placeholder="Buscar por nombre, tipo o carpeta" style="flex:1 1 220px;width:auto" data-autosubmit>
        <select class="sel" name="y" style="width:auto" data-change-submit><option>Todos</option>@foreach($years as $y)<option @selected($yr === $y)>{{ $y }}</option>@endforeach</select>
        <div class="seg white">
          @foreach($orig as $i => $l)
            @continue($portal && in_array($i, [1, 3], true))
            <a class="{{ $oc === $i ? 'on' : '' }}" href="{{ $rutaIndex(['o' => $i ?: null, 'sel' => null]) }}">{{ $portal && $i === 2 ? 'Subidos por mí' : $l }}</a>
          @endforeach
        </div>
      </form>
      <form method="POST" action="{{ $subirRuta }}" enctype="multipart/form-data" data-upload-form>
        @csrf
        <label class="dropzone" data-dropzone><span>Arrastra aquí los archivos o <b style="text-decoration:underline">elígelos</b>: se renombran y se clasifican solos</span><input type="file" name="archivos[]" multiple data-autoupload></label>
      </form>

      <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">
        <div class="dlist">
          @forelse($lista as $d)
            @php $k = $d->caducaPronto(); @endphp
            <a class="drow {{ $sel && $sel->id === $d->id ? 'on' : '' }}" href="{{ $rutaIndex(['sel' => $d->id]) }}">
              <span class="n">{{ $d->nombre }}</span>
              <span class="r">{{ Formato::fecha($d->fecha) }}</span>
              <div class="m"><span>{{ $d->carpetaTexto() }}</span><span>· {{ $d->kb() }}</span>
                @if($d->por_revisar && ! $portal)<span class="badge-rev">Por revisar</span>@endif
                @if($d->compartido && ! $portal)<span class="badge-sh">En el portal</span>@endif
                @if($k)<span class="badge-cad">{{ $d->caduca->isPast() ? 'Caducado' : 'Caduca '.Formato::fecha($d->caduca) }}</span>@endif
              </div>
              <span class="r">{{ $d->origen === 'Portal del cliente' ? ($portal ? ((int) $d->subido_por === (int) auth()->id() ? 'Subido por ti' : 'Subido por '.explode(' ', $d->autor?->name ?? 'tu empresa')[0]) : 'Portal del cliente') : 'Despacho' }}</span>
            </a>
          @empty
            <div style="padding:28px 20px;font-size:14px;color:#6A6A65">No hay documentos en esta vista.</div>
          @endforelse
        </div>

        @if($sel)
          @php $keep = $sel->conservarHasta(); $edit = Gate::allows('update', $sel); @endphp
          <div class="dsel">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start"><div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-size:12px;color:#6A6A65">{{ $sel->carpetaTexto() }}</span><span style="font:600 14px var(--mono);overflow-wrap:anywhere">{{ $sel->nombre }}</span></div><a href="{{ $rutaIndex(['sel' => null]) }}" style="font-size:22px;line-height:1;color:#6A6A65;text-decoration:none" aria-label="Cerrar">×</a></div>
            @if($sel->esImagen())
              <img class="dprev" src="{{ route('documentos.ver', $sel) }}" alt="{{ $sel->nombre }}">
            @elseif($sel->esPdf())
              <iframe class="dprev" src="{{ route('documentos.ver', $sel) }}" title="{{ $sel->nombre }}"></iframe>
            @else
              <div style="padding:24px;border-radius:10px;background:#F4F4F0;font-size:13px;color:#6A6A65;text-align:center">Vista previa no disponible para este tipo de archivo. Descárgalo para abrirlo.</div>
            @endif
            @if($sel->por_revisar && ! $portal)
              <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:10px;background:#FCEBCB;color:#6E4300;font-size:13px;font-weight:600"><span>Subido por el cliente desde su portal</span>
                <form method="POST" action="{{ route('documentos.revisado', $sel) }}">@csrf<input type="hidden" name="f" value="{{ $fold }}"><button class="btn white xs" type="submit">Marcar revisado</button></form></div>
            @endif
            <div class="row" style="gap:8px">
              <a class="btn sm" href="{{ route('documentos.descargar', $sel) }}">Descargar</a>
              @unless($portal)
                <form method="POST" action="{{ route('documentos.compartir', $sel) }}">@csrf<input type="hidden" name="f" value="{{ $fold }}"><button class="btn soft sm" type="submit">{{ $sel->compartido ? 'Quitar del portal' : 'Compartir en el portal' }}</button></form>
                @if($c->telefono)<a class="btn soft sm" target="_blank" rel="noopener noreferrer" href="https://wa.me/{{ $c->whatsapp() }}?text={{ rawurlencode('Hola, '.$c->nombre.'. Te dejamos en tu portal de GA AGOPYME el documento «'.$sel->nombre.'».') }}">Avisar al cliente</a>@endif
              @endunless
              @if($edit)
                @if($sel->eliminado_at)
                  <form method="POST" action="{{ route('documentos.restaurar', $sel) }}">@csrf<button class="btn lime sm" type="submit">Restaurar</button></form>
                @else
                  <form method="POST" action="{{ route('documentos.papelera', $sel) }}" @if($keep && $keep[0]->isFuture()) data-confirm="La ley obliga a conservar este documento hasta el {{ Formato::fecha($keep[0]) }} ({{ $keep[1] }}). ¿Moverlo a la papelera de todos modos?" @endif>@csrf<input type="hidden" name="f" value="{{ $fold }}"><button class="btn danger sm" type="submit">Eliminar</button></form>
                @endif
              @endif
            </div>
            @if($edit && ! $sel->eliminado_at)
              <div style="display:flex;flex-direction:column;gap:8px">
                <form method="POST" action="{{ route('documentos.renombrar', $sel) }}" style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;font-weight:600">
                  @csrf <input type="hidden" name="f" value="{{ $fold }}">
                  <span>Nombre</span>
                  <div style="display:flex;gap:6px"><input class="inp mono" style="font-size:12.5px;flex:1" name="nombre" value="{{ $sel->nombre }}" maxlength="180" data-ren><button class="btn soft sm" type="submit" style="height:40px">Guardar</button></div>
                  <button type="button" class="lnk" style="align-self:flex-start;font-size:12px" data-ren-sug="{{ \App\Support\ClasificadorDocumentos::proponer($sel->nombre_original, $c->nombre)['nombre'] }}">Proponer nombre con la nomenclatura</button>
                </form>
                <form method="POST" action="{{ route('documentos.mover', $sel) }}" style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;font-weight:600">
                  @csrf <input type="hidden" name="f" value="{{ $fold }}">
                  <span>Carpeta</span>
                  <select class="sel" name="carpeta" data-change-submit>@foreach($carpetas as $v => $l)<option value="{{ $v }}" @selected($sel->carpeta === $v)>{{ $l }}</option>@endforeach</select>
                </form>
              </div>
            @endif
            <div>
              @foreach([['Tipo', $sel->tipoTexto()], ['Fecha', Formato::fecha($sel->fecha)], ['Tamaño', $sel->kb()], ['Origen', $sel->origen.' · '.($sel->autor?->name ?? '—')], ['Caduca', $sel->caduca ? Formato::fecha($sel->caduca) : '—'], ['Conservar', $keep ? 'Hasta el '.Formato::fecha($keep[0]).' ('.$keep[1].')' : 'Mientras siga vigente'], ['Archivo original', $sel->nombre_original]] as [$l, $v])
                <div class="dmeta"><span>{{ $l }}</span><span>{{ $v }}</span></div>
              @endforeach
            </div>
          </div>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
