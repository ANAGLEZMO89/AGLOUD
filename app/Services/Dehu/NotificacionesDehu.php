<?php

namespace App\Services\Dehu;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Notificacion;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Support\ClasificadorDocumentos;
use App\Support\Plazos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Notificaciones de la DEHú con el certificado de la ficha de cada cliente (servicio LEMA, gran destinatario con un único NIF).
 *
 * - sincronizar(): LISTA lo pendiente (sin efectos jurídicos) y lo registra en Notificaciones como «Sin abrir».
 * - comparecer(): ABRE una notificación a petición de un usuario del despacho. Desde ese momento se entiende
 *   practicada (art. 43 Ley 39/2015) y empieza el plazo. Guarda el documento, los anexos y el acuse en Documentos › Fiscal › Requerimientos y notificaciones (compartidos con el cliente).
 */
class NotificacionesDehu
{
    /** Máximo de páginas de 50 envíos por consulta */
    private const MAX_PAG = 10;

    public function __construct(private AlmacenDocumentos $almacen, private ?Lema $lema = null)
    {
        $this->lema ??= new Lema;
    }

    /**
     * Certificado de la ficha del cliente marcado para la DEHú: [fila de la ficha, motivo si no se puede usar].
     *
     * @return array{0: ?array, 1: ?string}
     */
    public static function certificado(Cliente $c): array
    {
        $hoy = Plazos::hoy()->toDateString();
        $todos = $c->seccion('certs');
        $dehu = array_values(array_filter($todos, fn ($x) => ! empty($x['dehu'])));
        if (! $todos) {
            return [null, 'Sin certificado en la ficha'];
        }
        if (! $dehu) {
            return [null, 'Ningún certificado marcado con «Uso DEHú»'];
        }
        $vivos = array_values(array_filter($dehu, fn ($x) => ($x['fCad'] ?? '') >= $hoy));
        if (! $vivos) {
            return [null, 'El certificado para la DEHú está caducado'];
        }
        usort($vivos, fn ($a, $b) => strcmp($b['fCad'] ?? '', $a['fCad'] ?? ''));
        $x = $vivos[0];
        if (empty($x['archivo']['id']) || ! preg_match('/\.(p12|pfx)$/i', (string) ($x['archivo']['name'] ?? '')) || ($x['pass'] ?? '') === '') {
            return [$x, 'Falta el fichero .p12/.pfx o su contraseña'];
        }
        if (empty($x['gd'])) {
            return [$x, 'Falta el alta como Gran Destinatario en la DEHú'];
        }

        return [$x, null];
    }

    public function credencial(Cliente $c): Credencial
    {
        [$x, $motivo] = self::certificado($c);
        if ($motivo) {
            throw new InvalidArgumentException($motivo.'.');
        }
        $doc = Documento::withoutGlobalScopes()->where('cliente_id', $c->id)->find((int) $x['archivo']['id']);
        if (! $doc) {
            throw new InvalidArgumentException('No se encuentra el fichero del certificado en los documentos del cliente.');
        }

        return Credencial::desdePkcs12($this->almacen->contenido($doc->ruta), (string) $x['pass']);
    }

    /** Consulta la DEHú y registra lo nuevo. Devuelve ['nuevas' => n, 'total' => n] y anota el resultado en la ficha. */
    public function sincronizar(Cliente $c): array
    {
        try {
            $cred = $this->credencial($c);
            $nif = strtoupper((string) $c->nif);
            $nuevas = $total = 0;
            for ($p = 1; $p <= self::MAX_PAG; $p++) {
                $r = $this->lema->localiza($cred, $nif, $p);
                foreach ($r['envios'] as $e) {
                    $total++;
                    $nuevas += $this->registrar($c, $e) ? 1 : 0;
                }
                if (! $r['hayMas'] || $p >= $r['totalPag']) {
                    break;
                }
            }
            $this->anotar($c, true, $nuevas ? $nuevas.' nuevas' : 'Sin novedades', $nuevas);
            if ($nuevas) {
                Auditoria::registrar('notificacion.dehu', 'DEHú: '.$nuevas.' notificaciones nuevas de '.$c->nombre, $c, $c->id);
            }

            return ['nuevas' => $nuevas, 'total' => $total];
        } catch (Throwable $e) {
            $this->anotar($c, false, self::mensaje($e), 0);
            throw $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e : new RuntimeException(self::mensaje($e), 0, $e);
        }
    }

