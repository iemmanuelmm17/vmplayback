<?php
declare(strict_types=1);
// Actual FFmpeg integration tests: no PBX or real voicemail is involved.
$directory = sys_get_temp_dir() . '/vmplayback audio ' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('STORAGE_PATH=' . $directory . '/cache');
require dirname(__DIR__) . '/src/bootstrap.php';
$source = $directory . '/source with spaces.wav';
$target = $directory . '/rendered.wav';
$checks = 0;
function verifyAudio(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
try {
    // Two seconds of narrowband 440 Hz PCM: stretching must not mute it or shift pitch.
    $data = '';
    for ($i = 0; $i < 16000; $i++) $data .= pack('v', ((int)round(sin(2 * M_PI * 440 * $i / 8000) * 10000)) & 0xffff);
    $chunks = 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
    file_put_contents($source, 'RIFF' . pack('V', strlen($chunks) + 4) . 'WAVE' . $chunks);
    foreach (['browser', 'phone'] as $profile) {
        foreach ([.5, .75, 1.0, 1.25, 1.5, 1.75, 2.0] as $rate) {
            Audio::convert($source, $target, $rate, $profile);
            $info = Audio::wavInfo($target);
            verifyAudio($info !== null, "$profile $rate: valid PCM WAV");
            verifyAudio($info['sample_rate'] === ($profile === 'phone' ? 8000 : 48000), "$profile $rate: expected output sample rate");
            verifyAudio(abs($info['duration'] - 2 / $rate) < .16, "$profile $rate: correct speed/duration");
            $fp = fopen($target, 'rb'); fseek($fp, 12); $pcm = '';
            while (!feof($fp)) {
                $chunk = fread($fp, 8); if (strlen($chunk) !== 8) break;
                $length = unpack('V', substr($chunk, 4, 4))[1];
                if (substr($chunk, 0, 4) === 'data') { $pcm = fread($fp, $length); break; }
                fseek($fp, $length + ($length % 2), SEEK_CUR);
            }
            fclose($fp);
            $samples = array_values(unpack('v*', $pcm));
            $energy = 0; $crossings = 0; $previous = 0;
            foreach ($samples as $unsigned) {
                $sample = $unsigned >= 32768 ? $unsigned - 65536 : $unsigned;
                $energy += $sample * $sample;
                if ($previous < 0 && $sample >= 0) $crossings++;
                $previous = $sample;
            }
            verifyAudio(sqrt($energy / count($samples)) > 1000, "$profile $rate: output is not silent");
            verifyAudio(abs($crossings / $info['duration'] - 440) < 15, "$profile $rate: pitch remains near 440 Hz");
        }
    }
    // Cache hits do not require the upstream URL, but broken cache entries must be discarded.
    $mailbox = ['domain' => 'test', 'user' => '1'];
    $message = ['filename' => 'test.wav', 'audio_url' => 'https://invalid.example/audio.wav'];
    $cacheDir = config()['storage'] . '/audio';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0700, true);
    $id = Audio::cacheId($mailbox, $message['filename'], 1, 'browser');
    $cache = $cacheDir . '/' . $id . '.wav';
    try {
        Audio::convert($source, $target, 1, 'browser');
        copy($target, $cache);
        verifyAudio(Audio::prepare($mailbox, $message, 1, 'browser') === $id, 'valid cache hit does not re-download');
        file_put_contents($cache, 'broken cache');
        $rejected = false;
        try { Audio::prepare($mailbox, $message, 1, 'browser'); }
        catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(), 'allowed HTTPS'); }
        verifyAudio($rejected && !is_file($cache), 'invalid cache is removed, not returned as playable audio');
    } finally {
        if (is_file($cache)) unlink($cache);
        if (is_file($cacheDir . '/' . $id . '.lock')) unlink($cacheDir . '/' . $id . '.lock');
    }
    echo "PASS: $checks native FFmpeg checks on " . PHP_OS_FAMILY . ".\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
