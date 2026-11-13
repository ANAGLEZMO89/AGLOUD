<?php

namespace App\Services\Dehu;

use RuntimeException;

/** Error devuelto por la DEHú (código funcional del catálogo de errores o SOAP Fault) */
class LemaError extends RuntimeException
{
    public function __construct(string $mensaje, public readonly string $codigo = '')
    {
        parent::__construct($mensaje);
    }
}
