<?php

namespace App\Services\Dehu;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Cliente del servicio web LEMA de la DEHú para Grandes Destinatarios (Especificación v4.1, DEHú 4.4.0).
 *
 * - SOAP 1.1 document/literal sobre HTTPS, WS-Security con BinarySecurityToken X.509v3,
 *   firma RSA-SHA512 y digest SHA-512 sobre el Body y el Timestamp (c14n exclusiva).
 * - Cabeceras HTTP obligatorias: Content-Length y «Expect: 100-continue», sin chunking.
 * - Los documentos llegan como adjuntos (SwA o MTOM): se leen del mensaje multipart.
 * - localiza() solo LISTA (no tiene efectos jurídicos). peticionAcceso() COMPARECE: la notificación
 *   se entiende practicada (art. 43 Ley 39/2015). Solo se llama a petición expresa de un usuario.
 */
class Lema
{
    public const RUTAS = [
        'pro' => 'https://gd-dehuws.redsara.es/ws/v2/lema',
        'se' => 'https://se-gd-dehuws.redsara.es/ws/v2/lema',
    ];

    private const SOAPACTION = 'https://administracionelectronica.gob.es/notifica/ws/lema/';

    private const NS = [
        'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'wsse' => 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd',
        'wsu' => 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        'loc' => 'http://administracion.gob.es/punto-unico-notificaciones/localiza',
        'pac' => 'http://administracion.gob.es/punto-unico-notificaciones/peticionAcceso',
        'pca' => 'http://administracion.gob.es/punto-unico-notificaciones/consultaAnexos',
        'pcap' => 'http://administracion.gob.es/punto-unico-notificaciones/consultaAcusePdf',
    ];

    private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    private const RSA_SHA512 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512';

    private const SHA512 = 'http://www.w3.org/2001/04/xmlenc#sha512';

    /** Último intercambio (sin el certificado), para el registro de errores */
    public array $ultimo = [];

    /** @param  callable|null  $http  fn(string $url, string $action, string $xml): array{0:int,1:string,2:string}  (código, content-type, cuerpo); para pruebas */
    public function __construct(private string $entorno = '', private $http = null)
    {
        $this->entorno = $this->entorno ?: (string) config('agcloud.dehu.entorno', 'pro');
    }

    public function endpoint(): string
    {
        return self::RUTAS[$this->entorno] ?? self::RUTAS['pro'];
    }

    // ------------------------------------------------------------------ operaciones

    /**
     * Notificaciones y comunicaciones PENDIENTES de acceso donde el NIF es titular o destinatario.
     *
     * @return array{envios: array<int, array>, hayMas: bool, totalPag: int, codigo: string, descripcion: string}
     */
    public function localiza(Credencial $cred, string $nif, int $pagina = 1): array
    {
        $nif = strtoupper(trim($nif));
        $b = '<loc:Localiza xmlns:loc="'.self::NS['loc'].'">'
            .'<loc:nifTitular>'.self::x($nif).'</loc:nifTitular>'
            .'<loc:nifDestinatario>'.self::x($nif).'</loc:nifDestinatario>'
            .($pagina > 1 ? '<loc:opcionesLocaliza><loc:opcion tipo="dehu.paginador.pagina">'.$pagina.'</loc:opcion></loc:opcionesLocaliza>' : '')
            .'</loc:Localiza>';
        [$x] = $this->llamar('Localiza', $b, $cred);
        $r = $this->nodo($x, 'RespuestaLocaliza');
        $this->comprobar($r);
        $envios = [];
        foreach ($this->hijos($r, 'envios', 'item') as $it) {
            $org = fn ($n) => ($o = $this->hijo($it, $n)) ? ['codigo' => $this->texto($o, 'codigoOrganismo'), 'nombre' => $this->texto($o, 'nombreOrganismo'), 'nif' => $this->texto($o, 'nifOrganismo')] : null;
            $tit = $this->hijo($it, 'titular');
            $envios[] = [
                'identificador' => $this->texto($it, 'identificador'), 'codigoOrigen' => (int) $this->texto($it, 'codigoOrigen'),
                'concepto' => $this->texto($it, 'concepto'), 'descripcion' => $this->texto($it, 'descripcion'),
                'emisor' => $org('organismoEmisor'), 'raiz' => $org('organismoEmisorRaiz'),
                'fecha' => $this->texto($it, 'fechaPuestaDisposicion'), 'tipoEnvio' => (int) $this->texto($it, 'tipoEnvio'), 'vinculo' => (int) $this->texto($it, 'vinculo'),
                'titular' => $tit ? ['nombre' => $this->texto($tit, 'nombreTitular'), 'nif' => $this->texto($tit, 'nifTitular')] : null,
            ];
        }
        $opc = [];
        foreach ($this->hijos($r, 'opcionesRespuestaLocaliza', 'opcion') as $o) {
            $opc[trim($o->getAttribute('tipo'))] = trim($o->textContent);
        }

        return ['envios' => $envios, 'hayMas' => in_array(strtolower($this->texto($r, 'hayMasResultados')), ['1', 'true'], true),
            'totalPag' => (int) ($opc['dehu.paginador.totalPag'] ?? 1), 'codigo' => $this->texto($r, 'codigoRespuesta'), 'descripcion' => $this->texto($r, 'descripcionRespuesta')];
    }

