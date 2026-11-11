<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\JornadaAusencia;
use App\Models\JornadaCorreccion;
use App\Models\JornadaDia;
use App\Models\Trabajador;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ControlHorarioTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    private Trabajador $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-11-11 08:00:00');
        $this->a = $this->cliente('Café Las Colonias SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
        $r = $this->en()->post('/laboral/trabajador/nuevo', ['nom1' => 'María', 'ape1' => 'López', 'ape2' => 'Ruiz', 'tipoDoc' => 'DNI', 'nif' => '29112233Y', 'naf' => '211234567890',
            'contrato' => '100', 'alta' => '2026-01-01', 'jor' => '100', 'horas' => '40', 'grupo' => '5', 'salBase' => '1.500,00', 'pagas' => '14', 'irpfModo' => 'auto']);
        $r->assertSessionHasNoErrors();
        $this->t = Trabajador::firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $t): void
    {
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    private function en()
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
    }

    public function test_pantallas(): void
    {
        foreach (['hoy', 'reg', 'res', 'aus'] as $tab) {
            $this->en()->get('/control-horario?tab='.$tab)->assertOk()->assertSee('Control horario')->assertSee('María López Ruiz');
        }
        $this->actingAs($this->usuario(User::CLIENTE_ADMIN, $this->a))->get('/control-horario')->assertForbidden();
    }

    public function test_fichar_entrada_pausa_reanudar_y_salida(): void
    {
        $u = '/control-horario/'.$this->t->id.'/fichar/';
        $this->en()->post($u.'pausa')->assertSessionHasErrors('horario');
        $this->en()->post($u.'entrada')->assertSessionHasNoErrors();
        $this->en()->post($u.'entrada')->assertSessionHasErrors('horario');
        $this->reloj('2026-11-11 11:00:00');
        $this->en()->post($u.'pausa')->assertSessionHasNoErrors();
        $this->reloj('2026-11-11 11:30:00');
        $this->en()->post($u.'reanudar')->assertSessionHasNoErrors();
        $this->reloj('2026-11-11 16:30:00');
        $this->en()->post($u.'salida')->assertSessionHasNoErrors();
        $d = JornadaDia::firstOrFail();
        $this->assertSame([['in' => '08:00', 'out' => '11:00', 'p' => false], ['in' => '11:30', 'out' => '16:30', 'p' => false]], $d->tramos);
        $this->en()->get('/control-horario')->assertSee('Salida a las 16:30')->assertSee('8:00 de 8:00');
        $this->en()->get('/control-horario?tab=res')->assertSee('8:00');
    }

    public function test_corregir_con_motivo_y_trazabilidad(): void
    {
        $u = '/control-horario/'.$this->t->id.'/corregir';
        $this->en()->post($u, ['fecha' => '2026-11-10', 'txt' => '08:00-14:00', 'motivo' => ''])->assertSessionHasErrors('horario');
        $this->en()->post($u, ['fecha' => '2026-11-10', 'txt' => '14:00-08:00', 'motivo' => 'Olvido'])->assertSessionHasErrors('horario');
        $this->en()->post($u, ['fecha' => '2026-11-10', 'txt' => '08:00-14:00, 13:00-15:00', 'motivo' => 'Olvido'])->assertSessionHasErrors('horario');
        $this->en()->post($u, ['fecha' => '2026-11-12', 'txt' => '08:00-14:00', 'motivo' => 'Olvido'])->assertSessionHasErrors('horario');
        $this->en()->post($u, ['fecha' => '2026-11-10', 'txt' => '8:00-14:00, 15:00-17:00', 'motivo' => 'Olvidó fichar'])->assertSessionHasNoErrors();
        $this->en()->post($u, ['fecha' => '2026-11-10', 'txt' => '08:00-14:00', 'motivo' => 'Salió antes'])->assertSessionHasNoErrors();
        $this->assertSame(1, JornadaDia::count());
        $c = JornadaCorreccion::orderBy('id')->get();
        $this->assertCount(2, $c);
        $this->assertNull($c[0]->antes);
        $this->assertSame('08:00-14:00 · 15:00-17:00', $c[0]->despues);
        $this->assertSame('08:00-14:00 · 15:00-17:00', $c[1]->antes);
        $this->en()->get('/control-horario?tab=reg&ym=2026-11&editar=2026-11-10')->assertSee('Corregido: Salió antes')->assertSee('Olvidó fichar');
        $csv = $this->en()->get('/control-horario?tab=reg&ym=2026-11&exportar=excel');
        $this->assertStringContainsString('08:00–14:00', $csv->streamedContent());
    }

    public function test_ausencias_y_vacaciones(): void
    {
        $this->en()->post('/control-horario/ausencias', ['trabajador' => $this->t->id, 'tipo' => 'Vacaciones', 'desde' => '2026-11-20', 'hasta' => '2026-11-18'])->assertSessionHasErrors('horario');
        $this->en()->post('/control-horario/ausencias', ['trabajador' => $this->t->id, 'tipo' => 'Vacaciones', 'desde' => '2026-11-11', 'hasta' => '2026-11-15'])->assertSessionHasNoErrors();
        $this->en()->post('/control-horario/ausencias', ['trabajador' => $this->t->id, 'tipo' => 'Asuntos propios', 'desde' => '2026-11-14', 'hasta' => '2026-11-14'])->assertSessionHasErrors('horario');
        $this->en()->get('/control-horario?tab=aus')->assertSee('5 de 30 días naturales');
        // Con ausencia no se puede fichar
        $this->en()->post('/control-horario/'.$this->t->id.'/fichar/entrada')->assertSessionHasErrors('horario');
        $this->en()->delete('/control-horario/ausencias/'.JornadaAusencia::firstOrFail()->id)->assertRedirect();
        $this->assertSame(0, JornadaAusencia::count());
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $b = $this->cliente('Otra SL', 'B21345673');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->post('/control-horario/'.$this->t->id.'/fichar/entrada')->assertNotFound();
        $this->actingAs($this->as)->withSession(['cliente_activo' => $b->id])->get('/control-horario')->assertOk()->assertDontSee('María López Ruiz');
    }
}
