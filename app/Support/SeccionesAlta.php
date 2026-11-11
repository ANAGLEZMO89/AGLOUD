<?php

namespace App\Support;

use App\Models\Cliente;
use Carbon\Carbon;

/**
 * Secciones de la ficha de alta (ALTA_SPEC del prototipo).
 * Campo: [clave, etiqueta, tipo, opciones|null, ancho completo, placeholder]
 * Tipos: text, date, sel, tog, number, password, file, combo
 */
class SeccionesAlta
{
    public const DESC = [
        'general' => 'Identificación, domicilio fiscal, contacto y asesor responsable.',
        'bancos' => 'Cuentas del cliente. La principal se usa para domiciliar la cuota con mandato SEPA.',
        'cuota' => 'Tarifa del cliente: los conceptos y los servicios incluidos se cargan solos al hacerle la factura.',
        'centros' => 'Locales y centros donde se desarrolla la actividad, con su código de cuenta de cotización.',
        'acts' => 'Epígrafes CNAE 2025 e IAE, régimen de IVA y de IRPF de cada actividad.',
        'reps' => 'Administradores, socios y representantes legales o laborales.',
        'regs' => 'Escrituras e inscripciones: notario, protocolo, tomo, folio, hoja y archivo.',
        'certs' => 'Certificados para presentar modelos y leer notificaciones en nombre del cliente.',
        'logo' => 'Imagen corporativa y firma escaneada del cliente.',
    ];

    /** Secciones visibles según el tipo de cliente (altaSecs) */
    public static function secciones(string $tipo): array
    {
        $part = $tipo === 'Particular';
        $ent = in_array($tipo, ['Sociedad', 'Comunidad de bienes'], true);
        $s = ['general' => 'Datos generales', 'bancos' => 'Datos bancarios', 'cuota' => 'Cuota'];
        if (! $part) {
            $s['centros'] = 'Centros de trabajo';
            $s['acts'] = 'Actividades';
            $s['reps'] = $tipo === 'Comunidad de bienes' ? 'Comuneros y representantes' : 'Representantes';
        }
        if ($ent) {
            $s['regs'] = 'Datos registrales';
        }
        $s['certs'] = 'Certificados digitales';
        $s['logo'] = 'Logo y firma';

        return $s;
    }