    /**
     * COMPARECE (acepta) una notificación o accede a una comunicación y devuelve su contenido.
     *
     * @return array{fechaEvento: string, documento: ?array, anexos: array, acuse: array{referencia: ?string, csv: ?string}}
     */
    public function peticionAcceso(Credencial $cred, array $envio, string $nifReceptor, string $nombreReceptor): array
    {
        $b = '<pac:PeticionAcceso xmlns:pac="'.self::NS['pac'].'">'
            .'<pac:identificador>'.self::x($envio['identificador']).'</pac:identificador>'
            .'<pac:codigoOrigen>'.(int) $envio['codigoOrigen'].'</pac:codigoOrigen>'
            .'<pac:nifReceptor>'.self::x(strtoupper($nifReceptor)).'</pac:nifReceptor>'
            .'<pac:nombreReceptor>'.self::x(mb_substr($nombreReceptor, 0, 255)).'</pac:nombreReceptor>'
            .'<pac:evento>1</pac:evento>'
            .'<pac:concepto>'.self::x(mb_substr((string) $envio['concepto'], 0, 255)).'</pac:concepto>'
            .'</pac:PeticionAcceso>';
        [$x, $adj] = $this->llamar('PeticionAcceso', $b, $cred);
        $r = $this->nodo($x, 'RespuestaPeticionAcceso');
        $this->comprobar($r);
        $doc = $this->hijo($r, 'documento');
        $documento = $doc ? [
            'nombre' => $this->texto($doc, 'nombre') ?: 'notificacion.pdf', 'mime' => $this->texto($doc, 'mimeType') ?: 'application/pdf',
            'bytes' => $this->contenido($this->hijo($doc, 'contenido'), $adj), 'enlace' => $this->texto($doc, 'enlaceDocumento'),
            'referencia' => $this->texto($doc, 'referenciaDocumento'), 'hash' => $this->texto($doc, 'hash'),
        ] : null;
        $anexos = [];
        foreach ($r->getElementsByTagNameNS('*', 'anexoReferencia') as $a) {
            $anexos[] = ['referencia' => $this->texto($a, 'referenciaDocumento'), 'nombre' => $this->texto($a, 'nombre'), 'mime' => $this->texto($a, 'mimeType')];
        }
        foreach ($r->getElementsByTagNameNS('*', 'anexoUrl') as $a) {
            $anexos[] = ['enlace' => $this->texto($a, 'enlaceDocumento'), 'nombre' => $this->texto($a, 'nombre'), 'mime' => $this->texto($a, 'mimeType')];
        }

        return ['fechaEvento' => $this->texto($r, 'fechaEvento'), 'documento' => $documento, 'anexos' => $anexos,
            'acuse' => ['referencia' => $this->texto($r, 'referenciaPdfAcuse') ?: null, 'csv' => $this->texto($r, 'csvResguardo') ?: null]];
    }

