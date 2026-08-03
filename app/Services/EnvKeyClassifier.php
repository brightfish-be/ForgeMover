<?php

namespace App\Services;

/**
 * Classifies .env key NAMES for the relocation preflight: which secrets must
 * be rotated (compromised source), which values change on the new server,
 * which carry over as-is. Pure logic, no I/O.
 */
class EnvKeyClassifier
{
    protected const CREDENTIAL = '/(PASSWORD|SECRET|KEY|TOKEN|DSN|SALT|AUTH|PRIVATE|LICENSE|WEBHOOK)/i';

    protected const TARGET_SPECIFIC = [
        'APP_URL', 'ASSET_URL', 'APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        'DB_SOCKET', 'REDIS_HOST', 'REDIS_PORT', 'MEMCACHED_HOST', 'SESSION_DOMAIN',
        'SANCTUM_STATEFUL_DOMAINS', 'TRUSTED_PROXIES', 'TRUSTED_HOSTS', 'HORIZON_DOMAIN',
        'TELESCOPE_DOMAIN', 'OCTANE_HTTPS',
    ];

    protected const SERVER_LOCAL_SECRET = ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MEMCACHED_PASSWORD'];

    protected const STANDARD_PREFIXES = [
        'APP_', 'LOG_', 'DB_', 'BROADCAST_', 'CACHE_', 'FILESYSTEM_', 'FILESYSTEMS_', 'QUEUE_',
        'REDIS_', 'SESSION_', 'MAIL_', 'AWS_', 'VITE_', 'MIX_', 'PUSHER_', 'MEMCACHED_',
        'SANCTUM_', 'PASSPORT_', 'TELESCOPE_', 'HORIZON_', 'NOVA_', 'SCOUT_', 'MEILISEARCH_',
        'ALGOLIA_', 'SENTRY_', 'FLARE_', 'BUGSNAG_', 'DEBUGBAR_', 'TRUSTED_', 'OCTANE_',
        'REVERB_', 'ABLY_', 'POSTMARK_', 'RESEND_', 'MAILGUN_', 'SES_', 'SLACK_', 'STRIPE_',
        'CASHIER_', 'PADDLE_', 'TWILIO_', 'VONAGE_', 'NEXMO_', 'GOOGLE_', 'FACEBOOK_', 'GITHUB_',
        'GITLAB_', 'BITBUCKET_', 'PUSHER', 'SENTRY', 'DYNAMODB_', 'PUSHOVER_', 'PAPERTRAIL_',
        'ROLLBAR_', 'NIGHTWATCH_', 'PULSE_', 'SUPERVISOR_', 'BCRYPT_', 'HASH_', 'LIVEWIRE_',
        'FILAMENT_', 'JWT_', 'MAINTENANCE_', 'PHP_CLI_SERVER_',
    ];

    /**
     * @param  list<string>  $keys  .env key names with non-empty values
     * @param  bool  $compromised  whether the source server is compromised
     */
    public function classify(array $keys, bool $compromised = true): array
    {
        $buckets = [
            'rotate' => [],        // credentials that must get NEW values (compromised source)
            'change' => [],        // target-specific values Forge (re)generates
            'carry_secret' => [],  // credentials safe to copy over
            'carry_custom' => [],  // app-specific non-secrets to copy
            'carry_standard' => [], // standard Laravel keys to copy
        ];

        foreach ($keys as $key) {
            $isCredential = (bool) preg_match(self::CREDENTIAL, $key);
            $isStandard = $this->isStandard($key);

            if (in_array($key, self::SERVER_LOCAL_SECRET, true) || in_array($key, self::TARGET_SPECIFIC, true)) {
                $buckets['change'][] = $key;
            } elseif ($isCredential) {
                $buckets[$compromised ? 'rotate' : 'carry_secret'][] = $key;
            } elseif ($isStandard) {
                $buckets['carry_standard'][] = $key;
            } else {
                $buckets['carry_custom'][] = $key;
            }
        }

        return $buckets;
    }

    protected function isStandard(string $key): bool
    {
        foreach (self::STANDARD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