    public static function spec(string $k, string $tipo = 'Autónomo'): ?array
    {
        $cb = $tipo === 'Comunidad de bienes';
        $c = fn ($x) => config('agcloud.'.$x);

        return match ($k) {
            'bancos' => [
                'one' => 'cuenta bancaria',
                'empty' => 'Sin cuentas bancarias. Añade al menos una para domiciliar la cuota.',
                'cols' => ['IBAN', 'Entidad', 'Titular', 'Mandato SEPA'],
                'fields' => [
                    ['iban', 'IBAN *', 'text', null, true, 'ES00 0000 0000 0000 0000 0000'],
                    ['bic', 'BIC / SWIFT', 'text'], ['entidad', 'Entidad', 'text'], ['titular', 'Titular', 'text'],
                    ['sepa', 'Referencia mandato SEPA', 'text'], ['fSepa', 'Fecha de firma del mandato', 'date'],
                    ['principal', 'Principal', 'tog'],
                ],
            ],
            'centros' => [
                'one' => 'centro de trabajo',
                'empty' => 'Sin centros de trabajo.',
                'cols' => ['Denominación', 'Dirección', 'Provincia', 'CCC', 'Trabajadores'],
                'fields' => [
                    ['denom', 'Denominación *', 'text'], ['apertura', 'Fecha de apertura', 'date'],
                    ['dir', 'Dirección', 'text', null, true], ['cp', 'Código postal', 'text'], ['mun', 'Municipio', 'text'],
                    ['prov', 'Provincia', 'sel', $c('provincias')], ['ccc', 'CCC asociado', 'text', null, false, '21 1234567 89'],
                    ['trab', 'Nº trabajadores', 'number'], ['principal', 'Principal', 'tog'],
                ],
            ],
            'acts' => [
                'one' => 'actividad',
                'empty' => 'Sin actividades. Añade la actividad principal con su CNAE e IAE.',
                'cols' => ['Descripción', 'CNAE 2025', 'IAE', 'Régimen IVA', 'IRPF'],
                'fields' => [
                    ['desc', 'Descripción *', 'text', null, true], ['fIni', 'F. inicio', 'date'], ['fFin', 'F. fin', 'date'],
                    ['iva', 'Régimen IVA', 'sel', $c('iva_reg')], ['irpf', 'IRPF', 'sel', $c('irpf_reg')],
                    ['cnae', 'CNAE 2025', 'combo', 'cnae', true, 'Escribe el código o una palabra: 5630, bar, restaurante…'],
                    ['iae', 'IAE', 'combo', 'iae', true, 'Escribe el epígrafe o una palabra: 673.2, cafés, abogados…'],
                    ['ccc', 'Cuenta de cotización', 'text', null, false, '21 1234567 89'], ['cccProv', 'Provincia CCC', 'sel', $c('provincias')],
                    ['principal', 'Principal', 'tog'], ['recEq', 'R. equivalencia', 'tog'], ['caja', 'Criterio de caja', 'tog'],
                ],
            ],
            'reps' => [
                'one' => 'representante',
                'empty' => 'Sin representantes ni socios.',
                'cols' => ['NIF', 'Nombre', 'Cargo', '% Particip.', 'Caducidad cargo'],
                'fields' => [
                    ['nif', 'NIF *', 'text'], ['nombre', 'Nombre y apellidos *', 'text', null, true],
                    ['cargo', 'Cargo', 'sel', $cb ? $c('cargos_cb') : $c('cargos')],
                    ['pct', $cb ? '% Participación' : '% Acciones', 'text', null, false, '0,00'], ['nAcc', 'N. acciones', 'number'],
                    ['vNom', 'V. nominal (€)', 'text', null, false, '0,00'], ['caduc', 'Caducidad cargo', 'date'],
                    ['rLegal', 'R. legal', 'tog'], ['rLaboral', 'R. laboral', 'tog'], ['socio', 'Socio', 'tog'], ['admin', 'Admin.', 'tog'],
                ],
            ],
            'regs' => [
                'one' => 'dato registral',
                'empty' => 'Sin datos registrales. Añade la escritura de constitución y las posteriores.',
                'cols' => ['F. creación', 'Tipo', 'Descripción', 'Notario', 'Inscripción'],
                'fields' => [
                    ['fCrea', 'F. creación *', 'date'], ['tipo', 'Tipo', 'sel', $cb ? $c('reg_cb') : $c('reg_tipos')],
                    ['desc', 'Descripción', 'text', null, true], ['fReg', 'F. registro', 'date'], ['notario', 'Notario', 'text'],
                    ['prot', 'N. protocolo', 'text'], ['tomo', 'Tomo', 'text'], ['secc', 'Sección', 'text'], ['folio', 'Folio', 'text'],
                    ['hoja', 'Hoja', 'text'], ['insc', 'Inscripción', 'text'], ['rm', 'Registro Mercantil', 'sel', $c('provincias')], ['irus', 'IRUS', 'text'],
                    ['archivo', 'Archivo (escritura)', 'file', '.pdf,image/*', true],
                ],
            ],
            'certs' => [
                'one' => 'certificado digital',
                'empty' => 'Sin certificados. Súbelos para presentar modelos y leer notificaciones en nombre del cliente.',
                'cols' => ['Tipo', 'Titular', 'Emisor', 'Caduca', 'Usos'],
                'fields' => [
                    ['tipo', 'Tipo', 'sel', $c('cert_tipos'), true], ['titular', 'Titular *', 'text'], ['nifT', 'NIF titular', 'text'],
                    ['emisor', 'Emisor', 'sel', $c('emisores')], ['fEmi', 'F. emisión', 'date'], ['fCad', 'F. caducidad *', 'date'],
                    ['archivo', 'Archivo (.pfx / .p12 / .cer)', 'file', '.pfx,.p12,.cer,.crt,.pem', true],
                    ['pass', 'Contraseña del certificado', 'password'],
                    ['aeat', 'Uso AEAT', 'tog'], ['ss', 'Uso Seg. Social', 'tog'], ['dehu', 'Uso DEHú', 'tog'],
                ],
            ],
            default => null,
        };
    }

