<?php
declare(strict_types=1);

final class Audio {
    // Bump when rendering changes, so old narrowband/broken cache files aren't reused.
    private const CACHE_VERSION = 'pcm-v2';
    /** Discard process I/O without using Unix-only paths on Windows. */
    public static function processDescriptors(string $osFamily = PHP_OS_FAMILY): array {
        $nullDevice = $osFamily === 'Windows' ? 'NUL' : '/dev/null';
        return [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']];
    }
    public static function requestedRate(string $value): float {
        $rate = is_numeric($value) ? (float)$value : NAN;
        if (!in_array($rate, [0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0], true)) throw new InvalidArgumentException('Choose a playback speed from 0.50 to 2.00 in 0.25 steps.');
        return $rate;
    }
    public static function codec(string $profile, ?string $phoneCodec = null): string {
        if (!in_array($profile, ['browser', 'phone'], true)) throw new InvalidArgumentException('Invalid audio profile.');
        if ($profile === 'browser') return 'pcm_s16le';
        $codec = $phoneCodec ?? config()['phone_codec'];
        if (!in_array($codec, ['pcm_s16le', 'pcm_mulaw', 'pcm_alaw'], true)) {
            throw new InvalidArgumentException('PHONE_AUDIO_CODEC must be pcm_s16le, pcm_mulaw, or pcm_alaw.');
        }
        return $codec;
    }
    public static function cacheId(array $mailbox, string $file, float $rate, string $profile, ?string $phoneCodec = null): string {
        $codec = self::codec($profile, $phoneCodec);
        return hash('sha256', json_encode([self::CACHE_VERSION, $mailbox, filename($file), speed($rate), $profile, $codec], JSON_THROW_ON_ERROR));
    }
    public static function command(string $source, string $target, float $rate, string $profile, ?string $phoneCodec = null): array {
        $codec = self::codec($profile, $phoneCodec);
        $rate = speed($rate);
        $command = [config()['ffmpeg'], '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-protocol_whitelist', 'file,pipe', '-f', 'wav', '-i', $source, '-t', '1800', '-vn'];
        // Do tempo processing at 48 kHz, before any telephone downsampling. Never
        // stretch speech twice (the browser plays these pre-rendered files at 1x).
        // At normal speed don't run atempo at all.
        if ($rate !== 1.0) array_push($command, '-af', 'aresample=48000,atempo=' . number_format($rate, 2, '.', ''));
        if ($profile === 'phone') array_push($command, '-ar', '8000', '-ac', '1');
        else array_push($command, '-ar', '48000');
        // Always a WAV container with an explicit codec, never raw G.711 bytes.
        // A core that expects G.711 can select it without changing browser audio.
        array_push($command, '-c:a', $codec, '-f', 'wav', $target);
        return $command;
    }
    /** Inspect rendered PCM/G.711 WAV chunks. Header-only and truncated files aren't playable. */
    public static function wavInfo(string $path): ?array {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;
        try {
            $size = fstat($fp)['size'];
            $header = fread($fp, 12);
            if (strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') return null;
            if (unpack('V', substr($header, 4, 4))[1] + 8 !== $size) return null;
            $format = null; $bytes = 0;
            while (ftell($fp) + 8 <= $size) {
                $chunk = fread($fp, 8);
                if (strlen($chunk) !== 8) return null;
                $length = unpack('V', substr($chunk, 4, 4))[1]; $start = ftell($fp);
                if ($length > $size - $start) return null;
                if (substr($chunk, 0, 4) === 'fmt ') {
                    if ($length < 16) return null;
                    $format = unpack('vcodec/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits', fread($fp, 16));
                } elseif (substr($chunk, 0, 4) === 'data') $bytes += $length;
                if (fseek($fp, $start + $length + ($length % 2)) !== 0) return null;
            }
            if (!$format || $format['channels'] < 1 || $format['sample_rate'] < 8000) return null;
            $codec = match ($format['codec']) { 1 => 'pcm_s16le', 6 => 'pcm_alaw', 7 => 'pcm_mulaw', default => null };
            if ($codec === null) return null;
            $sampleBytes = $codec === 'pcm_s16le' ? 2 : 1;
            if ($format['bits'] !== $sampleBytes * 8 || $format['block_align'] !== $format['channels'] * $sampleBytes
                || $format['byte_rate'] !== $format['sample_rate'] * $format['block_align']
                || $bytes < $format['block_align'] || $bytes % $format['block_align'] !== 0) return null;
            // Only the telephone G.711 modes we actually render are accepted.
            if ($codec !== 'pcm_s16le' && ($format['sample_rate'] !== 8000 || $format['channels'] !== 1)) return null;
            return ['sample_rate' => $format['sample_rate'], 'channels' => $format['channels'], 'codec' => $codec, 'bits' => $format['bits'],
                'duration' => $bytes / $format['byte_rate'], 'data_bytes' => $bytes];
        } finally { fclose($fp); }
    }
    public static function validateRemote(string $url): void {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), config()['media_hosts'], true)
            || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            throw new RuntimeException('The audio URL is not on an allowed HTTPS media host.');
        }
    }
    public static function prepare(array $mailbox, array $message, float $rate, string $profile = 'phone'): string {
        $rate = speed($rate);
        $id = self::cacheId($mailbox, $message['filename'], $rate, $profile);
        $base = config()['storage'] . '/audio/' . $id;
        $lock = fopen($base . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Audio cache is unavailable.');
        try {
            if (is_file($base . '.wav')) {
                $info = self::wavInfo($base . '.wav');
                if ($info !== null && $info['codec'] === self::codec($profile)
                    && $info['sample_rate'] === ($profile === 'phone' ? 8000 : 48000)
                    && ($profile !== 'phone' || $info['channels'] === 1)) { touch($base . '.wav'); return $id; }
                if (!unlink($base . '.wav')) throw new RuntimeException('Could not replace invalid cached audio.');
            }
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
                $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $errno = curl_errno($ch);
                curl_close($ch); fclose($fp);
                if (!$ok || $status !== 200 || $bytes < 44) throw new RuntimeException('Could not download voicemail audio (HTTP ' . $status . ', cURL error ' . $errno . ', bytes ' . $bytes . ').');
                // Only accept WAV containers, not arbitrary FFmpeg playlists or network inputs.
                $magic = file_get_contents($source, false, null, 0, 12);
                if (substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WAVE') throw new RuntimeException('The voicemail server did not return WAV audio.');
                self::convert($source, $target, $rate, $profile);
                chmod($target, 0600);
                if (!rename($target, $base . '.wav')) throw new RuntimeException('Audio cache write failed.');
            } finally {
                if (isset($source) && is_file($source)) unlink($source);
                if (isset($target) && is_file($target)) unlink($target);
            }
            return $id;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    /** Local WAV conversion, also exercised by native FFmpeg integration tests. */
    public static function convert(string $source, string $target, float $rate, string $profile, ?string $phoneCodec = null): void {
        $command = self::command($source, $target, $rate, $profile, $phoneCodec);
        if (!function_exists('proc_open')) throw new RuntimeException('Audio conversion requires proc_open to be enabled in PHP.');
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
        clearstatcache(true, $target);
        if ($exit !== 0) throw new RuntimeException('Audio conversion failed. Check that FFMPEG_BIN points to a working FFmpeg executable and the web-server account can run it.');
        if (self::wavInfo($target) === null) throw new RuntimeException('The voicemail contains no playable audio or the WAV is incomplete. Try another message.');
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
