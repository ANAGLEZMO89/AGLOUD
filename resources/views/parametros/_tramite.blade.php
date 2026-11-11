<div class="par-tram" data-row>
  <div style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr) 120px 24px;gap:8px;align-items:center">
    <input class="inp" name="pl[{{ $i }}][nombre]" value="{{ $n }}" maxlength="120" placeholder="Nombre del trámite" style="font-weight:600">
    <input class="inp" name="pl[{{ $i }}][cat]" value="{{ $t['cat'] }}" maxlength="40" placeholder="Categoría">
    <input class="inp mono" name="pl[{{ $i }}][hon]" value="{{ \App\Support\Formato::num($t['hon']) }}" inputmode="decimal" title="Honorarios sin IVA">
    <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-row-del aria-label="Quitar">×</button>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
    <label class="lbl-s">Pasos · uno por línea<textarea class="txa" name="pl[{{ $i }}][pasos]" rows="4">{{ implode("\n", $t['pasos']) }}</textarea></label>
    <label class="lbl-s">Documentación a pedir · una por línea<textarea class="txa" name="pl[{{ $i }}][docs]" rows="4">{{ implode("\n", $t['docs']) }}</textarea></label>
  </div>
</div>
