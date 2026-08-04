<?php

return [

    /*
     * Laravel Forge API v2 (org-based). Token: https://forge.laravel.com/profile/api
     */
    'forge' => [
        'token' => env('FORGE_API_TOKEN'),
        'org' => env('FORGE_ORG'),
        'base_url' => env('FORGE_BASE_URL', 'https://forge.laravel.com'),
        'api_url' => env('FORGE_API_URL', 'https://forge.laravel.com/api'),
    ],

    /*
     * Default unix user owning /home/<user>/<site> on Forge servers.
     */
    'default_user' => env('FORGEMOVER_DEFAULT_USER', 'forge'),

    /*
     * ssh probe timeouts (seconds).
     */
    'ssh_timeout' => (int) env('FORGEMOVER_SSH_TIMEOUT', 8),

    /*
     * Cache TTLs (seconds) for remote probes — mirrors the legacy app.
     */
    'ttl' => [
        'facts' => 3600,
        'site_types' => 3600,
        'env_keys' => 3600,
        'env_template' => 3600,
        'inventory' => 3600,
        'dns' => 60,
        'dns_live' => 15,
        'server_ip' => 86400,
        'storage_link' => 30,
        'db_info' => 86400,
    ],

    /*
     * Binaries the app shells out to; checked by forgemover:setup and /setup.
     */
    'required_binaries' => ['ssh', 'dig', 'curl', 'tar', 'awk'],
    'optional_binaries' => ['jq', 'git', 'mysql'],
];
