<?php
declare(strict_types=1);

final class NetSapiens {
    /** Optional transport used by the offline tests. Never supplied by HTTP input. */
    public function __construct(private mixed $transport = null) {}
    private function request(string $method, string $path, ?array $body = null): mixed {
        if ($this->transport) return ($this->transport)($method, $path, $body);
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL is required.');
        $ch = curl_init(config()['api_url'] . $path);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . config()['token']];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $data = ''; $tooLarge = false;
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$data, &$tooLarge) {
                if (strlen($data) + strlen($chunk) > 8 * 1024 * 1024) { $tooLarge = true; return 0; }
                $data .= $chunk; return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $errno = curl_errno($ch); curl_close($ch);
        if ($tooLarge) throw new RuntimeException('The voicemail API response exceeds the 8 MiB limit.');
        if ($ok === false) throw new RuntimeException('The voicemail server could not be reached (cURL error ' . $errno . ').');
        if ($status < 200 || $status >= 300) throw new RuntimeException('Voicemail API request failed (HTTP ' . $status . ').');
        if (trim($data) === '') return [];
        try { return json_decode($data, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('The voicemail server returned invalid JSON.'); }
    }
    private function path(array $mailbox, string $folder, ?string $file = null): string {
        mailbox($mailbox['domain'], $mailbox['user']);
        return '/domains/' . rawurlencode($mailbox['domain']) . '/users/' . rawurlencode($mailbox['user']) . '/voicemails/' . folder($folder) . ($file !== null ? '/' . rawurlencode(filename($file)) : '');
    }
    public function messages(array $mailbox, string $folder): array {
        $data = $this->request('GET', $this->path($mailbox, $folder));
        if (!is_array($data)) throw new RuntimeException('Unexpected voicemail list.');
        // Some installations return one object instead of an array for one message.
        if (isset($data['filename'])) $data = [$data];
        if (!array_is_list($data)) throw new RuntimeException('Unexpected voicemail list.');
        $messages = [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['filename'])) throw new RuntimeException('Invalid voicemail record.');
            $messages[] = [
                'filename' => filename((string)$row['filename']),
                'duration' => (float)($row['file-duration-seconds'] ?? $row['length'] ?? 0),
                'date' => (string)($row['recorded-datetime'] ?? $row['created-datetime'] ?? $row['RecordTime'] ?? $row['filedate'] ?? ''),
                'name' => trim((string)($row['voicemail-from-name'] ?? $row['FromName'] ?? 'Unknown caller')),
                'number' => (string)($row['voicemail-from-caller-id-number'] ?? $row['voicemail-from-user'] ?? $row['NmsAni'] ?? $row['FromUser'] ?? ''),
                'audio_url' => (string)($row['file-access-url'] ?? $row['remotepath'] ?? ''),
                'transcript' => (string)($row['transcription'] ?? $row['file-script-text'] ?? ''),
            ];
        }
        usort($messages, static fn($a, $b) => strcmp($b['date'], $a['date']));
        return $messages;
    }
    public function find(array $mailbox, string $folder, string $file): array {
        filename($file);
        foreach ($this->messages($mailbox, $folder) as $message) if ($message['filename'] === $file) return $message;
        throw new InvalidArgumentException('Message is no longer in this folder. Refresh the mailbox.');
    }
    public function save(array $mailbox, string $folder, string $file): void {
        $path = $this->path($mailbox, $folder, $file);
        if ($folder !== 'save') $this->request('PATCH', $path . '/save');
    }
    public function trash(array $mailbox, string $folder, string $file): void {
        $path = $this->path($mailbox, $folder, $file);
        // Never send DELETE for a trashed message: that could be permanent deletion.
        if ($folder === 'trash') throw new InvalidArgumentException('This message is already in trash. Permanent deletion is disabled.');
        if (!config()['trash_confirmed']) throw new RuntimeException('Trash is disabled until the administrator confirms the server uses soft deletion.');
        $this->request('DELETE', $path);
    }
    public function forward(array $mailbox, string $folder, string $file, string $destination): void {
        if (!preg_match('/\A[0-9]{1,12}\z/', $destination)) throw new InvalidArgumentException('Enter a numeric destination extension.');
        if ($folder === 'trash') throw new InvalidArgumentException('Restore this message before forwarding.');
        $this->request('PATCH', $this->path($mailbox, $folder, $file) . '/forward', ['voicemail-forward-new-destination' => $destination]);
    }
}