    /** Anexo de una notificación ya comparecida: [nombre, mime, bytes] */
    public function consultaAnexos(Credencial $cred, string $nif, array $envio, string $referencia): array
    {
        $b = '<pca:ConsultaAnexos xmlns:pca="'.self::NS['pca'].'">'
            .'<pca:nifReceptor>'.self::x(strtoupper($nif)).'</pca:nifReceptor>'
            .'<pca:identificador>'.self::x($envio['identificador']).'</pca:identificador>'
            .'<pca:codigoOrigen>'.(int) $envio['codigoOrigen'].'</pca:codigoOrigen>'
            .'<pca:referencia>'.self::x($referencia).'</pca:referencia>'
            .'</pca:ConsultaAnexos>';
        [$x, $adj] = $this->llamar('ConsultaAnexos', $b, $cred);
        $r = $this->nodo($x, 'RespuestaConsultaAnexos');
        $this->comprobar($r);
        $d = $this->hijo($r, 'documento');

        return ['nombre' => $d ? $this->texto($d, 'nombre') : 'anexo', 'mime' => $d ? $this->texto($d, 'mimeType') : 'application/octet-stream', 'bytes' => $d ? $this->contenido($this->hijo($d, 'contenido'), $adj) : ''];
    }

    /** Acuse de recibo en PDF de la comparecencia: [nombre, mime, bytes] */
    public function consultaAcusePdf(Credencial $cred, string $nif, array $envio, ?string $referencia, ?string $csv): array
    {
        $b = '<pcap:ConsultaAcusePdf xmlns:pcap="'.self::NS['pcap'].'">'
            .'<pcap:nifReceptor>'.self::x(strtoupper($nif)).'</pcap:nifReceptor>'
            .'<pcap:identificador>'.self::x($envio['identificador']).'</pcap:identificador>'
            .'<pcap:codigoOrigen>'.(int) $envio['codigoOrigen'].'</pcap:codigoOrigen>'
            .'<pcap:identificadorAcusePdf>'.($referencia ? '<pcap:referencia>'.self::x($referencia).'</pcap:referencia>' : '<pcap:csvResguardo>'.self::x((string) $csv).'</pcap:csvResguardo>').'</pcap:identificadorAcusePdf>'
            .'</pcap:ConsultaAcusePdf>';
        [$x, $adj] = $this->llamar('ConsultaAcusePdf', $b, $cred);
        $r = $this->nodo($x, 'RespuestaConsultaAcusePdf');
        $this->comprobar($r);
        $d = $this->hijo($r, 'acusePdf');

        return ['nombre' => $d ? ($this->texto($d, 'nombreAcuse') ?: 'acuse.pdf') : 'acuse.pdf', 'mime' => 'application/pdf', 'bytes' => $d ? $this->contenido($this->hijo($d, 'contenido'), $adj) : ''];
    }

    // ------------------------------------------------------------------ SOAP firmado

