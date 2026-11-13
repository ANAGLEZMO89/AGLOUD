<?php

namespace App\Models;

use App\Notifications\RestablecerContrasena;
use App\Support\Formato;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ADMIN = 'admin_gestoria';

    public const ASESOR = 'asesor';

    public const CLIENTE_ADMIN = 'cliente_admin';

    public const CLIENTE_EMPLEADO = 'cliente_empleado';

    public const ROLES_DESPACHO = [self::ADMIN, self::ASESOR];

    public const ROLES_PORTAL = [self::CLIENTE_ADMIN, self::CLIENTE_EMPLEADO];

    protected $fillable = ['name', 'email', 'password', 'role', 'cliente_id', 'telefono', 'cargo', 'activo', 'must_change_password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $attributes = ['role' => self::ASESOR, 'activo' => true, 'must_change_password' => false];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function esDespacho(): bool
    {
        return in_array($this->role, self::ROLES_DESPACHO, true);
    }

    public function esAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function esPortal(): bool
    {
        return in_array($this->role, self::ROLES_PORTAL, true);
    }

    public function esEmpleado(): bool
    {
        return $this->role === self::CLIENTE_EMPLEADO;
    }

    public function rolTexto(): string
    {
        return config('agcloud.roles.'.$this->role, $this->role);
    }

    public function iniciales(): string
    {
        return Formato::iniciales($this->name);
    }

    public function tiene2fa(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RestablecerContrasena($token));
    }
}
