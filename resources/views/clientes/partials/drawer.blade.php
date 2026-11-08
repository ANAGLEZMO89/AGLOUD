@php
    use App\Support\Formato;
    use App\Support\SeccionesAlta;
    $cerrar = $nuevo ? route('clientes.index') : route('clientes.show', [$c, 'tab' => 'alta']);
    $esForm = in_array($sec, ['general', 'cuota'], true);
    $n = count($secs);
    $old = fn ($k, $def = null) => old($k, $def);
@endphp
<div class="drawer" role="dialog" aria-modal="true" aria-label="{{ $nuevo ? 'Alta de cliente' : 'Editar ficha del cliente' }}">
  <a class="shade" href="{{ $cerrar }}" aria-label="Cerrar"></a>
  <div class="panel">
    <div class="ph">
      <div style="min-width:0"><b>{{ $nuevo ? 'Alta de cliente' : 'Editar ficha del cliente' }}</b><small class="ell" style="display:block">{{ ($c->nombre ?: 'Nuevo cliente') }} · {{ $c->tipo }}</small></div>
      <a class="x" href="{{ $cerrar }}" aria-label="Cerrar">×</a>
    </div>
    <div class="pb">
      <nav class="pnav">
        @foreach($secs as $id => $l)
          @php
            $i = $loop->index;
            $spec0 = SeccionesAlta::spec($id, $c->tipo);
            $badge = $spec0 ? (count($c->seccion($id)) ?: '') : ($id === 'general' && $c->exists ? '✓' : ($id === 'cuota' && $c->cuota_importe ? '✓' : ($id === 'logo' && ($c->logo_documento_id || $c->firma_documento_id) ? '✓' : '')));
            $deshab = $nuevo && $id !== 'general';
          @endphp
          @if($deshab)
            <a class="{{ $id === $sec ? 'on' : '' }}" style="opacity:.45;cursor:not-allowed" title="Guarda primero los datos generales"><i>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</i><span>{{ $l }}</span></a>
          @else
            <a class="{{ $id === $sec ? 'on' : '' }}" href="{{ $nuevo ? route('clientes.create') : route('clientes.edit', [$c, 'sec' => $id]) }}"><i>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</i><span>{{ $l }}</span><em>{{ $badge }}</em></a>
          @endif
        @endforeach
      </nav>
      <div class="pc">
        <div style="display:flex;flex-direction:column;gap:3px"><span class="sec-t">{{ $secs[$sec] }}</span><span class="sec-d">{{ SeccionesAlta::DESC[$sec] }}</span></div>

        @if($errors->has('form'))<div class="ferr">{{ $errors->first('form') }}</div>@endif
        @if($errors->any() && ! $errors->has('form') && ! $errors->has('sub'))<div class="ferr">{{ $errors->first() }}</div>@endif

        {{-- ============ DATOS GENERALES ============ --}}
        @if($sec === 'general')
        <form id="altaForm" method="POST" action="{{ $nuevo ? route('clientes.store') : route('clientes.update', $c) }}" class="card" style="gap:16px">
          @csrf @if(! $nuevo) @method('PUT') @endif
          <input type="hidden" name="sec" value="general">
          <div class="fld"><span>Tipo de cliente</span>
            <div class="seg grid">
              @foreach(config('agcloud.tipos_cliente') as $t)
                <label><input type="radio" name="tipo" value="{{ $t }}" @checked($old('tipo', $c->tipo) === $t)><span>{{ $t }}</span></label>
              @endforeach
            </div>
          </div>
          <div class="fgrid">
            <label class="fld full"><span>Nombre o razón social *</span><input class="inp" name="nombre" value="{{ $old('nombre', $c->nombre) }}" placeholder="Ej. Bar El Puerto SL" required maxlength="200"></label>
            <div class="fld"><span>NIF / NIE / CIF *</span>
              <div class="nifwrap"><input class="inp mono upper" name="nif" value="{{ $old('nif', $c->nif) }}" placeholder="B21000000" data-nif required maxlength="15" autocomplete="off"><span class="nifmark" data-nif-mark></span></div>
              <span class="hint" data-nif-msg></span>
            </div>
            <label class="fld"><span>Nombre comercial</span><input class="inp" name="comercial" value="{{ $old('comercial', $c->comercial) }}"></label>
            <label class="fld"><span>Fecha de alta</span><input class="inp" type="date" name="fecha_alta" value="{{ $old('fecha_alta', $c->fecha_alta?->toDateString()) }}"></label>
            <label class="fld"><span>Email</span><input class="inp" type="email" name="email" value="{{ $old('email', $c->email) }}" data-portal-email></label>
            <label class="fld"><span>Teléfono</span><input class="inp" type="tel" name="telefono" value="{{ $old('telefono', $c->telefono) }}"></label>
            <label class="fld"><span>Asesor asignado</span><select class="sel" name="asesor_id">@foreach($asesores as $a)<option value="{{ $a->id }}" @selected((int) $old('asesor_id', $c->asesor_id) === $a->id)>{{ $a->name }}</option>@endforeach</select></label>
            @if(! $nuevo)
            <label class="fld"><span>Estado</span><select class="sel" name="estado">@foreach(array_keys(config('agcloud.estados_cliente')) as $e)<option value="{{ $e }}" @selected($old('estado', $c->estado) === $e)>{{ $e }}</option>@endforeach</select></label>
            @endif
            <label class="fld full"><span>Domicilio fiscal</span><input class="inp" name="domicilio" value="{{ $old('domicilio', $c->domicilio) }}" placeholder="Calle, número, piso"></label>
            <label class="fld"><span>Código postal</span><input class="inp" name="cp" value="{{ $old('cp', $c->cp) }}" data-cp inputmode="numeric" maxlength="5"><span class="hint" data-cp-hint></span></label>
            <label class="fld"><span>Municipio</span><input class="inp" name="municipio" value="{{ $old('municipio', $c->municipio) }}"></label>
            <label class="fld"><span>Provincia</span><select class="sel" name="provincia" data-provincia>@foreach(config('agcloud.provincias') as $p)<option value="{{ $p }}" @selected($old('provincia', $c->provincia) === $p)>{{ $p }}</option>@endforeach</select></label>
          </div>
          @php $tienePortal = $c->exists && $c->usuarios()->exists(); @endphp
          <div class="boxsoft">
            @if($tienePortal)
              <span style="font-size:13.5px;font-weight:600">Acceso al portal del cliente activo</span>
              <span class="hint muted">Gestiona usuarios, reenvíos y bajas desde la pestaña «Accesos al portal» de la ficha.</span>
            @else
              <label class="chk"><input type="checkbox" name="portal" value="1" data-portal-toggle @checked($old('portal', $nuevo ? '1' : null))>Dar acceso al portal del cliente</label>
              <div data-portal-box class="{{ $old('portal', $nuevo ? '1' : null) ? '' : 'hidden' }}" style="display:flex;flex-direction:column;gap:6px">
                <span class="flbl">Contraseña provisional · <span data-portal-user>{{ $old('email', $c->email) ? 'Usuario: '.$old('email', $c->email) : 'El usuario será el correo del cliente' }}</span></span>
                <div class="row" style="gap:6px">
                  <input class="inp mono" type="password" name="portal_pass" value="" placeholder="Se generará automáticamente" style="flex:1 1 200px" data-pass autocomplete="new-password">
                  <button class="btn white sm" type="button" style="height:40px" data-pass-show>Ver</button>
                  <button class="btn soft sm" type="button" style="height:40px" data-pass-gen>Generar</button>
                </div>
                <span class="hint muted">Al guardar el alta se envía automáticamente el correo de bienvenida con la dirección del portal, el usuario y esta contraseña. El cliente tendrá que cambiarla la primera vez que entre.</span>
              </div>
            @endif
          </div>
        </form>
        @endif

        {{-- ============ CUOTA ============ --}}
        @if($sec === 'cuota')
        <form id="altaForm" method="POST" action="{{ route('clientes.update', $c) }}" class="card" style="gap:16px" data-cuota>
          @csrf @method('PUT')
          <input type="hidden" name="sec" value="cuota">
          <div class="fgrid g200">
            <label class="fld"><span>Importe sin IVA (€) *</span><input class="inp big" name="cuota_importe" value="{{ $old('cuota_importe', $c->cuota_importe ? Formato::num($c->cuota_importe) : '') }}" inputmode="decimal" placeholder="0,00" data-c-imp></label>
            <label class="fld"><span>Periodicidad</span><select class="sel" name="cuota_periodicidad" data-c-per>@foreach(config('agcloud.cuota.periodicidad') as $p)<option @selected($old('cuota_periodicidad', $c->cuota_periodicidad) === $p)>{{ $p }}</option>@endforeach</select></label>
            <label class="fld"><span>IVA</span><select class="sel" name="cuota_iva" data-c-iva>@foreach(config('agcloud.cuota.iva') as $v => $l)<option value="{{ $v }}" @selected((string) $old('cuota_iva', $c->cuota_iva) === (string) $v)>{{ $l }}</option>@endforeach</select></label>
            <label class="fld"><span>Fecha de inicio</span><input class="inp" type="date" name="cuota_inicio" value="{{ $old('cuota_inicio', $c->cuota_inicio?->toDateString()) }}"></label>
            <label class="fld"><span>Forma de cobro</span><select class="sel" name="cuota_forma_cobro">@foreach(config('agcloud.cuota.forma_cobro') as $p)<option @selected($old('cuota_forma_cobro', $c->cuota_forma_cobro) === $p)>{{ $p }}</option>@endforeach</select></label>
            <label class="fld"><span>Cuenta de cargo</span><select class="sel" name="cuota_cuenta">
              <option value="">{{ count($c->seccion('bancos')) ? 'Elige una cuenta' : 'Sin cuentas: añádelas en Datos bancarios' }}</option>
              @foreach($c->seccion('bancos') as $i => $b)<option value="{{ $i }}" @selected((string) $old('cuota_cuenta', $c->cuota_cuenta) === (string) $i)>{{ Formato::iban($b['iban'] ?? '') }}{{ ! empty($b['principal']) ? ' · Principal' : '' }}</option>@endforeach
            </select></label>
            <label class="fld full"><span>Concepto en factura</span><input class="inp" name="cuota_concepto" value="{{ $old('cuota_concepto', $c->cuota_concepto) }}"></label>
            <label class="fld full"><span>Observaciones</span><textarea class="txa" name="cuota_observaciones" placeholder="Servicios incluidos, revisiones de precio, descuentos pactados…">{{ $old('cuota_observaciones', $c->cuota_observaciones) }}</textarea></label>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <div class="sumbox"><span>Base</span><b data-c-base>0,00 €</b></div>
            <div class="sumbox"><span>IVA</span><b data-c-ivat>0,00 €</b></div>
            <div class="sumbox l"><span>Total por periodo</span><b data-c-tot>0,00 €</b></div>
          </div>
          <span style="font-size:13px;color:#6A6A65" data-c-anual>Escribe el importe acordado con el cliente.</span>
        </form>
        @endif

        {{-- ============ SECCIONES DE LISTA ============ --}}
        @if($spec)
        <div class="card" style="gap:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="font-size:13.5px;color:#6A6A65">{{ count($lista) ? count($lista).(count($lista) === 1 ? ' registro' : ' registros') : 'Ningún registro todavía' }}</span>
            <a class="btn lime sm" href="{{ route('clientes.edit', [$c, 'sec' => $sec, 'item' => 'nuevo']) }}">+ Añadir</a>
          </div>

          @if($sub)
          <form class="subform" method="POST" action="{{ route('clientes.seccion.guardar', [$c, $sec]) }}" enctype="multipart/form-data">
            @csrf
            @if($sub['idx'] !== null)<input type="hidden" name="idx" value="{{ $sub['idx'] }}">@endif
            <span class="st">{{ $sub['idx'] !== null ? 'Editar '.$spec['one'] : 'Nuevo · '.$spec['one'] }}</span>
            <div class="fgrid g180">
              @foreach($spec['fields'] as $f)
                @php [$k, $l, $t] = $f; $opts = $f[3] ?? null; $full = $f[4] ?? false; $ph = $f[5] ?? ''; $v = $sub['d'][$k] ?? ''; @endphp
                <div class="fld" style="{{ $full ? 'grid-column:1 / -1' : '' }}">
                  <span>{{ $l }}</span>
                  @switch($t)
                    @case('sel')
                      <select class="sel" name="d[{{ $k }}]">@foreach($opts as $o)<option @selected($v === $o)>{{ $o }}</option>@endforeach</select>
                      @break
                    @case('tog')
                      <label class="tog"><input type="checkbox" name="d[{{ $k }}]" value="1" @checked(! empty($v))><span class="sw"></span><small>{{ ! empty($v) ? 'Sí' : 'No' }}</small></label>
                      @break
                    @case('file')
                      <label class="filebtn"><input type="file" name="archivo" accept="{{ $opts }}" data-file-name><span>{{ is_array($v) && ! empty($v['name']) ? $v['name'] : 'Ningún archivo' }}</span><b>Subir</b></label>
                      @if(is_array($v) && ! empty($v['id']))<a class="hint" href="{{ route('documentos.descargar', $v['id']) }}">Descargar el archivo guardado</a>@endif
                      @break
                    @case('password')
                      <input class="inp" type="password" name="d[{{ $k }}]" value="" placeholder="{{ ! empty($v) ? '•••••••• (guardada)' : '' }}" autocomplete="new-password">
                      @break
                    @case('combo')
                      <input class="inp" name="d[{{ $k }}]" value="{{ $v }}" placeholder="{{ $ph }}" autocomplete="off" data-combo="{{ $opts }}">
                      @break
                    @default
                      <input class="inp {{ in_array($k, ['iban', 'nif', 'nifT', 'ccc'], true) ? 'mono' : '' }}" type="{{ $t === 'number' ? 'number' : ($t === 'date' ? 'date' : 'text') }}" name="d[{{ $k }}]" value="{{ is_scalar($v) ? $v : '' }}" placeholder="{{ $ph }}" @if($k === 'iban') data-iban @endif @if($k === 'nif') data-nif @endif @if($k === 'ccc') data-ccc @endif @if($k === 'cp') data-cp @endif @if($t === 'number') min="0" @endif>
                      @if($k === 'iban')<span class="hint" data-iban-hint></span>@endif
                      @if($k === 'nif')<span class="hint" data-nif-msg></span>@endif
                      @if($k === 'ccc' || $k === 'cp')<span class="hint" data-prov-hint></span>@endif
                  @endswitch
                </div>
              @endforeach
            </div>
            @if($sec === 'certs')<span class="hint muted">Si subes un .p12 o .pfx con su contraseña, al guardar se leen automáticamente el titular, el NIF, el emisor y las fechas. El archivo y la contraseña se guardan cifrados.</span>@endif
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap">
              @error('sub')<span style="margin-right:auto;font-size:13px;color:#B42318;font-weight:600">{{ $message }}</span>@enderror
              <a class="btn soft" style="height:40px;border-radius:20px;background:#EDEDE8" href="{{ route('clientes.edit', [$c, 'sec' => $sec]) }}">Cancelar</a>
              <button class="btn" style="height:40px;border-radius:20px" type="submit">Aceptar</button>
            </div>
          </form>
          @endif

          @if(count($lista))
            <div class="tbl">
              <div class="ftbl h">@foreach($spec['cols'] as $h)<span>{{ $h }}</span>@endforeach<span class="act"></span></div>
              @foreach($lista as $i => $d)
                @php [$cells, $tag, $tbg, $tfg] = SeccionesAlta::row($sec, $d); @endphp
                <div class="ftbl">
                  @foreach($cells as $cv)<span title="{{ $cv }}">{{ $cv }}</span>@endforeach
                  <div class="act">
                    @if($tag)<span class="tag" style="{{ $tbg ? 'background:'.$tbg.';color:'.$tfg : '' }}">{{ $tag }}</span>@endif
                    <a class="lnk" href="{{ route('clientes.edit', [$c, 'sec' => $sec, 'item' => $i]) }}">Editar</a>
                    <form method="POST" action="{{ route('clientes.seccion.borrar', [$c, $sec, $i]) }}" data-confirm="¿Eliminar este registro?">@csrf @method('DELETE')<button class="lnk red" type="submit">Eliminar</button></form>
                  </div>
                </div>
              @endforeach
            </div>
          @elseif(! $sub)
            <div class="emptybox">{{ $spec['empty'] }}</div>
          @endif
        </div>
        @endif

        {{-- ============ LOGO Y FIRMA ============ --}}
        @if($sec === 'logo')
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:16px">
          @foreach([['logo', 'Logo', 'Se usa en facturas, presupuestos y en el portal del cliente.'], ['firma', 'Firma', 'Firma escaneada para escritos y autorizaciones. No sustituye al certificado digital.']] as [$slot, $l, $dsc])
            @php $has = (bool) $c->{$slot.'_documento_id'}; @endphp
            <div class="card" style="gap:10px">
              <div class="card-h"><span class="ct">{{ $l }}</span>
                @if($has)<form method="POST" action="{{ route('clientes.imagen.quitar', [$c, $slot]) }}">@csrf @method('DELETE')<button class="lnk red" type="submit">Quitar</button></form>@endif
              </div>
              <span style="font-size:12.5px;color:#6A6A65">{{ $dsc }}</span>
              <form method="POST" action="{{ route('clientes.imagen', [$c, $slot]) }}" enctype="multipart/form-data">
                @csrf
                <label class="drop" data-drop-img>
                  <input type="file" name="imagen" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" data-autoupload>
                  @if($has)<img src="{{ route('clientes.imagen.ver', [$c, $slot]) }}?v={{ $c->{$slot.'_documento_id'} }}" alt="{{ $l }}">
                  @else<div style="display:flex;flex-direction:column;align-items:center;gap:4px;text-align:center"><span style="font-size:14px;font-weight:700">Arrastra o haz clic para subir</span><span style="font-size:12.5px;color:#6A6A65">PNG, JPG o WEBP · máx. 4 MB</span></div>@endif
                </label>
              </form>
            </div>
          @endforeach
        </div>
        @endif
      </div>
    </div>
    <div class="pf">
      <span style="margin-right:auto;white-space:nowrap;font:500 12.5px var(--mono);color:#6A6A65">Sección {{ $pos + 1 }} de {{ $n }}</span>
      <a class="btn soft" style="height:40px;border-radius:20px" href="{{ $cerrar }}">Cancelar</a>
      @if($esForm)
        @if($prev && ! $nuevo)<button class="btn soft" style="height:40px;border-radius:20px" type="submit" form="altaForm" name="ir" value="{{ $prev }}">Anterior</button>@endif
        @if($next)<button class="btn" style="height:40px;border-radius:20px" type="submit" form="altaForm" name="ir" value="{{ $next }}">Siguiente</button>@endif
        <button class="btn lime" style="height:40px;border-radius:20px" type="submit" form="altaForm" name="ir" value="cerrar">{{ $nuevo ? 'Crear cliente' : 'Guardar cambios' }}</button>
      @else
        @if($prev)<a class="btn soft" style="height:40px;border-radius:20px" href="{{ route('clientes.edit', [$c, 'sec' => $prev]) }}">Anterior</a>@endif
        @if($next)<a class="btn" style="height:40px;border-radius:20px" href="{{ route('clientes.edit', [$c, 'sec' => $next]) }}">Siguiente</a>@endif
        <a class="btn lime" style="height:40px;border-radius:20px" href="{{ $cerrar }}">Guardar cambios</a>
      @endif
    </div>
  </div>
</div>
