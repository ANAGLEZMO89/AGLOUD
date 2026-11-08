<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Documento extends Model
{
    use PerteneceACliente;

    protected $guarded = ['id'];

    protected $hidden = ['ruta', 'sha256'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'caduca' => 'date',
            'eliminado_at' => 'datetime',
            'compartido' => 'boolean',
            'por_revisar' => 'boolean',
            'borrador' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Portal: el cliente_admin ve lo compartido + lo que suba su empresa;
        // el empleado solo ve lo que ha subido él mismo.
        static::addGlobalScope('portal', function (Builder $q) {
            $u = Auth::user();
            if (! $u || ! $u->esPortal()) {
                return;
            }
            if ($u->esEmpleado()) {
                $q->where('documentos.subido_por', $u->id);
            } else {
                $q->where(fn ($w) => $w->where('documentos.compartido', true)->orWhere('documentos.origen', 'Portal del cliente'));
            }
        });
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function scopeGestor(Builder $q): Builder
    {
        return $q->where('ambito', 'gestor')->where('borrador', false);
    }

    public function scopeVivos(Builder $q): Builder
    {
        return $q->whereNull('eliminado_at');
    }

    public function scopePapelera(Builder $q): Builder
    {
        return $q->whereNotNull('eliminado_at');
    }

    public function grupo(): string
    {
        return explode('/', $this->carpeta)[0];
    }

    public function carpetaTexto(): string
    {
        return Formato::carpeta($this->carpeta);
    }

    public function tipoTexto(): string
    {
        return config('agcloud.tipos_documento.'.$this->tipo, 'Documento');
    }

    public function kb(): string
    {
        return Formato::entero(max(1, (int) round($this->tamano / 1024))).' KB';
    }

    /** Fecha hasta la que la ley obliga a conservarlo [fecha, fundamento] o null */
    public function conservarHasta(): ?array
    {
        $k = config('agcloud.conservacion.'.$this->grupo());
        if (! $k) {
            return null;
        }
        // Desde el 1 de enero siguiente al año del documento + N años
        $hasta = Carbon::create($this->fecha->year + $k[0] + 1, 1, 1);

        return [$hasta, $k[1]];
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime, 'image/') && $this->mime !== 'image/svg+xml';
    }

    public function esPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function caducaPronto(): bool
    {
        return $this->caduca && $this->caduca->lte(now()->addDays(60));
    }
}
