<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$storage = config()['storage']; $now = time(); $removed = 0;
foreach (glob($storage . '/audio/*.wav') ?: [] as $path) {
    if (filemtime($path) > $now - 14400) continue;
    $lock = fopen(substr($path, 0, -4) . '.lock', 'c');
    if (!$lock) continue;
    if (flock($lock, LOCK_EX | LOCK_NB)) {
        clearstatcache(true, $path);
        if (is_file($path) && filemtime($path) < $now - 14400 && unlink($path)) $removed++;
        flock($lock, LOCK_UN);
    }
    fclose($lock);
}
// Intermediates have a random suffix and cannot be confused with final cache files.
foreach (glob($storage . '/audio/*.source*') ?: [] as $path) {
    if (filemtime($path) < $now - 14400 && unlink($path)) $removed++;
}
foreach (array_merge(glob($storage . '/calls/*.json') ?: [], glob($storage . '/login-*.json') ?: []) as $path) {
    $fp = fopen($path, 'r+'); if (!$fp) continue;
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        $data = json_decode(stream_get_contents($fp), true);
        $expires = $data['expires'] ?? (($data['since'] ?? filemtime($path)) + 14400);
        if ($expires < $now && unlink($path)) $removed++;
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
echo "Removed $removed expired private files.\n";
