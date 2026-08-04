<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Process;

/**
 * DNS facts via the local `dig` binary: zone, authoritative nameservers,
 * provider detection, live A-record checks for cutover.
 */
class DnsService
{
    protected const PROVIDERS = [
        'gandi.net' => 'Gandi', 'cloudflare.com' => 'Cloudflare', 'combell' => 'Combell',
        'digitalocean.com' => 'DigitalOcean', 'awsdns' => 'AWS Route 53', 'azure-dns' => 'Azure DNS',
        'googledomains.com' => 'Google Domains', 'google.com' => 'Google Cloud DNS', 'ovh.net' => 'OVH',
        'one.com' => 'one.com', 'transip' => 'TransIP', 'hetzner' => 'Hetzner',
        'registrar-servers.com' => 'Namecheap', 'domaincontrol.com' => 'GoDaddy',
        'dnsimple.com' => 'DNSimple', 'nsone.net' => 'NS1', 'netlify.com' => 'Netlify',
        'vercel-dns.com' => 'Vercel', 'name.com' => 'name.com', 'openprovider' => 'Openprovider',
        'versio' => 'Versio', 'hostinger' => 'Hostinger', 'siteground' => 'SiteGround',
    ];

    protected function dig(string ...$args): string
    {
        static $dig;
        $dig ??= app(SetupStatus::class)->findBinary('dig') ?? 'dig';
        $result = Process::timeout(10)->run([$dig, '+time=3', '+tries=1', ...$args]);

        // dig writes warnings like ";; communication error" to stdout — drop
        // them, or a timeout gets mistaken for a real answer.
        $lines = array_filter(explode("\n", trim($result->output())),
            fn (string $line) => $line !== '' && ! str_starts_with(ltrim($line), ';'));

        return implode("\n", $lines);
    }

    /** Zone, nameservers, provider, A record + TTL for a site. */
    public function lookup(string $site): array
    {
        // Walk up labels until we find NS records → that's the zone. Parse the
        // full answer and require type NS: `+short NS` on a CNAMEd name returns
        // the CNAME target, which is not a nameserver.
        $labels = explode('.', $site);
        $zone = null;
        $nameservers = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            foreach (explode("\n", $this->dig('+noall', '+answer', 'NS', $candidate)) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5 && $parts[3] === 'NS') {
                    $nameservers[] = rtrim($parts[4], '.');
                }
            }
            if ($nameservers) {
                $zone = $candidate;
                break;
            }
        }

        if (! $zone) {
            return ['ok' => false, 'site' => $site];
        }

        // Query the authoritative NS for the A record so the TTL is the real one.
        $ip = '';
        $cname = '';
        $ttl = null;
        $authoritative = false;
        $answer = $this->dig('+noall', '+answer', 'A', $site, '@'.$nameservers[0]);
        if ($answer === '') {
            $answer = $this->dig('+noall', '+answer', 'A', $site);
        } else {
            $authoritative = true;
        }
        foreach (explode("\n", $answer) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5 && in_array($parts[3], ['A', 'CNAME'], true)) {
                $ttl ??= (int) $parts[1];
                if ($parts[3] === 'A') {
                    $ip = $parts[4];
                } else {
                    $cname = rtrim($parts[4], '.');
                }
            }
        }

        return [
            'ok' => true,
            'site' => $site,
            'zone' => $zone,
            'ip' => $ip,
            'cname' => $cname,
            'ttl' => $ttl,
            'nameservers' => $nameservers,
            'provider' => $this->provider($nameservers),
            'authoritative' => $authoritative,
        ];
    }

    /** Current A record (fresh, for the cutover poll). */
    public function liveIp(string $site): array
    {
        $answer = $this->dig('+noall', '+answer', 'A', $site);
        $ip = '';
        $ttl = null;
        foreach (explode("\n", $answer) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5 && $parts[3] === 'A') {
                $ip = $parts[4];
                $ttl = (int) $parts[1];
                break;
            }
        }

        return ['ip' => $ip, 'ttl' => $ttl];
    }

    protected function provider(array $nameservers): string
    {
        foreach ($nameservers as $ns) {
            foreach (self::PROVIDERS as $fragment => $name) {
                if (str_contains($ns, $fragment)) {
                    return $name;
                }
            }
        }
        // Unknown → registrable domain of the first NS
        $parts = explode('.', $nameservers[0] ?? '');

        return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : 'unknown';
    }

    /** Deep link into the provider's DNS management UI, when we know how. */
    public function manageUrl(string $provider, string $zone): ?string
    {
        $gandi = Setting::get('dns_account:gandi');
        $cloudflare = Setting::get('dns_account:cloudflare');

        return match ($provider) {
            'Combell' => "https://my.combell.com/en/product/dns/{$zone}",
            'Gandi' => $gandi ? "https://admin.gandi.net/domain/{$gandi}/{$zone}/records" : null,
            'Cloudflare' => $cloudflare ? "https://dash.cloudflare.com/{$cloudflare}/{$zone}/dns/records" : null,
            'DigitalOcean' => "https://cloud.digitalocean.com/networking/domains/{$zone}",
            'AWS Route 53' => 'https://console.aws.amazon.com/route53/v2/hostedzones',
            'OVH' => "https://www.ovh.com/manager/#/web/zone/{$zone}/records",
            'TransIP' => "https://www.transip.nl/cp/domein-hosting/?domain={$zone}",
            'one.com' => 'https://www.one.com/admin/dns-overview.do',
            'Namecheap' => "https://ap.www.namecheap.com/Domains/DomainControlPanel/{$zone}/advancedns",
            'GoDaddy' => "https://dcc.godaddy.com/control/{$zone}/dns",
            'DNSimple' => "https://dnsimple.com/domains/{$zone}/records",
            default => null,
        };
    }
}
