<?php
declare(strict_types=1);

/**
 * Minimal streaming ZIP writer (store / no compression).
 * Photos are already compressed, so storing is fast and needs no ext-zip.
 */
final class ZipStream
{
    private int $offset = 0;
    private array $central = [];
    private array $names = [];

    public function __construct(string $downloadName)
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        @ini_set('zlib.output_compression', '0');

        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'photos.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
    }

    public function uniqueName(string $name): string
    {
        $name = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $try  = $name;
        $n = 1;
        while (isset($this->names[mb_strtolower($try)])) {
            $n++;
            $try = $base . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
        }
        $this->names[mb_strtolower($try)] = true;
        return $try;
    }

    public function addFile(string $name, string $data, ?int $mtime = null): void
    {
        [$dosTime, $dosDate] = $this->dosTime($mtime ?? time());
        $crc  = (int) hexdec(hash('crc32b', $data));
        $size = strlen($data);
        $flags = 0x0800; // UTF-8 file names

        $local = pack('VvvvvvVVVvv',
            0x04034b50, 20, $flags, 0, $dosTime, $dosDate,
            $crc, $size, $size, strlen($name), 0
        ) . $name;

        echo $local;
        echo $data;
        flush();

        $this->central[] = pack('VvvvvvvVVVvvvvvVV',
            0x02014b50, 20, 20, $flags, 0, $dosTime, $dosDate,
            $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $this->offset
        ) . $name;

        $this->offset += strlen($local) + $size;
    }

    public function finish(): void
    {
        $cd = implode('', $this->central);
        echo $cd;
        echo pack('VvvvvVVv',
            0x06054b50, 0, 0, count($this->central), count($this->central),
            strlen($cd), $this->offset, 0
        );
        flush();
    }

    private function dosTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) $d = getdate(mktime(0, 0, 0, 1, 1, 1980));
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$time, $date];
    }
}
