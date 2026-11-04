<?php
declare(strict_types=1);

final class Audio {
    /** Discard process I/O without using Unix-only paths on Windows. */
    public static function processDescriptors(string $osFamily = PHP_OS_FAMILY): array {
        $nullDevice = $osFamily === 'Windows' ? 'NUL' : '/dev/null';
        return [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']];
    }
    public static function validateRemote(string $url): void {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), config()['media_hosts'], true)
            || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            throw new RuntimeException('The audio URL is not on an allowed HTTPS media host.');
        }
    }
    public static function prepare(array $mailbox, array $message, float $rate): string {
        $rate = speed($rate);
        $id = hash('sha256', json_encode([$mailbox, $message['filename'], $rate]));
        $base = config()['storage'] . '/audio/' . $id;
        $lock = fopen($base . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Audio cache is unavailable.');
        try {
            if (is_file($base . '.wav')) { touch($base . '.wav'); return $id; }
            self::validateRemote($message['audio_url']);
            if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL is required.');
            $source = $base . '.' . bin2hex(random_bytes(8)) . '.source';
            $target = $source . '.wav';
            try {
                $fp = fopen($source, 'xb');
                if (!$fp) throw new RuntimeException('Cannot cache voicemail audio.');
                chmod($source, 0600);
                $bytes = 0;
                $ch = curl_init($message['audio_url']);
                curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use ($fp, &$bytes) {
                        $bytes += strlen($chunk);
                        if ($bytes > 32 * 1024 * 1024) return 0;
                        return fwrite($fp, $chunk);
                    }]);
                // file-access-url is already signed. Do not send the API bearer token to media hosts.
                $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch); fclose($fp);
                if (!$ok || $status !== 200 || $bytes < 44) throw new RuntimeException('Could not download voicemail audio.');
                // Only accept WAV containers, not arbitrary FFmpeg playlists or network inputs.
                $magic = file_get_contents($source, false, null, 0, 12);
                if (substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WAVE') throw new RuntimeException('The voicemail server did not return WAV audio.');
                $command = [config()['ffmpeg'], '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                    '-protocol_whitelist', 'file,pipe', '-f', 'wav', '-i', $source,
                    '-t', '1800', '-vn', '-af', 'atempo=' . $rate, '-ar', '8000', '-ac', '1', '-c:a', 'pcm_s16le', $target];
                if (!function_exists('proc_open')) throw new RuntimeException('Audio conversion requires proc_open to be enabled in PHP.');
                // Array arguments plus bypass_shell handle Windows executable paths with
                // spaces and avoid cmd.exe. Suppress startup warnings so API responses
                // remain valid JSON; expose an actionable, credential-free error instead.
                $process = @proc_open($command, self::processDescriptors(), $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) throw new RuntimeException('Could not start FFmpeg. Set FFMPEG_BIN to the full executable path and grant the web-server account permission to run it.');
                $deadline = microtime(true) + 30; $exit = -1;
                do {
                    $state = proc_get_status($process);
                    if (!$state['running']) { $exit = $state['exitcode']; break; }
                    if (microtime(true) >= $deadline) { proc_terminate($process, 9); break; }
                    usleep(20000);
                } while (true);
                proc_close($process);
                if ($exit !== 0 || !is_file($target) || filesize($target) < 44) throw new RuntimeException('Audio conversion failed. Check that FFMPEG_BIN points to a working FFmpeg executable and the web-server account can run it.');
                chmod($target, 0600);
                if (!rename($target, $base . '.wav')) throw new RuntimeException('Audio cache write failed.');
            } finally {
                if (isset($source) && is_file($source)) unlink($source);
                if (isset($target) && is_file($target)) unlink($target);
            }
            return $id;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function signedUrl(string $id): string {
        $expires = time() + 3600;
        return url('media.php', ['id' => $id, 'expires' => $expires, 'signature' => hash_hmac('sha256', $id . ':' . $expires, config()['key'])]);
    }
    public static function validSignature(string $id, string $expires, string $signature): bool {
        return preg_match('/\A[a-f0-9]{64}\z/', $id) && ctype_digit($expires) && (int)$expires >= time() && (int)$expires <= time() + 3600
            && hash_equals(hash_hmac('sha256', $id . ':' . $expires, config()['key']), $signature);
    }
    /** @return array{0:int,1:int}|null */
    public static function range(string $header, int $size): ?array {
        if ($header === '') return [0, $size - 1];
        if (!preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $m) || ($m[1] === '' && $m[2] === '')) return null;
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
        else { $start = (int)$m[1]; $end = $m[2] === '' ? $size - 1 : min($size - 1, (int)$m[2]); }
        return $start >= $size || $end < $start ? null : [$start, $end];
    }
}