    /** Valores por defecto de un registro nuevo (blank) */
    public static function blank(string $k, Cliente $f, array $l): array
    {
        $first = ! count($l);
        $irpf = config('agcloud.irpf_reg');
        $ct = config('agcloud.cert_tipos');

        return match ($k) {
            'bancos' => ['iban' => '', 'bic' => '', 'entidad' => '', 'titular' => $f->nombre, 'sepa' => '', 'fSepa' => '', 'principal' => $first],
            'centros' => ['denom' => $first ? 'Centro principal' : '', 'apertura' => '', 'dir' => $f->domicilio, 'cp' => $f->cp, 'mun' => $f->municipio, 'prov' => $f->provincia, 'ccc' => '', 'trab' => '0', 'principal' => $first],
            'acts' => ['desc' => '', 'fIni' => '', 'fFin' => '', 'iva' => 'Régimen general', 'irpf' => $f->tipo === 'Sociedad' ? $irpf[3] : ($f->tipo === 'Comunidad de bienes' ? $irpf[4] : $irpf[1]), 'cnae' => '', 'iae' => '', 'ccc' => '', 'cccProv' => $f->provincia, 'principal' => $first, 'recEq' => false, 'caja' => false],
            'reps' => ['nif' => '', 'nombre' => '', 'cargo' => $f->tipo === 'Comunidad de bienes' ? 'Comunero' : ($f->tipo === 'Sociedad' ? 'Administrador único' : 'Apoderado'), 'pct' => '', 'nAcc' => '', 'vNom' => '', 'caduc' => '', 'rLegal' => $first, 'rLaboral' => false, 'socio' => $f->tipo !== 'Autónomo', 'admin' => $f->tipo === 'Sociedad' && $first],
            'regs' => ['fCrea' => '', 'tipo' => ! $first ? ($f->tipo === 'Comunidad de bienes' ? 'Modificación del contrato' : 'Nombramiento de cargos') : ($f->tipo === 'Comunidad de bienes' ? 'Contrato privado de constitución' : 'Constitución'), 'desc' => $first ? 'Constitución de '.$f->nombre : '', 'fReg' => '', 'notario' => '', 'prot' => '', 'tomo' => '', 'secc' => '', 'folio' => '', 'hoja' => '', 'insc' => '', 'rm' => $f->provincia, 'irus' => '', 'archivo' => null],
            'certs' => ['tipo' => $f->tipo === 'Sociedad' ? $ct[1] : ($f->tipo === 'Comunidad de bienes' ? $ct[2] : $ct[0]), 'titular' => $f->nombre, 'nifT' => $f->nif, 'emisor' => 'FNMT-RCM', 'fEmi' => '', 'fCad' => '', 'archivo' => null, 'pass' => '', 'aeat' => true, 'ss' => false, 'dehu' => true],
            default => [],
        };
    }

    /** Validación (check). Devuelve el mensaje de error o '' */
    public static function check(string $k, array $d, array $lista, ?int $idx): string
    {
        return match ($k) {
            'bancos' => ! Validacion::iban($d['iban'] ?? '') ? 'El IBAN no es válido. Revisa los dígitos de control.' : '',
            'centros' => trim((string) ($d['denom'] ?? '')) === '' ? 'Indica la denominación del centro.' : '',
            'acts' => trim((string) ($d['desc'] ?? '')) === '' ? 'Escribe la descripción de la actividad.' : '',
            'reps' => (function () use ($d, $lista, $idx) {
                if (! Validacion::nifOk($d['nif'] ?? '')) {
                    return 'El NIF/NIE del representante no es válido.';
                }
                if (trim((string) ($d['nombre'] ?? '')) === '') {
                    return 'Indica el nombre.';
                }
                $tot = Formato::parseNum($d['pct'] ?? '');
                foreach ($lista as $i => $r) {
                    if ($i !== $idx) {
                        $tot += Formato::parseNum($r['pct'] ?? '');
                    }
                }

                return $tot > 100.001 ? 'La participación total sería '.Formato::num($tot).' %. No puede superar el 100 %.' : '';
            })(),
            'regs' => empty($d['fCrea']) ? 'Indica la fecha de creación.' : '',
            'certs' => trim((string) ($d['titular'] ?? '')) === '' ? 'Indica el titular.' : (empty($d['fCad']) ? 'Indica la fecha de caducidad.' : ''),
            default => '',
        };
    }