    /** Registra un envío de la DEHú si aún no existe. true si es nuevo. */
    private function registrar(Cliente $c, array $e): bool
    {
        if (Notificacion::withoutGlobalScopes()->where('cliente_id', $c->id)->where('dehu_id', $e['identificador'])->exists()) {
            return false;
        }
        $puesta = $e['fecha'] ? Carbon::parse($e['fecha'])->setTimezone('Europe/Madrid')->toDateString() : Plazos::hoy()->toDateString();
        $org = self::organismo(($e['raiz']['nombre'] ?? '').' '.($e['emisor']['nombre'] ?? ''));
        $n = new Notificacion([
            'cliente_id' => $c->id, 'organismo' => $org, 'canal' => 'DEHú',
            'tipo' => mb_substr(($e['tipoEnvio'] === 1 ? 'Comunicación' : 'Notificación').' · '.($e['concepto'] ?: 'Sin concepto'), 0, 160),
            'asunto' => mb_substr((string) ($e['descripcion'] ?: ($e['emisor']['nombre'] ?? '')), 0, 255), 'referencia' => mb_substr($e['identificador'], 0, 80),
            'puesta' => $puesta, 'plazo_n' => 0, 'plazo_tipo' => 'n', 'responsable_id' => $c->asesor_id,
        ]);
        $n->dehu_id = $e['identificador'];
        $n->dehu = json_encode(['codigoOrigen' => $e['codigoOrigen'], 'concepto' => $e['concepto'], 'tipoEnvio' => $e['tipoEnvio'], 'vinculo' => $e['vinculo'],
            'emisor' => $e['emisor'], 'raiz' => $e['raiz'], 'fecha' => $e['fecha'], 'titular' => $e['titular']], JSON_UNESCAPED_UNICODE);
        if (Plazos::caducidad($puesta)->lt(Plazos::hoy())) {
            // Más de 10 días naturales sin abrir: rechazada por caducidad (art. 43.2 Ley 39/2015)
            $n->estado = 'Rechazada';
            $n->caducada = true;
            $n->acceso = Plazos::caducidad($puesta);
        }
        $n->recalcular();
        $n->save();

        return true;
    }

    /**
     * ABRE (comparece) la notificación en la DEHú. Efectos jurídicos: se entiende notificada en este momento.
     * Guarda en Documentos el contenido, los anexos y el acuse, y deja la notificación como abierta.
     */
    public function comparecer(Notificacion $n, int $userId): Notificacion
    {
        if (! $n->dehu_id || ! $n->pendiente()) {
            throw new InvalidArgumentException('Solo se pueden abrir desde aquí las notificaciones de la DEHú pendientes.');
        }
        $c = Cliente::findOrFail($n->cliente_id);
        $meta = json_decode((string) $n->dehu, true) ?: [];
        $envio = ['identificador' => $n->dehu_id, 'codigoOrigen' => (int) ($meta['codigoOrigen'] ?? 0), 'concepto' => (string) ($meta['concepto'] ?? '')];
        $cred = $this->credencial($c);
        $nif = strtoupper((string) $c->nif);
        $r = $this->lema->peticionAcceso($cred, $envio, $nif, (string) $c->nombre);
        $fecha = $r['fechaEvento'] ? Carbon::parse($r['fechaEvento'])->setTimezone('Europe/Madrid')->toDateString() : Plazos::hoy()->toDateString();
        $n->estado = 'Aceptada';
        $n->acceso = $fecha;
        $n->recalcular();
        $meta['comparecencia'] = ['fecha' => $r['fechaEvento'], 'usuario' => $userId, 'csv' => $r['acuse']['csv'], 'enlace' => $r['documento']['enlace'] ?? null];
        $docs = [];
        if (! empty($r['documento']['bytes'])) {
            $docs[] = $this->guardarDoc($c, $r['documento']['bytes'], $r['documento']['nombre'], $r['documento']['mime'], $n, $userId);
        }
        foreach ($r['anexos'] as $i => $a) {
            if (! empty($a['referencia'])) {
                try {
                    $ax = $this->lema->consultaAnexos($cred, $nif, $envio, $a['referencia']);
                    if ($ax['bytes'] !== '') {
                        $docs[] = $this->guardarDoc($c, $ax['bytes'], $ax['nombre'] ?: 'anexo-'.($i + 1), $ax['mime'], $n, $userId);
                    }
                } catch (Throwable) {
                    $meta['avisos'][] = 'No se pudo descargar el anexo '.($i + 1).'.';
                }
            }
        }
        if ($r['acuse']['referencia'] || $r['acuse']['csv']) {
            try {
                $ac = $this->lema->consultaAcusePdf($cred, $nif, $envio, $r['acuse']['referencia'], $r['acuse']['csv']);
                if ($ac['bytes'] !== '') {
                    $docs[] = $this->guardarDoc($c, $ac['bytes'], 'Acuse '.$ac['nombre'], 'application/pdf', $n, $userId);
                }
            } catch (Throwable) {
                $meta['avisos'][] = 'No se pudo descargar el acuse en PDF; está en la DEHú con el CSV '.($r['acuse']['csv'] ?: '—').'.';
            }
        }
        $meta['documentos'] = array_map(fn ($d) => $d->id, $docs);
        $n->dehu = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($docs && ! $n->documento_id) {
            $n->documento_id = $docs[0]->id;
        }
        $n->save();
        Auditoria::registrar('notificacion.dehu.comparecencia', 'Abierta en la DEHú el '.$fecha.': '.$n->tipo, $n, $c->id);

        return $n;
    }

