<?php

namespace Tests\Unit;

use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\Validacion;
use Tests\TestCase;

class ValidacionTest extends TestCase
{
    public function test_nif(): void
    {
        $this->assertTrue(Validacion::nifOk('12345678Z'));
        $this->assertFalse(Validacion::nifOk('12345678A'));
        $this->assertTrue(Validacion::nifOk('X1234567L'));
        $this->assertTrue(Validacion::nifOk('B21890124'));
        $this->assertFalse(Validacion::nifOk('B21890123'));
        $this->assertSame('DNI incorrecto: la letra debería ser Z', Validacion::nif('12345678A')['msg']);
    }

    public function test_iban(): void
    {
        $this->assertTrue(Validacion::iban('ES91 2100 0418 4502 0005 1332'));
        $this->assertFalse(Validacion::iban('ES92 2100 0418 4502 0005 1332'));
        $this->assertSame(['CaixaBank', 'CAIXESBBXXX'], Validacion::banco('ES9121000418450200051332'));
        $this->assertSame('Huelva', Validacion::provinciaDe('21001'));
    }

    public function test_formato_espanol(): void
    {
        $this->assertSame('1.234,56 €', Formato::eur(1234.56));
        $this->assertSame('−12,00', Formato::num(-12));
        $this->assertSame(1234.56, Formato::parseNum('1.234,56'));
        $this->assertSame(1234.56, Formato::parseNum('1234.56'));
        $this->assertSame('04/11/2026', Formato::fecha('2026-11-04'));
    }

    public function test_clasificador(): void
    {
        $p = ClasificadorDocumentos::proponer('Modelo 303 3T 20261020.pdf');
        $this->assertSame('fiscal/modelos', $p['carpeta']);
        $this->assertSame('2026-10-20', $p['fecha']);
        $this->assertSame('ident/certdig', ClasificadorDocumentos::proponer('certificado.p12')['carpeta']);
        $this->assertSame('passwd', ClasificadorDocumentos::nombreSeguro('../../etc/passwd'));
    }
}
