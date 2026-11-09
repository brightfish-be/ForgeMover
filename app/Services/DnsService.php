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
        $answer = $this->dig('+noall', '+answer', 'A', $site, '@'.$nameservers[0]);
        $authoritative = $answer !== '';
        if (! $authoritative) {
            $answer = $this->dig('+noall', '+answer', 'A', $site);
        }
        [$ip, $cname, $ttl] = $this->parseAnswer($answer);

        $soa = $this->soa($zone, $authoritative ? $nameservers[0] : null);

        return [
            'ok' => true,
            'site' => $site,
            'zone' => $zone,
            'ip' => $ip,
            'cname' => $cname,
            'ttl' => $ttl,
            'nameservers' => $nameservers,
            'soa' => $soa,
            'provider' => $this->provider($nameservers),
            'authoritative' => $authoritative,
            'checked_at' => now()->format('H:i:s'),
        ];
    }

    /** @return array{primary: string, contact: string, serial: int}|null */
    protected function soa(string $zone, ?string $nameserver): ?array
    {
        $answer = $nameserver ? $this->dig('+noall', '+answer', 'SOA', $zone, '@'.$nameserver) : '';
        if ($answer === '') {
            $answer = $this->dig('+noall', '+answer', 'SOA', $zone);
        }
        foreach (explode("\n", $answer) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 7 && $parts[3] === 'SOA') {
                return [
                    'primary' => rtrim($parts[4], '.'),
                    // rname: first dot separates user from host → hostmaster.example.com = hostmaster@example.com
                    'contact' => preg_replace('/\./', '@', rtrim($parts[5], '.'), 1),
                    'serial' => (int) $parts[6],
                ];
            }
        }

        return null;
    }

    /**
     * Current A/CNAME record (fresh, for the cutover poll). Queries the
     * authoritative nameserver when given — the local resolver caches the old
     * record for its full TTL, which makes a just-repointed domain look stale.
     */
    public function liveIp(string $site, ?string $nameserver = null): array
    {
        $answer = $nameserver ? $this->dig('+noall', '+answer', 'A', $site, '@'.$nameserver) : '';
        $authoritative = $answer !== '';
        if (! $authoritative) {
            $answer = $this->dig('+noall', '+answer', 'A', $site);
        }
        [$ip, $cname, $ttl] = $this->parseAnswer($answer);

        // An authoritative NS answers a CNAMEd name with just the CNAME (it
        // won't follow it out of the zone) — chase it via the resolver.
        if ($ip === '' && $cname !== '') {
            [$ip] = $this->parseAnswer($this->dig('+noall', '+answer', 'A', $cname));
        }

        return [
            'ip' => $ip,
            'cname' => $cname,
            'ttl' => $ttl,
            'authoritative' => $authoritative,
            'checked_at' => now()->format('H:i:s'),
        ];
    }

    /** @return array{0: string, 1: string, 2: ?int} [ip, cname, ttl] from a dig +noall +answer block */
    protected function parseAnswer(string $answer): array
    {
        $ip = '';
        $cname = '';
        $ttl = null;
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

        return [$ip, $cname, $ttl];
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

    /**
     * Deep link into the provider's DNS management UI. A URL saved in settings
     * for the zone's SOA primary nameserver (dns_manage:<ns>, editable on the
     * wizard) wins over the built-in provider patterns.
     */
    public function manageUrl(string $provider, string $zone, ?string $primaryNs = null): ?string
    {
        if ($primaryNs && ($url = Setting::get('dns_manage:'.self::nsKey($primaryNs)))) {
            return str_replace('{zone}', $zone, $url);
        }

        $gandi = Setting::get('dns_account:gandi');
        $cloudflare = Setting::get('dns_account:cloudflare');

        return match ($provider) {
            'Combell' => "https://my.combell.com/en/product/dns/overview/{$zone}",
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

    /** Normalized settings key part for a nameserver ("ns3.combell.net." → "ns3.combell.net"). */
    public static function nsKey(string $nameserver): string
    {
        return strtolower(rtrim(trim($nameserver), '.'));
    }
}
