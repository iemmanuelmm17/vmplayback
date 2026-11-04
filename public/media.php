<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
// Warnings, HTML output buffers or PHP compression would corrupt WAV byte ranges.
ini_set('display_errors', '0');
ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store, no-transform'); header('Referrer-Policy: no-referrer');
header('Content-Encoding: identity');
try {
    requireConfig();
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { http_response_code(405); exit; }
    $id = input('id');
    if (!Audio::validSignature($id, input('expires'), input('signature'))) { http_response_code(403); exit; }
    $file = config()['storage'] . '/audio/' . $id . '.wav';
    $lock = fopen(config()['storage'] . '/audio/' . $id . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_SH)) throw new RuntimeException('Audio cache unavailable.');
    $fp = @fopen($file, 'rb');
    if (!$fp) { http_response_code(404); exit; }
    if (Audio::wavInfo($file) === null) { http_response_code(422); exit; }
    touch($file);
    $size = fstat($fp)['size']; $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    $range = Audio::range($rangeHeader, $size);
    if ($range === null) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
    [$start, $end] = $range;
    header('Content-Type: audio/wav'); header('Accept-Ranges: bytes'); header('Content-Length: ' . ($end - $start + 1));
    if ($rangeHeader !== '') { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    fseek($fp, $start); $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(65536, $remaining)); if ($chunk === false || $chunk === '') break;
        echo $chunk; $remaining -= strlen($chunk);
    }
    fclose($fp);
    flock($lock, LOCK_UN); fclose($lock);
} catch (Throwable) { http_response_code(500); }
