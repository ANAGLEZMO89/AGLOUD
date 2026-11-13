<?php

namespace App\Services\Dehu;

use InvalidArgumentException;

/**
 * Certificado con su clave privada, solo en memoria, para firmar las peticiones a la DEHú.
 * Se construye desde el .p12/.pfx descifrado y se descarta al terminar la petición.
 */
class Credencial
{
    private function __construct(private string $certPem, private $clave, public readonly array $datos) {}

    public static function desdePkcs12(string $p12, string $pass): self
    {
        $c = [];
        if ($p12 === '' || ! @openssl_pkcs12_read($p12, $c, $pass)) {
            throw new InvalidArgumentException('No se puede abrir el certificado: revisa el fichero y la contraseña en la ficha.');
        }

        return self::desdePem($c['cert'], $c['pkey']);
    }

    public static function desdePem(string $certPem, string $keyPem): self
    {
        $k = openssl_pkey_get_private($keyPem);
        $x = openssl_x509_parse($certPem) ?: [];
        if (! $k || ! $x) {
            throw new InvalidArgumentException('El certificado no tiene una clave privada utilizable.');
        }
        $sub = $x['subject'] ?? [];
        $nif = (string) ($sub['serialNumber'] ?? '');
        $nif = preg_replace('/^(IDCES-|VATES-)/i', '', $nif);

        return new self($certPem, $k, [
            'cn' => (string) ($sub['CN'] ?? ''), 'nif' => strtoupper((string) $nif), 'org' => (string) ($sub['O'] ?? ''),
            'orgId' => strtoupper(preg_replace('/^VATES-/i', '', (string) ($sub['organizationIdentifier'] ?? ''))),
            'desde' => (int) ($x['validFrom_time_t'] ?? 0), 'hasta' => (int) ($x['validTo_time_t'] ?? 0),
        ]);
    }

    public function certBase64(): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', $this->certPem);
    }

    public function clave()
    {
        return $this->clave;
    }

    /** NIF al que representa el certificado (el de la entidad si es de representante) */
    public function nifEntidad(): string
    {
        return $this->datos['orgId'] ?: $this->datos['nif'];
    }
}