    /** Sobre SOAP con WS-Security firmado (público para poder verificarlo en las pruebas) */
    public function sobre(string $cuerpo, Credencial $cred): string
    {
        $id = fn ($p) => $p.'-'.bin2hex(random_bytes(12));
        [$bodyId, $tsId, $certId] = [$id('Body'), $id('TS'), $id('X509')];
        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $expira = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $xml = '<soapenv:Envelope xmlns:soapenv="'.self::NS['soapenv'].'">'
            .'<soapenv:Header><wsse:Security xmlns:wsse="'.self::NS['wsse'].'" xmlns:wsu="'.self::NS['wsu'].'">'
            .'<wsu:Timestamp wsu:Id="'.$tsId.'"><wsu:Created>'.$ahora.'</wsu:Created><wsu:Expires>'.$expira.'</wsu:Expires></wsu:Timestamp>'
            .'<wsse:BinarySecurityToken EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary" ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3" wsu:Id="'.$certId.'">'.$cred->certBase64().'</wsse:BinarySecurityToken>'
            .'</wsse:Security></soapenv:Header>'
            .'<soapenv:Body xmlns:wsu="'.self::NS['wsu'].'" wsu:Id="'.$bodyId.'">'.$cuerpo.'</soapenv:Body>'
            .'</soapenv:Envelope>';
        $d = new DOMDocument('1.0', 'UTF-8');
        $d->preserveWhiteSpace = false;
        if (! $d->loadXML($xml)) {
            throw new RuntimeException('No se pudo construir la petición a la DEHú.');
        }
        $xp = new DOMXPath($d);
        $xp->registerNamespace('wsu', self::NS['wsu']);
        $xp->registerNamespace('wsse', self::NS['wsse']);
        $body = $xp->query('//*[@wsu:Id="'.$bodyId.'"]')->item(0);
        $ts = $xp->query('//*[@wsu:Id="'.$tsId.'"]')->item(0);
        $sec = $xp->query('//wsse:Security')->item(0);
        $ref = fn (DOMElement $n, string $uri) => '<ds:Reference URI="#'.$uri.'"><ds:Transforms><ds:Transform Algorithm="'.self::C14N.'"/></ds:Transforms>'
            .'<ds:DigestMethod Algorithm="'.self::SHA512.'"/><ds:DigestValue>'.base64_encode(hash('sha512', $n->C14N(true, false), true)).'</ds:DigestValue></ds:Reference>';
        $si = '<ds:SignedInfo xmlns:ds="'.self::NS['ds'].'"><ds:CanonicalizationMethod Algorithm="'.self::C14N.'"/><ds:SignatureMethod Algorithm="'.self::RSA_SHA512.'"/>'
            .$ref($body, $bodyId).$ref($ts, $tsId).'</ds:SignedInfo>';
        $f = $d->createDocumentFragment();
        $f->appendXML('<ds:Signature xmlns:ds="'.self::NS['ds'].'">'.$si.'<ds:SignatureValue/><ds:KeyInfo><wsse:SecurityTokenReference xmlns:wsse="'.self::NS['wsse'].'"><wsse:Reference URI="#'.$certId.'" ValueType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3"/></wsse:SecurityTokenReference></ds:KeyInfo></ds:Signature>');
        $sec->appendChild($f);
        $xp->registerNamespace('ds', self::NS['ds']);
        $siNode = $xp->query('//ds:SignedInfo')->item(0);
        if (! openssl_sign($siNode->C14N(true, false), $firma, $cred->clave(), OPENSSL_ALGO_SHA512)) {
            throw new RuntimeException('No se pudo firmar la petición con el certificado.');
        }
        $xp->query('//ds:SignatureValue')->item(0)->nodeValue = base64_encode($firma);

        return $d->saveXML($d->documentElement);
    }

    /** @return array{0: DOMDocument, 1: array<string,string>} respuesta y adjuntos por Content-ID */
    private function llamar(string $accion, string $cuerpo, Credencial $cred): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$this->sobre($cuerpo, $cred);
        [$codigo, $ctype, $resp] = $this->http
            ? ($this->http)($this->endpoint(), self::SOAPACTION.$accion, $xml)
            : $this->post(self::SOAPACTION.$accion, $xml);
        $this->ultimo = ['accion' => $accion, 'http' => $codigo, 'ctype' => $ctype, 'bytes' => strlen($resp)];
        [$soap, $adj] = self::partes($ctype, $resp);
        $d = new DOMDocument;
        if ($soap === '' || ! @$d->loadXML($soap, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('La DEHú no ha devuelto una respuesta válida (HTTP '.$codigo.').');
        }
        $fault = $d->getElementsByTagNameNS(self::NS['soapenv'], 'Fault')->item(0);
        if ($fault) {
            $msg = trim((string) ($fault->getElementsByTagName('faultstring')->item(0)?->textContent ?? 'Error SOAP'));
            throw new LemaError('La DEHú ha rechazado la petición: '.$msg, $msg);
        }

        return [$d, $adj];
    }

