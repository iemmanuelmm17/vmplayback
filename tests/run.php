<?php
declare(strict_types=1);
// Offline tests: no live PBX calls, credentials, or changes to real messages.
putenv('APP_URL=https://voicemail.example.test');
putenv('APP_KEY=' . str_repeat('k', 48));
putenv('ALLOWED_MAILBOXES=6001@1180.DynaCloud');
putenv('MEDIA_ALLOWED_HOSTS=nms1.nyc.birns.net');
putenv('PHONE_AUDIO_CODEC=pcm_mulaw');
putenv('CALLER_ID_DIGIT_STYLE=hyphens');
putenv('CALLER_ID_STRIP_US_COUNTRY_CODE=false');
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
check(Audio::processDescriptors('Windows') === [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], 'Windows process I/O uses NUL, not /dev/null');
check(Audio::processDescriptors('Linux') === [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], 'Unix process I/O uses /dev/null');
check(Audio::processDescriptors() === Audio::processDescriptors(PHP_OS_FAMILY), 'process I/O follows the host OS');
check(Phone::spokenNumber('13322198737') === 'one, three, three, two, two, one, nine, eight, seven, three, seven', 'caller ID is explicit digit words, not a large integer');
check(Phone::spokenNumber('+1 (332) 219-8737') === Phone::spokenNumber('13322198737'), 'formatted caller ID is normalized for digit speech');
check(Phone::spokenNumber('006001') === 'zero, zero, six, zero, zero, one', 'leading zeroes are spoken');
check(Phone::spokenNumber('Anonymous') === 'Anonymous' && Phone::spokenNumber('Agent 7') === 'Agent 7', 'non-numeric caller labels are preserved');
check(Phone::spokenNumber('') === 'unknown caller', 'empty caller has a safe fallback');
check(Phone::callerNumber('12622409605') === '1-2-6-2-2-4-0-9-6-0-5', 'caller uses hyphens without silently discarding country code');
check(Phone::callerNumber('12622409605', 'hyphens', true) === '2-6-2-2-4-0-9-6-0-5', 'optional US country-code removal matches requested example');
check(Phone::callerNumber('+1 (262) 240-9605', 'commas', true) === '2, 6, 2, 2, 4, 0, 9, 6, 0, 5', 'comma-separated caller ID is configurable');
check(Phone::callerNumber('12622409605', 'spaces', true) === '2 6 2 2 4 0 9 6 0 5', 'space-separated caller ID is configurable');
check(Phone::callerNumber('12622409605', 'words', true) === 'two, six, two, two, four, zero, nine, six, zero, five', 'explicit digit words remain available');
check(Phone::callerNumber('006001', 'hyphens', true) === '0-0-6-0-0-1', 'country-code preference preserves leading zeroes and extensions');
check(Phone::callerNumber('442079460123', 'hyphens', true) === '4-4-2-0-7-9-4-6-0-1-2-3', 'other international prefixes are not removed');
check(Phone::callerNumber('Anonymous', 'commas', true) === 'Anonymous', 'non-numeric caller label is unchanged');
check(Phone::spokenNumber('6002') === 'six, zero, zero, two', 'forwarding speech ignores caller-ID-only settings');
rejects(fn() => Phone::callerNumber('12622409605', 'unsupported'), 'invalid caller speech style rejected');
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
$browserCommand = Audio::command('source with spaces.wav', 'target.wav', 1.0, 'browser');
$phoneCommand = Audio::command('source.wav', 'target.wav', 1.0, 'phone');
$tempoCommand = Audio::command('source.wav', 'target.wav', .75, 'browser');
check(!in_array('-af', $browserCommand, true) && !in_array('-af', $phoneCommand, true), '1x bypasses time stretching');
check($browserCommand[array_search('-ar', $browserCommand, true) + 1] === '48000', 'browser audio is not reduced to telephone bandwidth');
check($phoneCommand[array_search('-ar', $phoneCommand, true) + 1] === '8000' && in_array('-ac', $phoneCommand, true), 'phone keeps compatible 8k mono format');
check(in_array('aresample=48000,atempo=0.75', $tempoCommand, true), 'tempo processing resamples before stretching');
check(in_array('source with spaces.wav', $browserCommand, true), 'FFmpeg arguments preserve spaced filenames');
check(Audio::cacheId($mb, $record['filename'], 1, 'browser') !== Audio::cacheId($mb, $record['filename'], 1, 'phone'), 'separate browser/phone caches');
check(Audio::cacheId($mb, $record['filename'], 1, 'browser') !== Audio::cacheId($mb, $record['filename'], .75, 'browser'), 'separate tempo caches');
check(Audio::cacheId($mb, $record['filename'], 1, 'phone') !== hash('sha256', json_encode([$mb, $record['filename'], 1.0])), 'old low-quality caches are invalidated');
check(Audio::requestedRate('1.00') === 1.0 && Audio::requestedRate('0.75') === .75, 'valid API playback rates');
foreach (['NAN', 'INF', '1e309', '0', '4', '1.1', ''] as $badRate) rejects(fn() => Audio::requestedRate($badRate), 'invalid playback rate rejected');
rejects(fn() => Audio::command('a', 'b', 1, 'invalid'), 'unknown audio profile rejected');
foreach (['pcm_s16le', 'pcm_mulaw', 'pcm_alaw'] as $codec) {
    $command = Audio::command('source.wav', 'target.wav', 1, 'phone', $codec);
    check($command[array_search('-c:a', $command, true) + 1] === $codec, 'phone codec is explicitly selected');
    check($command[count($command) - 3] === '-f' && $command[count($command) - 2] === 'wav', 'all phone codecs remain in a WAV container');
}
check(Audio::codec('phone') === 'pcm_mulaw', 'phone uses the requested mu-law setting');
check($phoneCommand[array_search('-c:a', $phoneCommand, true) + 1] === 'pcm_mulaw'
    && $phoneCommand[array_search('-ar', $phoneCommand, true) + 1] === '8000'
    && $phoneCommand[array_search('-ac', $phoneCommand, true) + 1] === '1', 'phone command explicitly renders 8000 Hz mono mu-law WAV');
