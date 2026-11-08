<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /** Mínimo 8 caracteres, con letras y números (regla del prototipo). */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(8)->letters()->numbers(), 'confirmed', 'max:100'];
    }
}