    /** Fila de la tabla: [celdas, etiqueta, fondo, texto] */
    public static function row(string $k, array $d): array
    {
        $f = fn ($x) => Formato::fecha($x ?? '');

        return match ($k) {
            'bancos' => [[Formato::iban($d['iban'] ?? ''), $d['entidad'] ?? '', $d['titular'] ?? '', ! empty($d['sepa']) ? $d['sepa'].(! empty($d['fSepa']) ? ' · '.$f($d['fSepa']) : '') : 'Pendiente'], ! empty($d['principal']) ? 'Principal' : '', '', ''],
            'centros' => [[$d['denom'] ?? '', implode(', ', array_filter([$d['dir'] ?? '', $d['cp'] ?? '', $d['mun'] ?? ''])), $d['prov'] ?? '', $d['ccc'] ?? '', (string) ($d['trab'] ?? 0)], ! empty($d['principal']) ? 'Principal' : '', '', ''],
            'acts' => [[$d['desc'] ?? '', $d['cnae'] ?? '', $d['iae'] ?? '', ($d['iva'] ?? '').(! empty($d['recEq']) ? ' + R.E.' : '').(! empty($d['caja']) ? ' + Caja' : ''), $d['irpf'] ?? ''],
                ! empty($d['fFin']) ? 'Baja '.$f($d['fFin']) : (! empty($d['principal']) ? 'Principal' : ''), ! empty($d['fFin']) ? '#F4F4F0' : '', ! empty($d['fFin']) ? '#6A6A65' : ''],
            'reps' => [[strtoupper($d['nif'] ?? ''), $d['nombre'] ?? '', $d['cargo'] ?? '', ($d['pct'] ?? '') !== '' ? Formato::num(Formato::parseNum($d['pct'])).' %' : '', $f($d['caduc'] ?? '')],
                implode(' · ', array_filter([! empty($d['rLegal']) ? 'R. legal' : '', ! empty($d['rLaboral']) ? 'R. laboral' : '', ! empty($d['admin']) ? 'Admin.' : ''])), '', ''],
            'regs' => [[$f($d['fCrea'] ?? ''), $d['tipo'] ?? '', $d['desc'] ?? '', $d['notario'] ?? '', implode(' · ', array_filter([! empty($d['tomo']) ? 'T '.$d['tomo'] : '', ! empty($d['folio']) ? 'F '.$d['folio'] : '', ! empty($d['hoja']) ? 'H '.$d['hoja'] : '', ! empty($d['insc']) ? 'Insc. '.$d['insc'] : '']))],
                ! empty($d['archivo']) ? 'Con archivo' : '', '#F4F4F0', '#3C3C3C'],
            'certs' => (function () use ($d, $f) {
                $st = self::estadoCert($d['fCad'] ?? null);

                return [[$d['tipo'] ?? '', ($d['titular'] ?? '').(! empty($d['empresaCert']) ? ' · '.$d['empresaCert'] : ''), $d['emisor'] ?? '', $f($d['fCad'] ?? ''), implode(', ', array_filter([! empty($d['aeat']) ? 'AEAT' : '', ! empty($d['ss']) ? 'SS' : '', ! empty($d['dehu']) ? 'DEHú' : '']))], $st[0], $st[1], $st[2]];
            })(),
            default => [[], '', '', ''],
        };
    }

    /** [texto, fondo, color] del estado de un certificado (certSt) */
    public static function estadoCert(?string $fCad): array
    {
        if (! $fCad) {
            return ['Sin fecha', '#F4F4F0', '#6A6A65'];
        }
        $days = (int) round(now()->startOfDay()->diffInDays(Carbon::parse($fCad)->startOfDay(), false));

        return $days < 0 ? ['Caducado', '#FDE2E1', '#B42318'] : ($days <= 45 ? ['Caduca en '.$days.' días', '#FCEBCB', '#6E4300'] : ['Vigente', '#ECF7C8', '#3A4A00']);
    }
}
