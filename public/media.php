<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer');
try {
    requireConfig();
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { http_response_code(405); exit; }
    $id = input('id');
    if (!Audio::validSignature($id, input('expires'), input('signature'))) { http_response_code(403); exit; }
    $file = config()['storage'] . '/audio/' . $id . '.wav';
    if (!is_file($file)) { http_response_code(404); exit; }
    $size = filesize($file); $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    $range = Audio::range($rangeHeader, $size);
    if ($range === null) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
    [$start, $end] = $range;
    header('Content-Type: audio/wav'); header('Accept-Ranges: bytes'); header('Content-Length: ' . ($end - $start + 1));
    if ($rangeHeader !== '') { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $fp = fopen($file, 'rb'); fseek($fp, $start); $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(65536, $remaining)); if ($chunk === false || $chunk === '') break;
        echo $chunk; $remaining -= strlen($chunk);
    }
    fclose($fp);
} catch (Throwable) { http_response_code(500); }