rejects(fn() => Audio::codec('phone', 'mp3'), 'unsupported phone codec is rejected');
check(Audio::codec('browser', 'pcm_mulaw') === 'pcm_s16le', 'G.711 setting cannot change browser audio');
check(Audio::cacheId($mb, $record['filename'], 1, 'phone', 'pcm_s16le') !== Audio::cacheId($mb, $record['filename'], 1, 'phone', 'pcm_mulaw'), 'codec changes invalidate phone cache automatically');
check(Audio::cacheId($mb, $record['filename'], 1, 'browser', 'pcm_s16le') === Audio::cacheId($mb, $record['filename'], 1, 'browser', 'pcm_mulaw'), 'browser cache is independent of phone codec');
$tempWav = tempnam(sys_get_temp_dir(), 'vmplayback-wav-');
try {
    $fmt = 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16);
    $data = str_repeat("\0", 1600);
    $chunks = $fmt . 'JUNK' . pack('V', 1) . 'x' . "\0" . 'data' . pack('V', strlen($data)) . $data;
    file_put_contents($tempWav, 'RIFF' . pack('V', strlen($chunks) + 4) . 'WAVE' . $chunks);
    $info = Audio::wavInfo($tempWav);
    check($info !== null && $info['sample_rate'] === 8000 && abs($info['duration'] - .1) < .0001, 'WAV parser skips padded metadata chunks');
    file_put_contents($tempWav, 'RIFF' . pack('V', strlen($chunks) + 4) . 'WAVE' . substr($chunks, 0, -2));
    check(Audio::wavInfo($tempWav) === null, 'truncated WAV rejected');
    $empty = $fmt . 'data' . pack('V', 0);
    file_put_contents($tempWav, 'RIFF' . pack('V', strlen($empty) + 4) . 'WAVE' . $empty);
    check(Audio::wavInfo($tempWav) === null, 'header-only WAV rejected');
    foreach ([6 => 'pcm_alaw', 7 => 'pcm_mulaw'] as $tag => $codec) {
        $g711 = 'fmt ' . pack('VvvVVvv', 16, $tag, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 800) . str_repeat("\xff", 800);
        file_put_contents($tempWav, 'RIFF' . pack('V', strlen($g711) + 4) . 'WAVE' . $g711);
        $info = Audio::wavInfo($tempWav);
        check($info !== null && $info['codec'] === $codec && $info['bits'] === 8 && abs($info['duration'] - .1) < .0001, 'G.711 WAV header and duration are recognized');
        $g711 = 'fmt ' . pack('VvvVVvv', 16, $tag, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 800) . str_repeat("\0", 800);
        file_put_contents($tempWav, 'RIFF' . pack('V', strlen($g711) + 4) . 'WAVE' . $g711);
        check(Audio::wavInfo($tempWav) === null, 'G.711 header mislabeled as 16-bit PCM is rejected');
    }
    file_put_contents($tempWav, '<html>upstream error</html>');
    check(Audio::wavInfo($tempWav) === null, 'non-audio cache rejected');
} finally { unlink($tempWav); }
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
check(str_contains($xml, 'From 1-3-3-2-2-1-9-8-7-3-7.') && !str_contains($xml, 'From 13322198737'), 'emitted Say uses configured separators for caller ID');
check(str_contains($xml, '<!-- vmplayback phone-v3 -->'), 'fresh responder XML identifies the updated code version');
step($phone, $s, '7'); check($s['rate'] === .75 && end($audioCalls)[2] === .75 && $s['index'] === 0, '7 slower replays current');
step($phone, $s, '9'); check($s['rate'] === 1.0, '9 faster');
step($phone, $s, '8'); check($s['index'] === 0, '8 replay does not skip');
$before = count($calls); $xml = step($phone, $s, '4');
check($s['stage'] === 'destination' && str_contains($xml, 'numDigits="12"') && count($calls) === $before, 'forward asks for extension without mutation');
$xml = step($phone, $s, '6002'); check($s['stage'] === 'confirm' && count($calls) === $before, 'forward confirmation');
check(str_contains($xml, 'extension six, zero, zero, two.'), 'forward confirmation also speaks extension digit by digit');
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
// Responder diagnostics must identify the failing operation without disclosing
// capabilities, caller metadata, raw upstream URLs or configured credentials.
$s = Phone::initial($mb, 'new');
try { $unavailable->respond($s, '', 'https://example.test/callback'); }
catch (RuntimeException $e) { $failure = Phone::failure($s, $e, 'https://example.test/callback?a=1&b=2'); }
check($s['last_error']['operation'] === 'list' && $s['last_error']['message'] === 'API down', 'mailbox-list failure retains useful private reason');
check(str_contains($failure, 'mailbox could not be loaded') && !str_contains($failure, 'API down'), 'list failure prompt is specific without exposing exception details');
check(str_contains($failure, 'Diagnostic reference: ') && str_contains($failure, '&amp;b='), 'error XML has a safe reference and escaped callback');
check((new DOMDocument())->loadXML($failure), 'error response is valid XML');

