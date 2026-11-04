<?php
declare(strict_types=1);
// Keep PHP warning HTML out of the XML protocol; use private diagnostics instead.
ini_set('display_errors', '0');
require dirname(__DIR__) . '/src/bootstrap.php';
securityHeaders(); header('Content-Type: application/xml; charset=UTF-8');
try {
    requireConfig();
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { http_response_code(405); exit; }
    $sid = input('sid');
    if ($sid === '') {
        if (!hash_equals(config()['phone_key'], input('key'))) { http_response_code(403); echo Phone::xml('<Say>Access denied.</Say><Hangup/>'); exit; }
        $mailbox = mailbox(input('AccountDomain'), input('AccountUser'));
        $sid = bin2hex(random_bytes(32));
        $state = Phone::initial($mailbox, input('folder', 'new'));
        $file = config()['storage'] . '/calls/' . $sid . '.json';
        $fp = fopen($file, 'x+');
        if (!$fp) throw new RuntimeException('Could not start call session.');
        chmod($file, 0600); flock($fp, LOCK_EX);
        $step = 0;
    } else {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sid) || !ctype_digit(input('step'))) throw new InvalidArgumentException('Invalid call session.');
        $file = config()['storage'] . '/calls/' . $sid . '.json';
        if (!is_file($file)) throw new InvalidArgumentException('Call session has expired.');
        $fp = fopen($file, 'r+');
        if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Call session unavailable.');
        $state = json_decode(stream_get_contents($fp), true, 512, JSON_THROW_ON_ERROR);
        $step = (int)input('step');
        if ($state['expires'] < time()) throw new InvalidArgumentException('Call session has expired.');
        if (isset($state['responses'][$step])) { echo $state['responses'][$step]; exit; }
        if ($step !== $state['step'] || $step > 500) throw new InvalidArgumentException('Invalid callback sequence.');
        // Identity is frozen in server-side state. Ignore AccountUser/Domain on callbacks.
    }
    $callback = url('webresponder.php', ['sid' => $sid, 'step' => $step + 1]);
    try {
        $xml = (new Phone(new NetSapiens()))->respond($state, input('Digits'), $callback);
    } catch (Throwable $e) {
        $xml = Phone::failure($state, $e, $callback);
        error_log('Voicemail call step failed: ' . json_encode($state['last_error'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        // Advance the callback even on error so a successful mutation isn't repeated
        // if preparing the following message's media failed.
    }
    $state['responses'][$step] = $xml; $state['step'] = $step + 1;
    // Bound replay storage for long calls; recent duplicated callbacks remain idempotent.
    if (count($state['responses']) > 12) unset($state['responses'][array_key_first($state['responses'])]);
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    rewind($fp);
    if (!ftruncate($fp, 0) || fwrite($fp, $encoded) !== strlen($encoded) || !fflush($fp)) {
        throw new RuntimeException('Call state could not be saved.');
    }
    flock($fp, LOCK_UN); fclose($fp);
    echo $xml;
} catch (Throwable $e) {
    error_log('Voicemail responder rejected request: ' . json_encode(Phone::diagnostic($e, 'entry'), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo Phone::xml('<Say>Voicemail is unavailable or the session has expired. Please call again.</Say><Hangup/>');
}
