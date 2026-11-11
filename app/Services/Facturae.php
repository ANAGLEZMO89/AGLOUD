<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Factura;
use InvalidArgumentException;

/**
 * Factura electrónica en formato Facturae 3.2.2 (Orden HAP/1650/2015 y
 * esquema oficial de facturae.gob.es). Se genera SIN firma: para presentarla
 * en FACe hay que firmarla con certificado (XAdES), pendiente de activar.
 */
class Facturae
{
    public const NS = 'http://www.facturae.gob.es/formato/Versiones/Facturaev3_2_2.xml';

    private const PROVINCIAS_CP = ['01' => 'Álava', '02' => 'Albacete', '03' => 'Alicante', '04' => 'Almería', '05' => 'Ávila', '06' => 'Badajoz', '07' => 'Baleares', '08' => 'Barcelona', '09' => 'Burgos', '10' => 'Cáceres', '11' => 'Cádiz', '12' => 'Castellón', '13' => 'Ciudad Real', '14' => 'Córdoba', '15' => 'A Coruña', '16' => 'Cuenca', '17' => 'Girona', '18' => 'Granada', '19' => 'Guadalajara', '20' => 'Gipuzkoa', '21' => 'Huelva', '22' => 'Huesca', '23' => 'Jaén', '24' => 'León', '25' => 'Lleida', '26' => 'La Rioja', '27' => 'Lugo', '28' => 'Madrid', '29' => 'Málaga', '30' => 'Murcia', '31' => 'Navarra', '32' => 'Ourense', '33' => 'Asturias', '34' => 'Palencia', '35' => 'Las Palmas', '36' => 'Pontevedra', '37' => 'Salamanca', '38' => 'S.C. Tenerife', '39' => 'Cantabria', '40' => 'Segovia', '41' => 'Sevilla', '42' => 'Soria', '43' => 'Tarragona', '44' => 'Teruel', '45' => 'Toledo', '46' => 'Valencia', '47' => 'Valladolid', '48' => 'Bizkaia', '49' => 'Zamora', '50' => 'Zaragoza', '51' => 'Ceuta', '52' => 'Melilla'];

    private const MEDIOS = ['Transferencia' => '04', 'Domiciliación SEPA' => '02', 'Tarjeta' => '19', 'Bizum' => '13', 'Efectivo' => '01'];

    public static function provinciaDeCp(?string $cp): string
    {
        return self::PROVINCIAS_CP[substr((string) $cp, 0, 2)] ?? '';
    }

