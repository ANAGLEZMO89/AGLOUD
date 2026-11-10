<?php

namespace Tests\Unit;

use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PlazosTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Plazos::limpiarCache();
        parent::tearDown();
    }

    public function test_pascua_y_viernes_santo(): void
    {
        $this->assertSame('2026-04-05', Plazos::pascua(2026)->toDateString());
        $this->assertSame('2027-03-28', Plazos::pascua(2027)->toDateString());
        $this->assertArrayHasKey('2026-04-03', Plazos::festivos(2026));
        $this->assertArrayHasKey('2026-04-02', Plazos::festivos(2026)); // Jueves Santo (Andalucía)
    }

    public function test_dias_habiles_excluyen_fines_de_semana_y_festivos(): void
    {
        // 10 hábiles desde el miércoles 04/11/2026 → miércoles 18/11/2026
        $this->assertSame('2026-11-18', Plazos::vencimiento('2026-11-04', 10, 'h')->toDateString());
        // Desde el viernes 04/12/2026: lunes 7 hábil, 8 festivo (Inmaculada) → 1 hábil = 07/12
        $this->assertSame('2026-12-07', Plazos::vencimiento('2026-12-04', 1, 'h')->toDateString());
        $this->assertSame('2026-12-09', Plazos::vencimiento('2026-12-04', 2, 'h')->toDateString());
    }

    public function test_meses_de_fecha_a_fecha_y_ultimo_dia_inhabil(): void
    {
        // 31/01 + 1 mes → 28/02/2026 (sábado y Día de Andalucía) → lunes 02/03
        $this->assertSame('2026-03-02', Plazos::vencimiento('2026-01-31', 1, 'm')->toDateString());
        // 27/11 + 1 mes → domingo 27/12 → lunes 28/12
        $this->assertSame('2026-12-28', Plazos::vencimiento('2026-11-27', 1, 'm')->toDateString());
        $this->assertSame('2026-12-15', Plazos::vencimiento('2026-11-15', 1, 'm')->toDateString());
    }

    public function test_dias_naturales_se_trasladan_si_acaban_en_inhabil(): void
    {
        // 20 naturales desde 10/11/2026 → 30/11 (lunes)
        $this->assertSame('2026-11-30', Plazos::vencimiento('2026-11-10', 20, 'n')->toDateString());
        // 5 naturales desde 02/12 → 07/12 (lunes hábil)
        $this->assertSame('2026-12-07', Plazos::vencimiento('2026-12-02', 5, 'n')->toDateString());
        $this->assertNull(Plazos::vencimiento('2026-12-02', 0, 'n'));
    }

    public function test_caducidad_diez_dias_naturales(): void
    {
        $this->assertSame('2026-11-14', Plazos::caducidad('2026-11-04')->toDateString());
    }

    public function test_festivos_extra_configurables(): void
    {
        config(['agcloud.festivos_extra' => ['2026-11-12']]); // p. ej. un festivo local
        Plazos::limpiarCache();
        $this->assertSame('2026-11-19', Plazos::vencimiento('2026-11-04', 10, 'h')->toDateString());
    }
}
