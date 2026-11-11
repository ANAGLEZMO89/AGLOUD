<?php

namespace App\Models;

use App\Support\Formato;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Cliente extends Model
{
    use SoftDeletes;

    public const SECCIONES_LISTA = ['bancos', 'centros', 'acts', 'reps', 'regs', 'certs'];

    protected $guarded = ['id', 'es_despacho', 'created_by', 'updated_by'];

    protected $attributes = ['estado' => 'Activo', 'es_despacho' => false, 'cuota_periodicidad' => 'Mensual', 'cuota_iva' => 21, 'cuota_forma_cobro' => 'Domiciliación SEPA', 'cuota_concepto' => 'Honorarios de asesoría'];

    protected function casts(): array
    {
        return [
            'es_despacho' => 'boolean',
            'fecha_alta' => 'date',
            'cuota_inicio' => 'date',
            'cuota_importe' => 'decimal:2',
            'cuota_lineas' => 'array',
            'nif_aeat_at' => 'datetime',
            'datos' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        // Un usuario del portal solo puede «ver» su propio cliente
        static::addGlobalScope('tenant', function (Builder $q) {
            $u = Auth::user();
            if ($u && $u->esPortal()) {
                $q->where('clientes.id', $u->cliente_id ?? 0);
            }
        });

        static::creating(function (Cliente $c) {
            $c->created_by = $c->created_by ?? Auth::id();
        });
        static::saving(function (Cliente $c) {
            $c->updated_by = Auth::id() ?? $c->updated_by;
        });
    }

    public function expedientes(): HasMany
    {
        return $this->hasMany(Expediente::class);
    }

    public function notificaciones(): HasMany
    {
        return $this->hasMany(Notificacion::class);
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asesor_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeClientes(Builder $q): Builder
    {
        return $q->where('es_despacho', false);
    }

    public static function despacho(): ?self
    {
        return static::where('es_despacho', true)->first();
    }

    /** Lista de una sección de alta (bancos, centros…) */
    public function seccion(string $k): array
    {
        return array_values(($this->datos ?? [])[$k] ?? []);
    }

    public function setSeccion(string $k, array $lista): void
    {
        $d = $this->datos ?? [];
        $d[$k] = array_values($lista);
        $this->datos = $d;
    }

    public function iniciales(): string
    {
        return Formato::iniciales($this->nombre);
    }

    public function estadoColores(): array
    {
        return config('agcloud.estados_cliente.'.$this->estado, ['#ECECE7', '#6A6A65']);
    }

    public function cuotaTexto(): string
    {
        if (! $this->cuota_importe) {
            return '—';
        }
        $v = (float) $this->cuota_importe;

        return (fmod($v, 1.0) ? Formato::num($v) : number_format($v, 0, ',', '.')).' €';
    }

    /**
     * Conceptos de la cuota [{d, q, p, iva}]. Si aún no se han detallado, una sola línea con el concepto y el importe.
     */
    public function lineasCuota(): array
    {
        $L = array_values(array_filter((array) $this->cuota_lineas, fn ($l) => trim((string) ($l['d'] ?? '')) !== ''));
        if (! $L && (float) $this->cuota_importe > 0) {
            $L = [['d' => $this->cuota_concepto ?: 'Honorarios de asesoría', 'q' => 1, 'p' => (float) $this->cuota_importe, 'iva' => (int) $this->cuota_iva]];
        }

        return $L;
    }

    /** Texto del periodo que se factura: «noviembre 2026», «4T 2026», «2026»… */
    public static function periodoCuota(string $periodicidad, CarbonInterface $f): string
    {
        $mes = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$f->month - 1];

        return match ($periodicidad) {
            'Mensual' => $mes.' '.$f->year,
            'Trimestral' => (intdiv($f->month - 1, 3) + 1).'T '.$f->year,
            'Semestral' => (($f->month <= 6) ? '1.er' : '2.º').' semestre '.$f->year,
            'Anual' => (string) $f->year,
            default => '',
        };
    }

    /** Cuota con IVA, con el IVA redondeado al céntimo */
    public function cuotaConIva(): float
    {
        $t = 0.0;
        foreach ($this->lineasCuota() as $l) {
            $b = round((float) $l['q'] * (float) $l['p'], 2);
            $t += $b + round($b * (float) $l['iva'] / 100, 2);
        }

        return round($t, 2);
    }

    public function actividadPrincipal(): string
    {
        $acts = $this->seccion('acts');
        foreach ($acts as $a) {
            if (! empty($a['principal']) && empty($a['fFin'])) {
                return $a['desc'] ?? '';
            }
        }

        return $this->actividad ?? ($acts[0]['desc'] ?? '');
    }

    /** Teléfono en formato wa.me (prefijo 34 si es un número español de 9 cifras) */
    public function whatsapp(): string
    {
        $t = preg_replace('/\D/', '', (string) $this->telefono);

        return strlen($t) === 9 ? '34'.$t : $t;
    }

    public function esParticular(): bool
    {
        return $this->tipo === 'Particular';
    }

    public function subtitulo(): string
    {
        if ($this->es_despacho) {
            return ($this->nif ?: 'Sin NIF').' · Despacho propio';
        }

        return ($this->nif ?: 'Pendiente').' · '.$this->tipo;
    }
}