    private function post(string $action, string $xml): array
    {
        $ch = curl_init($this->endpoint());
        $ctype = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'SOAPAction: "'.$action.'"', 'Content-Length: '.strlen($xml), 'Expect: 100-continue'],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$ctype) {
                if (stripos($h, 'content-type:') === 0) {
                    $ctype = trim(substr($h, 13));
                }

                return strlen($h);
            },
        ]);
        $resp = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('No se pudo conectar con la DEHú: '.$err);
        }

        return [$codigo, $ctype, (string) $resp];
    }

    /** Separa el XML SOAP y los adjuntos de una respuesta multipart (SwA / MTOM) */
    public static function partes(string $ctype, string $resp): array
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', $ctype, $m) || stripos($ctype, 'multipart/') === false) {
            return [$resp, []];
        }
        $soap = '';
        $adj = [];
        foreach (explode('--'.$m[1], $resp) as $parte) {
            $parte = ltrim($parte, "\r\n");
            if ($parte === '' || str_starts_with($parte, '--')) {
                continue;
            }
            $sep = strpos($parte, "\r\n\r\n");
            [$cab, $cuerpo] = $sep === false ? ['', $parte] : [substr($parte, 0, $sep), substr($parte, $sep + 4)];
            $cuerpo = preg_replace("/\r\n$/", '', $cuerpo);
            if (preg_match('/content-transfer-encoding:\s*base64/i', $cab)) {
                $cuerpo = base64_decode(preg_replace('/\s+/', '', $cuerpo)) ?: '';
            }
            $cid = preg_match('/content-id:\s*<?([^>\r\n]+)>?/i', $cab, $c) ? trim($c[1]) : null;
            if ($soap === '' && preg_match('/<(\w+:)?Envelope\b/', $cuerpo)) {
                $soap = $cuerpo;
            } elseif ($cid) {
                $adj[$cid] = $cuerpo;
            }
        }

        return [$soap, $adj];
    }

    // ------------------------------------------------------------------ lectura de la respuesta

    private function comprobar(?DOMElement $r): void
    {
        if (! $r) {
            throw new RuntimeException('La DEHú ha respondido sin el resultado esperado.');
        }
        $cod = $this->texto($r, 'codigoRespuesta');
        if ($cod !== '' && $cod !== '200' && $cod !== '0') {
            throw new LemaError('DEHú '.$cod.': '.$this->texto($r, 'descripcionRespuesta'), $cod);
        }
    }

    private function contenido(?DOMElement $c, array $adj): string
    {
        if (! $c) {
            return '';
        }
        $inc = $c->getElementsByTagNameNS('http://www.w3.org/2004/08/xop/include', 'Include')->item(0);
        $href = $inc ? $inc->getAttribute('href') : trim($c->textContent);
        if (str_starts_with($href, 'cid:')) {
            $cid = rawurldecode(substr($href, 4));
            foreach ($adj as $k => $v) {
                if ($k === $cid || trim($k, '<>') === $cid) {
                    return $v;
                }
            }

            return '';
        }

        return base64_decode(preg_replace('/\s+/', '', $href), true) ?: '';
    }

    private function nodo(DOMDocument $d, string $local): ?DOMElement
    {
        $n = $d->getElementsByTagNameNS('*', $local)->item(0);

        return $n instanceof DOMElement ? $n : null;
    }

    private function hijo(DOMElement $p, string $local): ?DOMElement
    {
        foreach ($p->childNodes as $n) {
            if ($n instanceof DOMElement && $n->localName === $local) {
                return $n;
            }
        }

        return null;
    }

    /** @return DOMElement[] */
    private function hijos(DOMElement $p, string $contenedor, string $local): array
    {
        $c = $this->hijo($p, $contenedor);

        return $c ? array_values(array_filter(iterator_to_array($c->childNodes), fn ($n) => $n instanceof DOMElement && $n->localName === $local)) : [];
    }

    private function texto(DOMElement $p, string $local): string
    {
        $h = $this->hijo($p, $local) ?? ($p->getElementsByTagNameNS('*', $local)->item(0));

        return $h ? trim($h->textContent) : '';
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
