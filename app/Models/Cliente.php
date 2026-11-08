<?php

namespace App\Models;

use App\Support\Formato;
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

    protected $attributes = ['estado' => 'Alta en curso', 'es_despacho' => false, 'cuota_periodicidad' => 'Mensual', 'cuota_iva' => 21, 'cuota_forma_cobro' => 'Domiciliación SEPA', 'cuota_concepto' => 'Honorarios de asesoría'];

    protected function casts(): array
    {
        return [
            'es_despacho' => 'boolean',
            'fecha_alta' => 'date',
            'cuota_inicio' => 'date',
            'cuota_importe' => 'decimal:2',
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

    /** Cuota con IVA, con el IVA redondeado al céntimo */
    public function cuotaConIva(): float
    {
        $imp = round((float) $this->cuota_importe, 2);

        return round($imp + round($imp * (int) $this->cuota_iva / 100, 2), 2);
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
