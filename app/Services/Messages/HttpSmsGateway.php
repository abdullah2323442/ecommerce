<?php

namespace App\Services\Messages;

use App\Services\Integrations\Integrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A text service reached over HTTP, set up by the owner in Admin > Connections: the address, GET or POST, and the names of the phone and message
 * fields; the key and sender name the service asks for are kept encrypted. Most Bangladeshi text services work this way. The address must be https and
 * must not point at this machine or a private network.
 */
class HttpSmsGateway implements SmsGateway
{
    public function __construct(private Integrations $integrations) {}

    public function send(string $phone, string $text): void
    {
        $values = $this->integrations->active('sms:http') ?? throw new RuntimeException('The text service is not switched on.');
        $result = $this->call($values, $phone, $text);

        if (! $result['ok']) {
            throw new RuntimeException($result['note']);
        }
    }

    /**
     * A real try, always (even in test mode), to a number the owner gives.
     *
     * @param  array{settings: array<string, mixed>, credentials: array<string, mixed>}  $values
     * @return array{ok: bool, note: string}
     */
    public function probe(array $values, string $to): array
    {
        if (! preg_match('/^01[3-9]\d{8}$/', $to)) {
            return ['ok' => false, 'note' => 'Write a Bangladesh mobile number, like 01712345678.'];
        }

        return $this->call($values, $to, 'This is a test message from your shop.');
    }

    /** @return array{ok: bool, note: string} */
    private function call(array $values, string $phone, string $text): array
    {
        $s = $values['settings'] ?? [];
        $url = (string) ($s['url'] ?? '');

        if (! \App\Services\Integrations\SafeUrl::ok($url)) {
            return ['ok' => false, 'note' => 'The address must be https and a public website.'];
        }

        parse_str((string) ($values['credentials']['extra'] ?? ''), $extra);
        $params = $extra + [(string) ($s['to_param'] ?? 'to') => $phone, (string) ($s['message_param'] ?? 'message') => $text];

        try {
            $http = Http::timeout(10)->withoutRedirecting();
            $res = ($s['method'] ?? 'POST') === 'GET' ? $http->get($url, $params) : $http->asForm()->post($url, $params);
        } catch (ConnectionException) {
            return ['ok' => false, 'note' => 'Could not reach the service.'];
        }

        $need = trim((string) ($s['success_text'] ?? ''));
        $ok = $res->successful() && ($need === '' || str_contains(mb_strtolower($res->body()), mb_strtolower($need)));

        // Only a short fixed sentence is kept: the answer may repeat the key we sent.
        if ($ok) {
            return ['ok' => true, 'note' => 'Sent.'];
        }

        return ['ok' => false, 'note' => $res->successful() ? 'The service answered, but not the word you expected.' : 'The service did not accept it.'];
    }
}
