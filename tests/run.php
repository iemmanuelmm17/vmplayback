<?php
declare(strict_types=1);
// Offline tests: no live PBX calls, credentials, or changes to real messages.
putenv('APP_URL=https://voicemail.example.test');
putenv('APP_KEY=' . str_repeat('k', 48));
putenv('ALLOWED_MAILBOXES=6001@1180.DynaCloud');
putenv('MEDIA_ALLOWED_HOSTS=nms1.nyc.birns.net');
putenv('NS_DELETE_MOVES_TO_TRASH=' . (in_array('--trash-enabled', $argv, true) ? 'true' : 'false'));
require dirname(__DIR__) . '/src/bootstrap.php';
$tests = 0;
function check(bool $condition, string $message): void {
    global $tests; $tests++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}
$mb = mailbox('1180.DynaCloud', '6001');
check($mb['user'] === '6001', 'mailbox identity');
rejects(fn() => mailbox('../bad', '6001'), 'domain traversal');
rejects(fn() => mailbox('1180.DynaCloud', '6002'), 'mailbox allowlist');
rejects(fn() => filename('../test.wav'), 'filename traversal');
rejects(fn() => folder('thrash'), 'correct trash spelling');
check(speed(.1) === .5 && speed(9) === 2.0 && speed(1.24) === 1.25, 'bounded speed increments');
$record = ['filename' => 'vm-20260522212317016335-5b95c75296e501bf9118b1ea964c1033.wav', 'file-duration-seconds' => 10,
    'created-datetime' => '2026-05-22T21:23:43+00:00', 'file-access-url' => 'https://nms1.nyc.birns.net/audio.wav?auth=test',
    'voicemail-from-name' => 'Direct <NEW> & NY', 'voicemail-from-caller-id-number' => 13322198737];
