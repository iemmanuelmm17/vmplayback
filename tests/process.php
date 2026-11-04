<?php
declare(strict_types=1);
// Native integration test: exercises the same OS-specific descriptors and launch
// options as FFmpeg, without needing a PBX, API token, or FFmpeg installation.
require dirname(__DIR__) . '/src/bootstrap.php';
if (!function_exists('proc_open')) throw new RuntimeException('This integration test requires native PHP with proc_open enabled.');

$directory = sys_get_temp_dir() . '/vmplayback process ' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create process test directory.');
$script = $directory . '/child with spaces.php';
file_put_contents($script, '<?php if (stream_get_contents(STDIN) !== "") exit(9); echo "discarded stdout"; fwrite(STDERR, "discarded stderr"); exit(7);');
try {
    // Includes an argument with spaces and no shell quoting, as used for audio files.
    $process = proc_open([PHP_BINARY, $script], Audio::processDescriptors(), $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Could not start child PHP process.');
    $exit = proc_close($process);
    if ($exit !== 7) throw new RuntimeException('Unexpected child exit code: ' . $exit);
    if ($pipes !== []) throw new RuntimeException('Null-device descriptors must not create pipes.');
    echo 'PASS: process launch, null-device I/O and spaced paths on ' . PHP_OS_FAMILY . ".\n";
} finally {
    unlink($script); rmdir($directory);
}
