@php $tot = $p->total(); @endphp
<div class="pager">
  <span>{{ $tot ? 'Mostrando '.\App\Support\Formato::entero($p->firstItem()).'–'.\App\Support\Formato::entero($p->lastItem()).' de '.\App\Support\Formato::entero($tot) : 'Sin resultados' }}</span>
  @if($p->lastPage() > 1)
    <nav>
      @php
        $cur = $p->currentPage(); $last = $p->lastPage();
        $pages = collect([1, $cur - 1, $cur, $cur + 1, $last])->filter(fn ($x) => $x >= 1 && $x <= $last)->unique()->sort()->values();
      @endphp
      @foreach($pages as $i => $n)
        @if($i > 0 && $n - $pages[$i - 1] > 1)<span class="p" style="background:none;padding:5px 4px">…</span>@endif
        @if($n === $cur)<span class="p on">{{ $n }}</span>@else<a href="{{ $p->url($n) }}">{{ $n }}</a>@endif
      @endforeach
    </nav>
  @endif
</div>
