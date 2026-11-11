<div class="par-tipos" data-row>
  <select class="sel" name="tipos[{{ $i }}][org]">@foreach(config('agcloud.organismos') as $k => $l)<option value="{{ $k }}" @selected($t[0] === $k)>{{ $k }}</option>@endforeach</select>
  <select class="sel" name="tipos[{{ $i }}][canal]">@foreach(config('agcloud.canales') as $k)<option @selected($t[1] === $k)>{{ $k }}</option>@endforeach</select>
  <input class="inp" name="tipos[{{ $i }}][tipo]" value="{{ $t[2] }}" maxlength="160" placeholder="Tipo de notificación">
  <input class="inp mono" type="number" min="0" max="365" name="tipos[{{ $i }}][n]" value="{{ $t[3] }}">
  <select class="sel" name="tipos[{{ $i }}][t]">@foreach(['h' => 'hábiles', 'n' => 'naturales', 'm' => 'meses'] as $k => $l)<option value="{{ $k }}" @selected($t[4] === $k)>{{ $l }}</option>@endforeach</select>
  <input class="inp" name="tipos[{{ $i }}][accion]" value="{{ $t[5] }}" maxlength="120" placeholder="Actuación">
  <button type="button" class="lnk" style="font-size:18px;color:#6A6A65;text-decoration:none" data-row-del aria-label="Quitar">×</button>
</div>
