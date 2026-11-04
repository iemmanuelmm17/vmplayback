<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
securityHeaders();
$error = ''; $ready = true;
try { requireConfig(); browserSession(); }
catch (Throwable $e) { $ready = false; $error = 'Server setup is required. Configure .env using .env.example; see README.md.'; }
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf();
        if (input('action') === 'logout') {
            $_SESSION = []; session_regenerate_id(true); header('Location: ./'); exit;
        }
        // Per-IP login throttle. Use REMOTE_ADDR, never untrusted forwarding headers.
        $path = config()['storage'] . '/login-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
        $fp = fopen($path, 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Login is unavailable.');
        chmod($path, 0600);
        $attempts = json_decode(stream_get_contents($fp), true) ?: ['since' => time(), 'count' => 0];
        if ($attempts['since'] < time() - 900) $attempts = ['since' => time(), 'count' => 0];
        if ($attempts['count'] >= 10) {
            http_response_code(429); $error = 'Too many login attempts. Try again in 15 minutes.';
        } elseif (hash_equals(config()['browser_key'], input('access_key'))) {
            session_regenerate_id(true); $_SESSION['auth_until'] = time() + 28800;
            $_SESSION['csrf'] = bin2hex(random_bytes(32)); $attempts['count'] = 0;
        } else { $attempts['count']++; $error = 'The access key is incorrect.'; }
        rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($attempts)); flock($fp, LOCK_UN); fclose($fp);
    } catch (Throwable) { $error = 'Could not sign in. Reload the page and try again.'; }
}
$authorized = $ready && browserAuthorized();
try { $initialUser = input('AccountUser'); $initialDomain = input('AccountDomain'); }
catch (Throwable) { $initialUser = ''; $initialDomain = ''; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>VM Playback · Voicemail, at your pace</title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar"><a class="brand" href="./"><span class="brand-icon" aria-hidden="true">◉</span> vm<span>playback</span></a><span class="top-note">YOUR VOICEMAIL. YOUR PACE.</span>
<?php if ($authorized): ?><form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button class="text-button" name="action" value="logout">Sign out ↗</button></form><?php endif; ?>
</header>
<main>
<div class="page-heading"><div class="eyebrow">A CLEARER WAY TO LISTEN</div><h1>Your inbox, unhurried<span>.</span></h1><p>Listen closely. Find the details. Move on when you’re ready.</p></div>
<?php if ($error): ?><p class="alert" role="alert"><?= escape($error) ?></p><?php endif; ?>
<?php if (!$authorized): ?>
<section class="login-card"><div class="eyebrow">PRIVATE MAILBOX ACCESS</div><h2>A little privacy, first.</h2><p>Enter your shared access key to open the voicemail player. Your API credentials stay on the server.</p>
<?php if ($ready): ?><form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><label for="access-key">Access key</label><input id="access-key" name="access_key" type="password" required autocomplete="current-password" placeholder="Your shared access key"><button class="primary" type="submit">Open player <span>→</span></button></form><?php endif; ?>
<div class="private-note">◈ &nbsp; Protected access · No permanent deletion</div></section>
<?php else: ?>
<div id="app" data-csrf="<?= escape($_SESSION['csrf']) ?>">
<section class="connection-card"><div class="connection-title"><span class="section-icon">⌘</span><div><h2>Connect your mailbox</h2><p>Choose the account you want to listen to.</p></div></div>
<form id="connect-form"><div class="field"><label for="domain">Account domain</label><input id="domain" name="AccountDomain" value="<?= escape($initialDomain) ?>" placeholder="1180.DynaCloud" required maxlength="128" autocomplete="off"></div><div class="field extension"><label for="extension">Extension</label><input id="extension" name="AccountUser" value="<?= escape($initialUser) ?>" placeholder="6001" required inputmode="numeric" pattern="[0-9]{1,12}" maxlength="12" autocomplete="off"></div><button class="primary" id="connect-button">Connect mailbox <span>→</span></button></form>
</section>
<div id="status" class="status" role="status" aria-live="polite">Enter your domain and extension to get started.</div>
<div class="workspace">
<section class="inbox-panel"><div class="panel-heading"><h2>Voicemail</h2><button id="refresh" class="icon-button" aria-label="Refresh mailbox" disabled>↻</button></div>
<div class="tabs" role="group" aria-label="Voicemail folder"><button class="tab active" data-folder="new" aria-pressed="true">New <span id="message-count">—</span></button><button class="tab" data-folder="save" aria-pressed="false">Saved</button><button class="tab" data-folder="trash" aria-pressed="false">Trash</button></div>
<div id="message-list" class="message-list"><div class="empty"><span class="empty-icon">▤</span><h3>A quieter kind of inbox.</h3><p>Connect a mailbox to see your messages here.</p></div></div>
<div class="inbox-footer"><span class="dot"></span> Trash is recoverable. Nothing is permanently deleted.</div>
</section>
<section class="player-panel"><div class="panel-heading"><div class="eyebrow">NOW LISTENING</div><span class="small-tag" id="folder-tag">NO MAILBOX</span></div>
<div class="player-intro"><div class="caller-avatar" id="caller-avatar">♪</div><h2 id="caller-name">Make time for the message.</h2><p id="caller-number">Select a voicemail to start listening.</p><p class="message-date" id="message-date">Every detail, at your pace.</p></div>
<div class="audio-area"><audio id="audio" controls preload="metadata" aria-label="Voicemail audio"></audio><p id="audio-note">Audio stays private. Playback starts when you press play.</p></div>
<div class="speed-controls"><span>PLAYBACK SPEED</span><div><button id="slower" class="speed-button" aria-label="Decrease playback speed">−</button><output id="speed-value">1.00×</output><button id="faster" class="speed-button" aria-label="Increase playback speed">+</button></div><button id="normal-speed" class="text-button">Reset to 1×</button></div>
<div class="player-actions"><button id="save" disabled>↓ <span>Save</span><kbd>1</kbd></button><button id="trash" disabled>⌫ <span>Move to trash</span><kbd>2</kbd></button><button id="forward" disabled>↗ <span>Forward</span><kbd>4</kbd></button></div>
<div class="navigation"><button id="replay" class="text-button" disabled>↶ Replay <kbd>8</kbd></button><button id="next" class="next-button" disabled>Next message → <kbd>5</kbd></button></div>
<div id="transcript-block" class="transcript" hidden><div class="eyebrow">TRANSCRIPT</div><p id="transcript"></p></div>
</section></div>
<section class="phone-help"><span class="phone-icon">⌕</span><div><h3>The same control, on your phone.</h3><p>Dial your configured Web Responder extension. <kbd>7</kbd> slows down, <kbd>9</kbd> speeds up, and <kbd>8</kbd> replays. Speed changes restart the message.</p></div><span class="small-tag">PHONE READY</span></section>
<dialog id="forward-dialog"><form id="forward-form"><div class="eyebrow">PASS IT ALONG</div><h2>Forward voicemail</h2><p>Send the selected voicemail to another extension in this domain. The API may move the original message.</p><label for="destination">Destination extension</label><input id="destination" name="destination" inputmode="numeric" pattern="[0-9]{1,12}" maxlength="12" required autocomplete="off"><p id="forward-error" role="alert"></p><div class="dialog-actions"><button id="cancel-forward" type="button">Cancel</button><button class="primary" type="submit">Confirm forward →</button></div></form></dialog>
</div>
<?php endif; ?>
<footer><span>vmplayback</span><p>Less rushing. More listening.</p><span class="footer-note">POWERED BY NETSAPIENS</span></footer>
</main></body></html>
