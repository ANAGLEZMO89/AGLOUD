<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Copia de seguridad completa sin depender de mysqldump:
 *  1) volcado SQL de la base de datos con PHP (PDO),
 *  2) documentos (ya cifrados) de storage/app/privado,
 *  3) todo en un ZIP cifrado con AES-256 (BACKUP_ARCHIVE_PASSWORD).
 * NUNCA incluye el .env: las claves (APP_KEY, DOCS_KEY) se guardan aparte.
 */
class CopiaSeguridad
{
    public function dir(): string
    {
        return storage_path('app/backups');
    }

    /** @return array{ruta: string, tamano: int, tablas: int, documentos: int} */
    public function crear(): array
    {
        $pass = (string) config('agcloud.backup.password');
        if (strlen($pass) < 12) {
            throw new RuntimeException('Falta BACKUP_ARCHIVE_PASSWORD en el .env (mínimo 12 caracteres).');
        }
        File::ensureDirectoryExists($this->dir(), 0750);
        $stamp = now()->format('Ymd-His');
        $sql = $this->dir().'/db-'.$stamp.'.sql';
        $tablas = $this->volcarBd($sql);

        $zipPath = $this->dir().'/agcloud-'.$stamp.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }
        $zip->setPassword($pass);
        $add = function (string $file, string $name) use ($zip) {
            $zip->addFile($file, $name);
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
        };
        $add($sql, 'base-de-datos.sql');
        $docs = 0;
        $raiz = app(AlmacenDocumentos::class)->raiz();
        if (is_dir($raiz)) {
            foreach (File::allFiles($raiz) as $f) {
                $add($f->getPathname(), 'documentos/'.str_replace('\\', '/', $f->getRelativePathname()));
                $docs++;
            }
        }
        $zip->setArchiveComment('AG Cloud · copia '.$stamp.' · '.$tablas.' tablas · '.$docs.' documentos cifrados');
        if (! $zip->close()) {
            throw new RuntimeException('Error al cerrar el ZIP de la copia.');
        }
        @unlink($sql);
        @chmod($zipPath, 0640);

        return ['ruta' => $zipPath, 'tamano' => filesize($zipPath), 'tablas' => $tablas, 'documentos' => $docs];
    }

    /** Elimina copias locales con más de $dias días. */
    public function limpiar(int $dias): int
    {
        $n = 0;
        foreach (glob($this->dir().'/agcloud-*.zip') ?: [] as $f) {
            if (filemtime($f) < now()->subDays($dias)->getTimestamp()) {
                @unlink($f);
                $n++;
            }
        }

        return $n;
    }

    public function listar(): array
    {
        $out = [];
        foreach (glob($this->dir().'/agcloud-*.zip') ?: [] as $f) {
            $out[] = ['nombre' => basename($f), 'tamano' => filesize($f), 'fecha' => filemtime($f)];
        }
        usort($out, fn ($a, $b) => $b['fecha'] <=> $a['fecha']);

        return $out;
    }

    private function volcarBd(string $dest): int
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $fh = fopen($dest, 'wb');
        fwrite($fh, '-- AG Cloud · volcado '.now()->toDateTimeString().' · '.$driver."\n");
        if ($driver === 'mysql' || $driver === 'mariadb') {
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tablas = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
            $nombres = array_column($tablas, 0);
            foreach ($nombres as $t) {
                $create = $pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
                fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
                $this->volcarFilas($pdo, $fh, $t, '`');
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } elseif ($driver === 'sqlite') {
            $rows = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);
            $nombres = [];
            foreach ($rows as $r) {
                $nombres[] = $r['name'];
                fwrite($fh, "\nDROP TABLE IF EXISTS \"{$r['name']}\";\n{$r['sql']};\n");
                $this->volcarFilas($pdo, $fh, $r['name'], '"');
            }
        } else {
            throw new RuntimeException('Motor de base de datos no soportado para la copia: '.$driver);
        }
        fclose($fh);

        return count($nombres);
    }

    private function volcarFilas(PDO $pdo, $fh, string $t, string $q): void
    {
        $st = $pdo->query('SELECT * FROM '.$q.$t.$q);
        $lote = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), array_values($row));
            $lote[] = '('.implode(',', $vals).')';
            if (count($lote) >= 200) {
                $this->insert($fh, $t, $q, array_keys($row), $lote);
                $lote = [];
            }
            $cols = array_keys($row);
        }
        if ($lote) {
            $this->insert($fh, $t, $q, $cols, $lote);
        }
    }

    private function insert($fh, string $t, string $q, array $cols, array $lote): void
    {
        $c = implode(',', array_map(fn ($x) => $q.$x.$q, $cols));
        fwrite($fh, 'INSERT INTO '.$q.$t.$q.' ('.$c.') VALUES '.implode(",\n", $lote).";\n");
    }
}
