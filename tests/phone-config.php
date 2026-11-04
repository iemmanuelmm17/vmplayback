<?php
declare(strict_types=1);
// Separate PHP processes exercise real configuration (config() is request-cached).
$style = $argv[1] ?? 'hyphens';
if (!in_array($style, ['hyphens', 'commas'], true)) throw new RuntimeException('Unsupported test style.');
putenv('APP_URL=https://example.test');
putenv('ALLOWED_MAILBOXES=1001@test');
putenv('PHONE_AUDIO_CODEC=pcm_mulaw');
putenv('CALLER_ID_DIGIT_STYLE=' . $style);
putenv('CALLER_ID_STRIP_US_COUNTRY_CODE=true');
require dirname(__DIR__) . '/src/bootstrap.php';
$api = new NetSapiens(static function ($method) {
    if ($method !== 'GET') throw new RuntimeException('This read-only test must not mutate a mailbox.');
    return [['filename' => 'example.wav', 'voicemail-from-caller-id-number' => '12622409605']];
});
$phone = new Phone($api, static fn() => 'https://example.test/media.wav');
$state = Phone::initial(mailbox('test', '1001'), 'new');
$xml = $phone->respond($state, '', 'https://example.test/callback');
$expected = $style === 'hyphens' ? '2-6-2-2-4-0-9-6-0-5' : '2, 6, 2, 2, 4, 0, 9, 6, 0, 5';
if (!str_contains($xml, 'From ' . $expected . '.') || str_contains($xml, 'From 12622409605')) throw new RuntimeException('Caller announcement does not match configured style/prefix.');
if (!str_contains($xml, '<!-- vmplayback phone-v3 -->')) throw new RuntimeException('Missing deployment version marker.');
if ($state['messages'][0]['number'] !== '12622409605') throw new RuntimeException('Announcement preferences must not modify mailbox metadata.');
$state['step']++;
$phone->respond($state, '4', 'https://example.test/callback');
$state['step']++;
$xml = $phone->respond($state, '1001', 'https://example.test/callback');
if (!str_contains($xml, 'extension one, zero, zero, one.')) throw new RuntimeException('Forwarding speech must stay digit words independently of caller ID configuration.');
$command = Audio::command('source.wav', 'target.wav', 1, 'phone');
foreach (['-ar' => '8000', '-ac' => '1', '-c:a' => 'pcm_mulaw'] as $flag => $expectedValue) {
    $index = array_search($flag, $command, true);
    if ($index === false || $command[$index + 1] !== $expectedValue) throw new RuntimeException('Phone output does not match the configured mu-law format.');
}
echo "PASS: configured $style caller XML, optional prefix, unchanged metadata/forwarding, version marker and mu-law output.\n";
