<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Support\Formato;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación administrativa recibida por un cliente (registro manual:
 * no hay conexión automática con DEHú, AEAT ni TGSS).
 */
class Notificacion extends Model
{
    use PerteneceACliente;

    protected $table = 'notificaciones';

    protected $guarded = ['id'];

    protected $attributes = [
        'estado' => 'Pendiente',
        'caducada' => false,
        'archivada' => false,
        'plazo_n' => 0,
        'plazo_tipo' => 'n',
    ];

    protected function casts(): array
    {
        return [
            'puesta' => 'immutable_date',
            'acceso' => 'immutable_date',
            'vence' => 'immutable_date',
            'caducada' => 'boolean',
            'archivada' => 'boolean',
            'plazo_n' => 'integer',
            'nota' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // El portal del cliente no ve las notificaciones (las gestiona el despacho)
        static::addGlobalScope('portal', function (Builder $q) {
            $u = auth()->user();
            if ($u && $u->esPortal()) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /** Marca como rechazadas las que pasaron 10 días naturales sin abrir. */
    public static function aplicarCaducidad(): int
    {
        $limite = Plazos::hoy()->subDays(Plazos::CADUCIDAD_DIAS + 1)->toDateString();
        $n = 0;
        static::withoutGlobalScopes()->where('estado', 'Pendiente')->whereDate('puesta', '<=', $limite)->each(function (self $x) use (&$n) {
            $x->estado = 'Rechazada';
            $x->caducada = true;
            $x->acceso = $x->caducidad();
            $x->recalcular();
            $x->saveQuietly();
            $n++;
        });

        return $n;
    }

    public function caducidad(): CarbonImmutable
    {
        return Plazos::caducidad($this->puesta);
    }

    public function recalcular(): void
    {
        $this->vence = $this->acceso ? Plazos::vencimiento($this->acceso, (int) $this->plazo_n, (string) $this->plazo_tipo, $this->cliente_id) : null;
    }

    public function pendiente(): bool
    {
        return $this->estado === 'Pendiente';
    }

    public function diasCaducidad(): int
    {
        return (int) Plazos::diasHasta($this->caducidad());
    }

    public function plazoEnCurso(): bool
    {
        return ! $this->pendiente() && $this->vence && $this->vence->gte(Plazos::hoy());
    }

    public function plazoTexto(): string
    {
        return Plazos::texto((int) $this->plazo_n, (string) $this->plazo_tipo);
    }

    public function organismoTexto(): string
    {
        return config('agcloud.organismos.'.$this->organismo, $this->organismo);
    }

    public function urlSede(): string
    {
        return config('agcloud.organismos_url.'.$this->organismo, 'https://dehu.redsara.es/');
    }

    /** [texto, fondo, color] como en el prototipo */
    public function estadoChip(): array
    {
        if ($this->pendiente()) {
            $d = $this->diasCaducidad();

            return $d <= 3 ? ['Caduca en '.max(0, $d).' d', '#FDE7E7', '#8A1C1C'] : ['Sin abrir', '#FCEBCB', '#6E4300'];
        }
        if ($this->estado === 'Rechazada') {
            return [$this->caducada ? 'Caducada' : 'Rechazada', '#ECECE7', '#3C3C3C'];
        }
        if ($this->plazoEnCurso()) {
            return ['Plazo hasta '.Formato::fecha($this->vence), '#E6F6A8', '#2E3A00'];
        }

        return ['Abierta', '#ECECE7', '#3C3C3C'];
    }

    public function textoWhatsapp(): string
    {
        $c = $this->cliente;
        $nombre = explode(' ', trim((string) $c?->nombre))[0] ?? '';

        return 'Hola, '.$nombre.'. Hemos recibido en tu nombre una notificación de '.$this->organismoTexto().': '.mb_strtolower($this->tipo)
            .($this->asunto ? ' ('.$this->asunto.')' : '').'.'
            .($this->vence ? ' El plazo para responder vence el '.Formato::fecha($this->vence).'.' : '')
            .' Nos ponemos con ello y te contamos.';
    }
}
