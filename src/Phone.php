<?php
declare(strict_types=1);

final class Phone {
    public function __construct(private NetSapiens $api, private mixed $audio = null) {
        $this->audio ??= static fn($mailbox, $message, $rate) => Audio::signedUrl(Audio::prepare($mailbox, $message, $rate));
    }
    public static function initial(array $mailbox, string $folder): array {
        return ['mailbox' => $mailbox, 'folder' => folder($folder), 'messages' => null, 'index' => 0, 'rate' => 1.0,
            'stage' => 'menu', 'destination' => '', 'timeouts' => 0, 'expires' => time() + 7200, 'step' => 0, 'responses' => []];
    }
    public function respond(array &$s, string $digits, string $callback): string {
        if ($s['stage'] === 'ended') return self::xml('<Hangup/>');
        if ($digits === '0' && $s['stage'] !== 'destination') { $s['stage'] = 'ended'; return self::xml('<Say>Goodbye.</Say><Hangup/>'); }
        if ($s['messages'] === null) $s['messages'] = $this->api->messages($s['mailbox'], $s['folder']);
        $notice = ''; $play = $s['step'] === 0;
        if ($digits === '' && $s['step'] > 0) {
            if (++$s['timeouts'] >= 3) { $s['stage'] = 'ended'; return self::xml('<Say>No input received. Goodbye.</Say><Hangup/>'); }
        } else { $s['timeouts'] = 0; }
        if ($s['stage'] === 'folders') {
            if (isset(['1' => 'new', '2' => 'save', '3' => 'trash'][$digits])) {
                $s['folder'] = ['1' => 'new', '2' => 'save', '3' => 'trash'][$digits];
                $s['messages'] = $this->api->messages($s['mailbox'], $s['folder']); $s['index'] = 0; $s['stage'] = 'menu'; $play = true;
            } else return $this->folderMenu($callback);
        } elseif ($s['stage'] === 'destination') {
            // Asterisk cancels. Collection ends at max digits or the interdigit timeout.
            if ($digits === '*') { $s['stage'] = 'menu'; $notice = 'Forward cancelled.'; }
            elseif (preg_match('/\A[0-9]{1,12}\z/', $digits)) {
                $s['destination'] = $digits; $s['stage'] = 'confirm';
                return self::xml($this->gather($callback, '<Say>Forward to extension ' . escape(implode(' ', str_split($digits))) . '. Press 1 to confirm, or 2 to cancel.</Say>'));
            } else return $this->destinationMenu($callback);
        } elseif ($s['stage'] === 'confirm') {
            if ($digits === '1') {
                $m = $this->current($s);
                if ($m) { $this->api->forward($s['mailbox'], $s['folder'], $m['filename'], $s['destination']); $s['index']++; $notice = 'Message forwarded.'; $play = true; }
                $s['stage'] = 'menu';
            } elseif ($digits === '2' || $digits === '*') { $s['stage'] = 'menu'; $notice = 'Forward cancelled.'; }
            else return self::xml($this->gather($callback, '<Say>Press 1 to confirm forwarding, or 2 to cancel.</Say>'));
        } else {
            if ($digits === '*') { $s['stage'] = 'folders'; return $this->folderMenu($callback); }
            $m = $this->current($s);
            if ($m) {
                switch ($digits) {
                    case '1': $this->api->save($s['mailbox'], $s['folder'], $m['filename']); $s['index']++; $notice = 'Message saved.'; $play = true; break;
                    case '2': $this->api->trash($s['mailbox'], $s['folder'], $m['filename']); $s['index']++; $notice = 'Message moved to trash.'; $play = true; break;
                    case '4':
                        if ($s['folder'] === 'trash') { $notice = 'Save this message before forwarding.'; break; }
                        $s['stage'] = 'destination'; return $this->destinationMenu($callback);
                    case '5': $s['index']++; $play = true; break;
                    case '7': $s['rate'] = speed($s['rate'] - 0.25); $notice = 'Playback speed ' . $s['rate'] . ' times.'; $play = true; break;
                    case '8': $play = true; break;
                    case '9': $s['rate'] = speed($s['rate'] + 0.25); $notice = 'Playback speed ' . $s['rate'] . ' times.'; $play = true; break;
                    case '': break;
                    default: $notice = 'Invalid selection.';
                }
            }
        }
        $m = $this->current($s);
        if (!$m) {
            $s['stage'] = 'folders';
            return self::xml(($notice ? '<Say>' . escape($notice) . '</Say>' : '') . $this->gather($callback, '<Say>No more messages in this folder. Press 1 for new messages, 2 for saved messages, 3 for trash, or 0 to end.</Say>'));
        }
        $content = '';
        if ($play) {
            $media = ($this->audio)($s['mailbox'], $m, $s['rate']);
            $notice .= ' Message ' . ($s['index'] + 1) . ' of ' . count($s['messages']) . '. From ' . ($m['number'] ?: $m['name']) . '.';
            $content .= '<Play>' . escape($media) . '</Play>';
        }
        $content .= '<Say>Press 1 to save, 2 to move to trash, 4 to forward, 5 for next, 7 for slower, 8 to replay, 9 for faster, star for folders, or 0 to end.</Say>';
        return self::xml(($notice ? '<Say>' . escape($notice) . '</Say>' : '') . $this->gather($callback, $content));
    }
    private function current(array $s): ?array { return $s['messages'][$s['index']] ?? null; }
    private function gather(string $callback, string $content, int $digits = 1): string {
        return '<Gather input="dtmf" numDigits="' . $digits . '" timeout="6" digitEndTimeout="3" action="' . escape($callback) . '">' . $content . '</Gather>';
    }
    private function folderMenu(string $callback): string { return self::xml($this->gather($callback, '<Say>Press 1 for new messages, 2 for saved messages, 3 for trash, or 0 to end.</Say>')); }
    private function destinationMenu(string $callback): string { return self::xml($this->gather($callback, '<Say>Enter the destination extension, then wait three seconds. Press star to cancel.</Say>', 12)); }
    public static function xml(string $body): string { return '<?xml version="1.0" encoding="UTF-8"?><Response>' . $body . '</Response>'; }
}
