<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Support\Formato;
use App\Support\Plazos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Expediente extends Model
{
    use PerteneceACliente;

    public const ESTADOS = ['Abierto', 'En curso', 'Esperando cliente', 'Presentado', 'Cerrado'];

    protected $guarded = ['id'];

    protected $attributes = [
        'estado' => 'Abierto',
        'portal' => true,
        'honorarios' => 0,
        'pasos' => '[]',
        'docs' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'apertura' => 'immutable_date',
            'plazo' => 'immutable_date',
            'pasos' => 'array',
            'docs' => 'array',
            'portal' => 'boolean',
            'honorarios' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Portal: solo los expedientes marcados como visibles
        static::addGlobalScope('portal', function (Builder $q) {
            $u = auth()->user();
            if ($u && $u->esPortal()) {
                $q->where('expedientes.portal', true);
            }
        });
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ExpedienteEvento::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Siguiente referencia del año: EXP-2026-0001 */
    public static function siguienteRef(?int $y = null): string
    {
        $y ??= (int) now()->format('Y');
        $ult = DB::table('expedientes')->where('ref', 'like', "EXP-$y-%")->orderByDesc('ref')->value('ref');
        $n = $ult ? ((int) substr($ult, -4)) + 1 : 1;

        return sprintf('EXP-%d-%04d', $y, $n);
    }

    public function evento(string $texto, bool $nota = false): void
    {
        $this->eventos()->create(['cliente_id' => $this->cliente_id, 'user_id' => auth()->id(), 'nota' => $nota, 'texto' => $texto]);
    }

    public function cerrado(): bool
    {
        return $this->estado === 'Cerrado';
    }

    public function hechos(): int
    {
        return collect($this->pasos)->where('ok', true)->count();
    }

    public function docsOk(): int
    {
        return collect($this->docs)->where('ok', true)->count();
    }

    public function progTxt(): string
    {
        return $this->hechos().'/'.count($this->pasos);
    }

    public function pct(): int
    {
        $t = count($this->pasos);

        return $t ? (int) round($this->hechos() / $t * 100) : 0;
    }

    public function dias(): ?int
    {
        return $this->plazo && ! $this->cerrado() ? Plazos::diasHasta($this->plazo) : null;
    }

    public function plazoTxt(): string
    {
        return $this->plazo ? Formato::fecha($this->plazo) : 'Sin plazo';
    }

    public function leftTxt(): string
    {
        $d = $this->dias();

        return match (true) {
            $this->cerrado() => 'Cerrado',
            $d === null => 'Sin fecha límite',
            $d < 0 => 'Vencido hace '.(-$d).' d',
            $d === 0 => 'Vence hoy',
            $d === 1 => 'Queda 1 día',
            default => 'Quedan '.$d.' días',
        };
    }

    public function dot(): string
    {
        $d = $this->dias();

        return $d === null ? '#C4C4BE' : ($d <= 7 ? '#E5484D' : ($d <= 15 ? '#F5A524' : '#8A8A85'));
    }

    public function colores(): array
    {
        return config('agcloud.estados_expediente.'.$this->estado, ['#F4F4F0', '#0B0B0B']);
    }

    public function facturaTxt(): string
    {
        return match ($this->estado) {
            'Cerrado' => 'Facturado',
            'Presentado' => 'Listo para facturar',
            default => 'Se factura al presentar',
        };
    }

    public function siguienteEstado(): ?string
    {
        $i = array_search($this->estado, self::ESTADOS, true);

        return self::ESTADOS[$i + 1] ?? null;
    }

    public function docsPendientes(): array
    {
        return collect($this->docs)->where('ok', false)->pluck('l')->all();
    }
}