    private function guardarDoc(Cliente $c, string $bytes, string $nombre, string $mime, Notificacion $n, int $userId): Documento
    {
        $ext = str_contains($mime, 'pdf') ? 'pdf' : (str_contains($mime, 'zip') ? 'zip' : (pathinfo($nombre, PATHINFO_EXTENSION) ?: 'bin'));
        $nombre = preg_match('/\.[a-z0-9]{2,4}$/i', $nombre) ? $nombre : $nombre.'.'.$ext;
        $tmp = tempnam(sys_get_temp_dir(), 'dehu');
        file_put_contents($tmp, $bytes);
        try {
            $g = $this->almacen->guardar(new UploadedFile($tmp, $nombre, $mime, null, true), $c->id);
        } finally {
            @unlink($tmp);
        }

        return Documento::create([
            'cliente_id' => $c->id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => 'fiscal/requerimientos', 'tipo' => 'docu',
            'nombre' => ClasificadorDocumentos::nombreSeguro(mb_substr($n->organismo.' · '.$nombre, 0, 180)), 'nombre_original' => ClasificadorDocumentos::nombreSeguro($nombre),
            'fecha' => Plazos::hoy()->toDateString(), 'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'],
            'origen' => 'DEHú', 'compartido' => true, 'por_revisar' => false, 'subido_por' => $userId,
        ]);
    }

    /** Organismo de la ficha a partir del emisor de la DEHú */
    public static function organismo(string $nombre): string
    {
        $n = mb_strtolower($nombre);

        return match (true) {
            str_contains($n, 'estatal de administración tributaria') => 'AEAT',
            // TGSS, INSS e ISM se agrupan como Seguridad Social
            str_contains($n, 'seguridad social') || str_contains($n, 'social de la marina') => 'TGSS',
            str_contains($n, 'servicio público de empleo') => 'SEPE',
            str_contains($n, 'tráfico') => 'DGT',
            str_contains($n, 'junta de andalucía') || str_contains($n, 'tributaria de andalucía') => 'JA',
            str_contains($n, 'ayuntamiento') || str_contains($n, 'diputación') => 'AYTO',
            default => 'OTRO',
        };
    }

    private function anotar(Cliente $c, bool $ok, string $msg, int $nuevas): void
    {
        $d = $c->datos ?? [];
        $d['dehuSync'] = ['at' => now()->toIso8601String(), 'ok' => $ok, 'msg' => mb_substr($msg, 0, 300), 'nuevas' => $nuevas];
        $c->datos = $d;
        $c->saveQuietly();
    }

    public static function mensaje(Throwable $e): string
    {
        $m = $e->getMessage();
        if ($e instanceof LemaError && str_contains($m, '4103')) {
            return 'La DEHú no reconoce el certificado: falta el alta como Gran Destinatario con este certificado (o la registrada es otra).';
        }

        return $m;
    }
}
