{{-- Un concepto retributivo del convenio (fila del editor). Variables: i, r, catsV, opt, E, inv --}}
@php $p = 'conceptos['.$i.']'; $e = 'conceptos.'.$i; $sop = $r['soportado'] ?? true; @endphp
<fieldset class="cv-conc" data-lb-row>
  <legend class="sr-only">Concepto {{ $r['nombre'] ?? 'nuevo' }}</legend>
  <div class="cv-conc-g">
    <label class="cv-l" style="grid-column:span 2">Nombre *<input class="inp lb-in" name="{{ $p }}[nombre]" value="{{ $r['nombre'] ?? '' }}" maxlength="120" {{ $inv("$e.nombre") }}>{{ $E("$e.nombre") }}</label>
    <label class="cv-l">Código<input class="inp lb-in mono" name="{{ $p }}[codigo]" value="{{ $r['codigo'] ?? '' }}" maxlength="20"></label>
    <label class="cv-l">Código CRA<input class="inp lb-in mono" name="{{ $p }}[cra]" value="{{ $r['cra'] ?? '' }}" maxlength="10"></label>
    <label class="cv-l">Naturaleza<select class="sel lb-in" name="{{ $p }}[naturaleza]">{{ $opt(\App\Models\ConvenioConcepto::NATURALEZAS, $r['naturaleza'] ?? 'salarial') }}</select>{{ $E("$e.naturaleza") }}</label>
    <label class="cv-l">Cálculo<select class="sel lb-in" name="{{ $p }}[calculo]">{{ $opt(['fijo' => 'Importe', 'porcentaje' => 'Porcentaje', 'formula' => 'Fórmula'], $r['calculo'] ?? 'fijo') }}</select>{{ $E("$e.calculo") }}</label>
    <label class="cv-l">Se aplica<select class="sel lb-in" name="{{ $p }}[unidad]">{{ $opt(\App\Models\ConvenioConcepto::UNIDADES, $r['unidad'] ?? 'mes') }}</select>{{ $E("$e.unidad") }}</label>
    <label class="cv-l">Importe general (€ o %)<input class="inp lb-in mono" name="{{ $p }}[valor]" value="{{ $r['valor'] ?? '' }}" inputmode="decimal" placeholder="No localizado" {{ $inv("$e.valor") }}>{{ $E("$e.valor") }}</label>
    <label class="cv-l">Base del porcentaje<select class="sel lb-in" name="{{ $p }}[base]"><option value="">—</option>{{ $opt(['salario_base' => 'Salario base', 'salario_base_plus' => 'Salario base + plus'], $r['base'] ?? '') }}</select>{{ $E("$e.base") }}</label>
    <label class="cv-l">Cotiza<select class="sel lb-in" name="{{ $p }}[cotiza]">{{ $opt(['si' => 'Sí', 'no' => 'No', 'limite' => 'Con límite'], $r['cotiza'] ?? 'si') }}</select></label>
    <label class="cv-l">Tributa (IRPF)<select class="sel lb-in" name="{{ $p }}[tributa]">{{ $opt(['si' => 'Sí', 'no' => 'No', 'limite' => 'Con límite'], $r['tributa'] ?? 'si') }}</select></label>
    <label class="cv-l">Redondeo<select class="sel lb-in" name="{{ $p }}[redondeo]">{{ $opt(['2d' => 'Al céntimo', '0d' => 'Al euro'], $r['redondeo'] ?? '2d') }}</select></label>
    <label class="cv-l">Límite (€)<input class="inp lb-in mono" name="{{ $p }}[limite_importe]" value="{{ $r['limite_importe'] ?? '' }}" inputmode="decimal" {{ $inv("$e.limite_importe") }}>{{ $E("$e.limite_importe") }}</label>
    <label class="cv-l">Límite por<select class="sel lb-in" name="{{ $p }}[limite_unidad]"><option value="">—</option>{{ $opt(['dia' => 'Día', 'mes' => 'Mes'], $r['limite_unidad'] ?? '') }}</select></label>
    <label class="cv-l" style="grid-column:span 2">Norma del límite<input class="inp lb-in" name="{{ $p }}[limite]" value="{{ $r['limite'] ?? '' }}" maxlength="250" placeholder="Ej.: art. 9 RIRPF, gastos de manutención sin pernocta">{{ $E("$e.limite") }}</label>
    <label class="cv-l" style="grid-column:span 2">Fórmula<input class="inp lb-in mono" name="{{ $p }}[formula]" value="{{ $r['formula'] ?? '' }}" maxlength="500" placeholder="salario_base / horas_mes * 0,25" title="Variables: {{ implode(', ', array_keys(\App\Services\Formula::VARIABLES)) }}; otros conceptos: c_CÓDIGO; funciones: min(a; b), max(a; b), redondear(x; 2)" {{ $inv("$e.formula") }}>{{ $E("$e.formula") }}</label>
    <label class="cv-l">Artículo / página<input class="inp lb-in" name="{{ $p }}[referencia]" value="{{ $r['referencia'] ?? '' }}" maxlength="120"></label>
    <label class="cv-l" style="grid-column:span 3">Descripción<input class="inp lb-in" name="{{ $p }}[descripcion]" value="{{ $r['descripcion'] ?? '' }}" maxlength="500"></label>
  </div>
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
    <label class="lb-chk"><input type="checkbox" name="{{ $p }}[automatico]" value="1" @checked(! empty($r['automatico']))>Sale solo cada mes</label>
    <label class="lb-chk"><input type="checkbox" name="{{ $p }}[prop_jornada]" value="1" @checked(! empty($r['prop_jornada']))>Proporcional a la jornada</label>
    <label class="lb-chk"><input type="checkbox" name="{{ $p }}[prop_periodo]" value="1" @checked(! empty($r['prop_periodo']))>Proporcional a los días en alta</label>
    <label class="lb-chk"><input type="checkbox" name="{{ $p }}[en_pagas]" value="1" @checked(! empty($r['en_pagas']))>Se incluye en las pagas extra</label>
    @if(! $sop)<span class="cv-warn">No sale en las nóminas: {{ $r['motivo'] ?? 'el motor todavía no lo calcula' }}.</span>@endif
    <button type="button" class="lb-del" data-lb-del aria-label="Quitar concepto" style="margin-left:auto">Quitar</button>
  </div>
  @if(count($catsV))
  <details @if(! empty($r['importes'])) open @endif><summary style="cursor:pointer;font-size:12.5px;font-weight:600">Importe por categoría (si cambia según la categoría)</summary>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;margin-top:6px">
      @foreach($catsV as $ct)<label class="cv-l">{{ $ct->nombre }}<input class="inp lb-in mono" name="{{ $p }}[importes][{{ $ct->id }}]" value="{{ $r['importes'][$ct->id] ?? '' }}" inputmode="decimal" placeholder="Importe general"></label>@endforeach
    </div>
  </details>
  @endif
</fieldset>