$renderFails = true;
$audioFailure = new Phone($api, function () use (&$renderFails) {
    if ($renderFails) throw new RuntimeException('FFmpeg test failure');
    return 'https://example.test/audio.wav';
});
$s = Phone::initial($mb, 'new');
try { $audioFailure->respond($s, '', 'https://example.test/callback'); }
catch (RuntimeException $e) { $failure = Phone::failure($s, $e, 'https://example.test/callback'); }
check($s['last_error']['operation'] === 'audio' && $s['index'] === 0, 'initial audio failure is not misclassified as API list failure');
check(str_contains($failure, 'audio for this message') && str_contains($failure, '5 to skip'), 'unplayable-message recovery offers skip');
$s['step']++;
$renderFails = false;
check(str_contains($audioFailure->respond($s, '5', 'https://example.test/callback'), '<Play>') && $s['index'] === 1, 'caller can skip a broken first message');

$s = Phone::initial($mb, 'new'); $s['messages'] = $messages; $s['step'] = 1;
$renderFails = true; $before = count($calls);
try { $audioFailure->respond($s, '1', 'https://example.test/callback'); }
catch (RuntimeException $e) { Phone::failure($s, $e, 'https://example.test/callback'); }
check($s['index'] === 1 && $s['last_error']['operation'] === 'audio' && count($calls) === $before + 1, 'successful save stays committed if next audio preparation fails');
$s['step']++; $renderFails = false;
$audioFailure->respond($s, '8', 'https://example.test/callback');
check(count($calls) === $before + 1 && $s['index'] === 1, 'retrying audio does not repeat the successful mutation');

$folderFailure = new Phone(new NetSapiens(function () { throw new RuntimeException('Saved folder read failed'); }));
$s = Phone::initial($mb, 'new'); $s['messages'] = $messages; $s['stage'] = 'folders'; $s['step'] = 1;
try { $folderFailure->respond($s, '2', 'https://example.test/callback'); }
catch (RuntimeException $e) { Phone::failure($s, $e, 'https://example.test/callback'); }
check($s['folder'] === 'new' && $s['messages'] === $messages && $s['last_error']['operation'] === 'list', 'failed folder switch cannot relabel messages as belonging to another folder');

$diagnostic = Phone::diagnostic(new RuntimeException('Failure ' . config()['key'] . ' https://media.example/play?auth=unknown-secret Bearer unknown-bearer key=unknown-key sid=unknown-session' . "\n" . 'next line'), 'audio');
check(!str_contains($diagnostic['message'], config()['key']), 'configured application secret is redacted');
foreach (['unknown-secret', 'unknown-bearer', 'unknown-key', 'unknown-session', 'https://', "\n"] as $secret) check(!str_contains($diagnostic['message'], $secret), 'unknown URL capabilities and log-control characters are redacted');
check((bool)preg_match('/\A[a-f0-9]{12}\z/', $diagnostic['reference']), 'diagnostic reference has no session ID');
check(Phone::diagnostic(new RuntimeException('test'), 'untrusted-operation')['operation'] === 'control', 'diagnostic operation is allowlisted');
echo "PASS: $tests checks (trash " . (config()['trash_confirmed'] ? 'enabled' : 'disabled') . ").\n";