$calls = [];
$transport = function ($method, $path, $body) use (&$calls, $record) {
    $calls[] = [$method, $path, $body]; return $method === 'GET' ? [$record, array_merge($record, ['filename' => 'vm-second.wav', 'created-datetime' => '2026-05-21T00:00:00Z'])] : [];
};
$api = new NetSapiens($transport);
$messages = $api->messages($mb, 'new');
check(count($messages) === 2 && $messages[0]['number'] === '13322198737', 'normalize sample API response');
check($messages[0]['duration'] === 10.0 && $messages[0]['name'] === $record['voicemail-from-name'], 'metadata retained');
$single = (new NetSapiens(fn() => $record))->messages($mb, 'new');
check(count($single) === 1, 'single object response');
$legacy = (new NetSapiens(fn() => [['filename' => 'old.wav', 'length' => '19', 'remotepath' => 'https://nms1.nyc.birns.net/a.wav', 'FromName' => 'Old caller', 'NmsAni' => '1005']]))->messages($mb, 'save');
check($legacy[0]['duration'] === 19.0 && $legacy[0]['number'] === '1005', 'legacy response aliases');
rejects(fn() => (new NetSapiens(fn() => ['error' => 'bad']))->messages($mb, 'new'), 'reject invalid list');
$api->save($mb, 'new', $record['filename']);
check(end($calls)[0] === 'PATCH' && str_ends_with(end($calls)[1], '/save'), 'save request');
$count = count($calls); $api->save($mb, 'save', $record['filename']); check(count($calls) === $count, 'save in save is a no-op');
$api->forward($mb, 'new', $record['filename'], '6002');
check(end($calls)[2] === ['voicemail-forward-new-destination' => '6002'], 'forward destination JSON');
check(str_ends_with(end($calls)[1], '/forward'), 'forward endpoint');
rejects(fn() => $api->forward($mb, 'trash', $record['filename'], '6002'), 'cannot forward trash');
rejects(fn() => $api->forward($mb, 'new', $record['filename'], '6002/evil'), 'validate forward extension');
$count = count($calls);
rejects(fn() => $api->trash($mb, 'trash', $record['filename']), 'permanent delete is always prohibited');
check(count($calls) === $count, 'no HTTP request for permanent delete');
if (config()['trash_confirmed']) {
    $api->trash($mb, 'new', $record['filename']); check(end($calls)[0] === 'DELETE' && str_contains(end($calls)[1], '/new/'), 'soft delete new only');
    $api->trash($mb, 'save', $record['filename']); check(str_contains(end($calls)[1], '/save/'), 'soft delete saved');
} else {
    rejects(fn() => $api->trash($mb, 'new', $record['filename']), 'soft delete must be confirmed by administrator');
    check(count($calls) === $count, 'disabled trash sends no request');
}
Audio::validateRemote($record['file-access-url']);
foreach (['http://nms1.nyc.birns.net/a', 'https://evil.example/a', 'https://nms1.nyc.birns.net.evil/a', 'https://user:pass@nms1.nyc.birns.net/a', 'https://nms1.nyc.birns.net:444/a', 'file:///etc/passwd'] as $bad) rejects(fn() => Audio::validateRemote($bad), 'media SSRF guard');
$id = str_repeat('a', 64); parse_str(parse_url(Audio::signedUrl($id), PHP_URL_QUERY), $q);
check(Audio::validSignature($q['id'], (string)$q['expires'], $q['signature']), 'valid signed media');
check(!Audio::validSignature(str_repeat('b', 64), (string)$q['expires'], $q['signature']), 'tamper rejected');
check(!Audio::validSignature($q['id'], (string)(time() - 1), $q['signature']), 'expired media rejected');
check(Audio::range('', 100) === [0, 99], 'full audio range');
check(Audio::range('bytes=10-19', 100) === [10, 19], 'explicit range');
check(Audio::range('bytes=95-', 100) === [95, 99], 'open range');
check(Audio::range('bytes=-5', 100) === [95, 99], 'suffix range');
foreach (['bytes=100-', 'bytes=20-10', 'bytes=0-1,4-5', 'bytes=-0', 'bytes=-', 'nonsense'] as $range) check(Audio::range($range, 100) === null, 'invalid range rejected');
$audioCalls = [];
$phone = new Phone($api, function ($mailbox, $message, $rate) use (&$audioCalls) { $audioCalls[] = [$mailbox, $message, $rate]; return 'https://voicemail.example.test/media.php?id=a&signature=b'; });
$s = Phone::initial($mb, 'new');
function step(Phone $phone, array &$s, string $digits): string {
    $xml = $phone->respond($s, $digits, 'https://voicemail.example.test/webresponder.php?sid=test&step=2');
    $s['step']++;
    $doc = new DOMDocument(); check($doc->loadXML($xml), 'well-formed Web Responder XML');
    return $xml;
}
$xml = step($phone, $s, '');
check(str_contains($xml, '<Gather input="dtmf"') && str_contains($xml, '<Play>') && str_contains($xml, '&amp;signature='), 'initial gather with private WAV playback');
step($phone, $s, '7'); check($s['rate'] === .75 && end($audioCalls)[2] === .75 && $s['index'] === 0, '7 slower replays current');
step($phone, $s, '9'); check($s['rate'] === 1.0, '9 faster');
step($phone, $s, '8'); check($s['index'] === 0, '8 replay does not skip');
$before = count($calls); $xml = step($phone, $s, '4');
check($s['stage'] === 'destination' && str_contains($xml, 'numDigits="12"') && count($calls) === $before, 'forward asks for extension without mutation');
step($phone, $s, '6002'); check($s['stage'] === 'confirm' && count($calls) === $before, 'forward confirmation');
step($phone, $s, '2'); check($s['stage'] === 'menu' && count($calls) === $before, 'cancel forward');
step($phone, $s, '4'); step($phone, $s, '6002'); step($phone, $s, '1');
check(end($calls)[0] === 'PATCH' && str_ends_with(end($calls)[1], '/forward') && $s['index'] === 1, 'confirm forwards and advances');
step($phone, $s, '*'); step($phone, $s, '2'); check($s['folder'] === 'save' && $s['index'] === 0, 'folder switch resets cursor');
step($phone, $s, '*'); step($phone, $s, '3'); $before = count($calls);
rejects(fn() => $phone->respond($s, '2', 'https://example.test'), 'DTMF trash cannot permanently delete');
check(count($calls) === $before, 'DTMF trash no DELETE');
step($phone, $s, '1'); check($s['index'] === 1 && str_ends_with(end($calls)[1], '/save'), 'restore trash via save');
step($phone, $s, '5'); check($s['stage'] === 'folders', 'next at end offers folders');
$xml = step($phone, $s, '0'); check(str_contains($xml, '<Hangup/>'), 'hangup');
$s = Phone::initial($mb, 'new'); step($phone, $s, ''); step($phone, $s, ''); step($phone, $s, '');
$xml = step($phone, $s, ''); check(str_contains($xml, '<Hangup/>'), 'hangup after three timeouts');
$unavailable = new Phone(new NetSapiens(function () { throw new RuntimeException('API down'); }));
$s = Phone::initial($mb, 'new');
check(str_contains($unavailable->respond($s, '0', 'https://example.test'), '<Hangup/>'), 'hangup works even if the upstream API is unavailable');
$xml = Phone::xml('<Say>' . escape("Caller <name> & \x01") . '</Say>');
$doc = new DOMDocument(); check($doc->loadXML($xml), 'invalid XML control characters are replaced');
echo "PASS: $tests checks (trash " . (config()['trash_confirmed'] ? 'enabled' : 'disabled') . ").\n";
