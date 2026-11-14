<?php

namespace App\Support;

/**
 * Análisis básico de un PDF sin librerías externas: número de páginas y si tiene texto seleccionable o solo imágenes
 * (documento escaneado que necesita OCR). Lee los flujos de contenido (descomprimiendo FlateDecode) y busca operadores de texto.
 * Es una comprobación orientativa: no extrae el texto ni lo interpreta.
 */
final class PdfInfo
{
    /** @return array{pdf: bool, paginas: int, texto: bool, imagenes: int, escaneado: bool, cifrado: bool} */
    public static function analizar(string $b): array
    {
        $out = ['pdf' => strncmp($b, '%PDF', 4) === 0, 'paginas' => 0, 'texto' => false, 'imagenes' => 0, 'escaneado' => false, 'cifrado' => false];
        if (! $out['pdf']) {
            return $out;
        }
        $out['paginas'] = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $b);
        $out['imagenes'] = preg_match_all('#/Subtype\s*/Image#', $b);
        $out['cifrado'] = (bool) preg_match('#/Encrypt\s#', $b);
        $txt = (bool) preg_match('#\bBT\b[\s\S]{0,4000}?(Tj|TJ)#', $b);
        if (! $txt && preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $b, $m)) {
            foreach (array_slice($m[1], 0, 400) as $raw) {
                $dec = @gzuncompress($raw);
                if ($dec === false) {
                    $dec = @gzinflate(substr($raw, 2));
                }
                if (is_string($dec) && preg_match('#\bBT\b[\s\S]{0,4000}?(Tj|TJ|\'|")#', $dec) && str_contains($dec, 'Tf')) {
                    $txt = true;
                    break;
                }
            }
        }
        $out['texto'] = $txt;
        // Sin texto y con imágenes: escaneado. Cifrado: no se puede saber (los flujos no se leen)
        $out['escaneado'] = ! $txt && ! $out['cifrado'] && $out['imagenes'] > 0;

        return $out;
    }
}
