<?php
declare(strict_types=1);
// Keep PHP warnings out of JSON responses; diagnostics belong in the server log.
ini_set('display_errors', '0');
require dirname(__DIR__) . '/src/bootstrap.php';
securityHeaders();
try {
    requireConfig(); browserSession();
    if (!browserAuthorized()) jsonResponse(['error' => 'Sign in to continue.'], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'POST required.'], 405);
    csrf(); session_write_close();
    $mailbox = mailbox(input('AccountDomain'), input('AccountUser'));
    $folder = folder(input('folder', 'new')); $action = input('action'); $api = new NetSapiens();
    if ($action === 'list') {
        $messages = $api->messages($mailbox, $folder);
        foreach ($messages as &$message) unset($message['audio_url']);
        jsonResponse(['messages' => $messages, 'trash_enabled' => config()['trash_confirmed']]);
    }
    $file = filename(input('filename'));
    $message = $api->find($mailbox, $folder, $file);
    switch ($action) {
        case 'audio':
            $rate = Audio::requestedRate(input('rate', '1'));
            jsonResponse(['url' => Audio::signedUrl(Audio::prepare($mailbox, $message, $rate, 'browser')), 'rate' => $rate]);
        case 'save': $api->save($mailbox, $folder, $file); break;
        case 'trash': $api->trash($mailbox, $folder, $file); break;
        case 'forward': $api->forward($mailbox, $folder, $file, input('destination')); break;
        default: throw new InvalidArgumentException('Unknown action.');
    }
    jsonResponse(['ok' => true]);
} catch (InvalidArgumentException $e) { jsonResponse(['error' => $e->getMessage()], 400); }
catch (Throwable $e) {
    error_log('Voicemail browser request failed: ' . get_class($e) . ': ' . $e->getMessage());
    // Messages are application-authored; never expose upstream bodies or signed URLs.
    jsonResponse(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Voicemail is temporarily unavailable.'], 502);
}