    private static function e(?string $s, int $max = 0): string
    {
        $s = trim((string) $s);
        if ($max) {
            $s = mb_substr($s, 0, $max);
        }

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function n(float|string|null $x, int $dec = 2): string
    {
        return number_format((float) $x, $dec, '.', '');
    }

    private static function tipoPersona(string $nif): string
    {
        return preg_match('/^[ABCDEFGHJNPQRSUVW]/', strtoupper($nif)) ? 'J' : 'F';
    }

    private static function direccion(string $dir, string $cp, string $mun, string $prov): string
    {
        return '<AddressInSpain><Address>'.self::e($dir, 80).'</Address><PostCode>'.self::e($cp).'</PostCode><Town>'.self::e($mun, 50).'</Town><Province>'.self::e($prov, 20).'</Province><CountryCode>ESP</CountryCode></AddressInSpain>';
    }

    private static function sujeto(string $nif, string $nombre, string $dir, string $cp, string $mun, string $prov, string $centros = ''): string
    {
        $pt = self::tipoPersona($nif);
        $x = '<TaxIdentification><PersonTypeCode>'.$pt.'</PersonTypeCode><ResidenceTypeCode>R</ResidenceTypeCode><TaxIdentificationNumber>'.self::e($nif).'</TaxIdentificationNumber></TaxIdentification>'.$centros;
        if ($pt === 'J') {
            return $x.'<LegalEntity><CorporateName>'.self::e($nombre, 80).'</CorporateName>'.self::direccion($dir, $cp, $mun, $prov).'</LegalEntity>';
        }
        $p = preg_split('/\s+/', trim($nombre));
        $nom = array_shift($p) ?? '';
        $ape1 = array_shift($p) ?? '-';
        $ape2 = implode(' ', $p);

        return $x.'<Individual><Name>'.self::e($nom, 40).'</Name><FirstSurname>'.self::e($ape1, 40).'</FirstSurname>'.($ape2 !== '' ? '<SecondSurname>'.self::e($ape2, 40).'</SecondSurname>' : '').self::direccion($dir, $cp, $mun, $prov).'</Individual>';
    }

    /** Comprueba que hay datos suficientes. Devuelve el primer problema o null. */
    public static function faltan(Cliente $c, Factura $f): ?string
    {
        $fe = $f->datos_fe ?? [];

        return match (true) {
            ! $c->nif => 'La empresa emisora no tiene NIF.',
            ! $c->domicilio || ! preg_match('/^\d{5}$/', (string) $c->cp) || ! $c->municipio => 'Completa el domicilio, el código postal y el municipio de la empresa emisora en su ficha.',
            ! $f->receptor_nif || ! $f->receptor_nombre => 'La factura electrónica necesita el nombre y el NIF del cliente.',
            ! preg_match('/^\d{5}$/', (string) ($fe['cp'] ?? '')) || empty($fe['municipio']) || ! $f->receptor_dir => 'La factura electrónica necesita la dirección, el código postal y el municipio del cliente.',
            default => null,
        };
    }

    public static function xml(Cliente $c, Factura $f): string
    {
        if ($m = self::faltan($c, $f)) {
            throw new InvalidArgumentException($m);
        }
        $fe = $f->datos_fe ?? [];
        $invoiceTotal = round((float) $f->base + (float) $f->cuota - (float) $f->retencion, 2);
        $outstanding = round($invoiceTotal + (float) $f->suplidos, 2);
        $provEm = $c->provincia ?: self::provinciaDeCp($c->cp);
        $provRc = ($fe['provincia'] ?? '') ?: self::provinciaDeCp($fe['cp']);

        // Centros administrativos (DIR3) para las Administraciones Públicas
        $centros = '';
        if (! empty($fe['dir3_oc']) && ! empty($fe['dir3_og']) && ! empty($fe['dir3_ut'])) {
            $centros = '<AdministrativeCentres>';
            foreach ([['dir3_oc', '01'], ['dir3_og', '02'], ['dir3_ut', '03']] as [$k, $rol]) {
                $centros .= '<AdministrativeCentre><CentreCode>'.self::e(strtoupper($fe[$k]), 10).'</CentreCode><RoleTypeCode>'.$rol.'</RoleTypeCode>'
                    .self::direccion((string) $f->receptor_dir, $fe['cp'], $fe['municipio'], $provRc).'</AdministrativeCentre>';
            }
            $centros .= '</AdministrativeCentres>';
        }

        $impuestos = function (array $ivas): string {
            $x = '';
            foreach ($ivas as $t => $v) {
                $x .= '<Tax><TaxTypeCode>01</TaxTypeCode><TaxRate>'.self::n($t).'</TaxRate><TaxableBase><TotalAmount>'.self::n($v['base']).'</TotalAmount></TaxableBase><TaxAmount><TotalAmount>'.self::n($v['cuota']).'</TotalAmount></TaxAmount></Tax>';
            }

            return $x;
        };

        // Cabecera de la factura (rectificativa por diferencias)
        $cab = '<InvoiceHeader><InvoiceNumber>'.self::e((string) $f->numero).'</InvoiceNumber><InvoiceSeriesCode>'.self::e($f->serie.'-'.$f->fecha->format('Y')).'</InvoiceSeriesCode>'
            .'<InvoiceDocumentType>'.($f->tipo === 'F2' ? 'FA' : 'FC').'</InvoiceDocumentType><InvoiceClass>'.($f->tipo === 'R1' ? 'OR' : 'OO').'</InvoiceClass>';
        if ($f->tipo === 'R1' && $f->rectificada) {
            $o = $f->rectificada;
            $cab .= '<Corrective><InvoiceNumber>'.self::e((string) $o->numero).'</InvoiceNumber><InvoiceSeriesCode>'.self::e($o->serie.'-'.$o->fecha->format('Y')).'</InvoiceSeriesCode>'
                .'<ReasonCode>10</ReasonCode><ReasonDescription>Detalle Operación</ReasonDescription>'
                .'<TaxPeriod><StartDate>'.$o->fecha->startOfMonth()->toDateString().'</StartDate><EndDate>'.$o->fecha->endOfMonth()->toDateString().'</EndDate></TaxPeriod>'
                .'<CorrectionMethod>02</CorrectionMethod><CorrectionMethodDescription>Rectificación por diferencias</CorrectionMethodDescription>'
                .'<InvoiceIssueDate>'.$o->fecha->toDateString().'</InvoiceIssueDate></Corrective>';
        }
        $cab .= '</InvoiceHeader>';

        $retTotal = self::n($f->retencion);
        $ret = (float) $f->retencion ? '<TaxesWithheld><Tax><TaxTypeCode>04</TaxTypeCode><TaxRate>'.self::n($f->ret_pct).'</TaxRate><TaxableBase><TotalAmount>'.self::n($f->base).'</TotalAmount></TaxableBase><TaxAmount><TotalAmount>'.$retTotal.'</TotalAmount></TaxAmount></Tax></TaxesWithheld>' : '';

        $sup = '';
        if ((float) $f->suplidos) {
            $sup = '<ReimbursableExpenses>';
            foreach ($f->lineas as $l) {
                if (! empty($l['sup'])) {
                    $sup .= '<ReimbursableExpenses><ReimbursableExpensesAmount>'.self::n(round($l['q'] * $l['p'], 2)).'</ReimbursableExpensesAmount></ReimbursableExpenses>';
                }
            }
            $sup .= '</ReimbursableExpenses>';
        }

        $lineas = '';
        foreach ($f->lineas as $l) {
            if (! empty($l['sup'])) {
                continue;
            }
            $imp = round($l['q'] * $l['p'], 2);
            $lineas .= '<InvoiceLine><ItemDescription>'.self::e($l['d'], 2500).'</ItemDescription><Quantity>'.self::n($l['q']).'</Quantity><UnitOfMeasure>01</UnitOfMeasure>'
                .'<UnitPriceWithoutTax>'.self::n($l['p'], 6).'</UnitPriceWithoutTax><TotalCost>'.self::n($imp, 6).'</TotalCost><GrossAmount>'.self::n($imp, 6).'</GrossAmount>'
                .((float) $f->ret_pct ? '<TaxesWithheld><Tax><TaxTypeCode>04</TaxTypeCode><TaxRate>'.self::n($f->ret_pct).'</TaxRate><TaxableBase><TotalAmount>'.self::n($imp).'</TotalAmount></TaxableBase><TaxAmount><TotalAmount>'.self::n(round($imp * $f->ret_pct / 100, 2)).'</TotalAmount></TaxAmount></Tax></TaxesWithheld>' : '')
                .'<TaxesOutputs><Tax><TaxTypeCode>01</TaxTypeCode><TaxRate>'.self::n($l['iva']).'</TaxRate><TaxableBase><TotalAmount>'.self::n($imp).'</TotalAmount></TaxableBase><TaxAmount><TotalAmount>'.self::n(round($imp * $l['iva'] / 100, 2)).'</TotalAmount></TaxAmount></Tax></TaxesOutputs></InvoiceLine>';
        }

        $pago = '';
        if ($f->forma_pago) {
            $venc = $f->pago_dias ? $f->fecha->addDays((int) $f->pago_dias) : $f->fecha;
            $pago = '<PaymentDetails><Installment><InstallmentDueDate>'.$venc->toDateString().'</InstallmentDueDate><InstallmentAmount>'.self::n($outstanding).'</InstallmentAmount><PaymentMeans>'.(self::MEDIOS[$f->forma_pago] ?? '01').'</PaymentMeans>'
                .($f->forma_pago === 'Transferencia' && $f->iban_emisor ? '<AccountToBeCredited><IBAN>'.self::e($f->iban_emisor).'</IBAN></AccountToBeCredited>' : '')
                .($f->forma_pago === 'Domiciliación SEPA' && $f->iban ? '<AccountToBeDebited><IBAN>'.self::e($f->iban).'</IBAN></AccountToBeDebited>' : '')
                .'</Installment></PaymentDetails>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<fe:Facturae xmlns:fe="'.self::NS.'" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
            .'<FileHeader><SchemaVersion>3.2.2</SchemaVersion><Modality>I</Modality><InvoiceIssuerType>EM</InvoiceIssuerType>'
            .'<Batch><BatchIdentifier>'.self::e($c->nif.$f->num, 70).'</BatchIdentifier><InvoicesCount>1</InvoicesCount>'
            .'<TotalInvoicesAmount><TotalAmount>'.self::n($invoiceTotal).'</TotalAmount></TotalInvoicesAmount>'
            .'<TotalOutstandingAmount><TotalAmount>'.self::n($outstanding).'</TotalAmount></TotalOutstandingAmount>'
            .'<TotalExecutableAmount><TotalAmount>'.self::n($outstanding).'</TotalAmount></TotalExecutableAmount><InvoiceCurrencyCode>EUR</InvoiceCurrencyCode></Batch></FileHeader>'
            .'<Parties><SellerParty>'.self::sujeto($c->nif, $c->nombre, (string) $c->domicilio, (string) $c->cp, (string) $c->municipio, $provEm).'</SellerParty>'
            .'<BuyerParty>'.self::sujeto($f->receptor_nif, $f->receptor_nombre, (string) $f->receptor_dir, $fe['cp'], $fe['municipio'], $provRc, $centros).'</BuyerParty></Parties>'
            .'<Invoices><Invoice>'.$cab
            .'<InvoiceIssueData><IssueDate>'.$f->fecha->toDateString().'</IssueDate><InvoiceCurrencyCode>EUR</InvoiceCurrencyCode><TaxCurrencyCode>EUR</TaxCurrencyCode><LanguageName>es</LanguageName></InvoiceIssueData>'
            .'<TaxesOutputs>'.$impuestos($f->ivas ?? []).'</TaxesOutputs>'.$ret
            .'<InvoiceTotals><TotalGrossAmount>'.self::n($f->base).'</TotalGrossAmount><TotalGrossAmountBeforeTaxes>'.self::n($f->base).'</TotalGrossAmountBeforeTaxes>'
            .'<TotalTaxOutputs>'.self::n($f->cuota).'</TotalTaxOutputs><TotalTaxesWithheld>'.$retTotal.'</TotalTaxesWithheld><InvoiceTotal>'.self::n($invoiceTotal).'</InvoiceTotal>'
            .$sup.'<TotalOutstandingAmount>'.self::n($outstanding).'</TotalOutstandingAmount><TotalExecutableAmount>'.self::n($outstanding).'</TotalExecutableAmount>'
            .((float) $f->suplidos ? '<TotalReimbursableExpenses>'.self::n($f->suplidos).'</TotalReimbursableExpenses>' : '').'</InvoiceTotals>'
            .'<Items>'.$lineas.'</Items>'.$pago
            .($f->observaciones ? '<AdditionalData><InvoiceAdditionalInformation>'.self::e($f->observaciones, 2500).'</InvoiceAdditionalInformation></AdditionalData>' : '')
            .'</Invoice></Invoices></fe:Facturae>';
    }
}
