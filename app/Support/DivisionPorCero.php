<?php

namespace App\Support;

use RuntimeException;

/** División por cero en una fórmula o en un cálculo de nómina */
class DivisionPorCero extends RuntimeException {}
