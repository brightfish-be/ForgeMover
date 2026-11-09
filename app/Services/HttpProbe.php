<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * HTTPS reachability via the local `curl` binary: status code and TLS
 * handshake, optionally pinned to a specific server IP so a site can be
 * checked on the target before (or regardless of) the DNS cutover.
 */
class HttpProbe
{
    /** @return array{code: ?int, error: ?string, checked_at: string} */
    public function status(string $domain, ?string $ip = null): array
    {
        static $curl;
        $curl ??= app(SetupStatus::class)->findBinary('curl') ?? 'curl';

        $args = [$curl, '--silent', '--show-error', '--output', '/dev/null',
            '--write-out', '%{http_code}', '--max-time', '10'];
        if ($ip) {
            $args = [...$args, '--resolve', "{$domain}:443:{$ip}"];
        }
        $result = Process::timeout(15)->run([...$args, "https://{$domain}"]);

        $code = (int) trim($result->output());

        return [
            'code' => $code >= 100 ? $code : null,
            'error' => $code >= 100 ? null : (trim($result->errorOutput()) ?: 'connection failed'),
            'checked_at' => now()->format('H:i:s'),
        ];
    }
}
