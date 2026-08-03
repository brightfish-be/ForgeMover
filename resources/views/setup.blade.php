<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ForgeMover setup</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; padding: 2rem 1rem;
               background: light-dark(#f5f6f8, #101216); color: light-dark(#1d2129, #e6e8ec); }
        .wrap { max-width: 46rem; margin: 0 auto; }
        h1 { font-size: 1.6rem; } h1 small { color: #888; font-weight: normal; font-size: 1rem; }
        .card { background: light-dark(#fff, #191d24); border: 1px solid light-dark(#e2e5ea, #2a2f38);
                border-radius: .6rem; padding: 1.2rem 1.4rem; margin-bottom: 1.2rem; }
        .card h2 { margin-top: 0; font-size: 1.1rem; }
        .check { display: flex; gap: .5rem; padding: .2rem 0; font-size: .92rem; }
        .check .detail { color: #889; margin-left: auto; text-align: right; font-size: .8rem; max-width: 55%; }
        .ok { color: #1a9c46; } .fail { color: #d33; } .opt { color: #d90; }
        label { display: block; font-size: .85rem; margin: .7rem 0 .2rem; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: .5rem .6rem; border-radius: .4rem;
                border: 1px solid light-dark(#ccd0d7, #3a404c); background: light-dark(#fff, #12151a); color: inherit; }
        button { margin-top: 1rem; padding: .55rem 1.2rem; border: 0; border-radius: .4rem;
                 background: #4f46e5; color: #fff; font-weight: 600; cursor: pointer; }
        button:hover { background: #4338ca; }
        .muted { color: #889; font-size: .85rem; }
        .status { background: #1a9c4622; border: 1px solid #1a9c46; color: light-dark(#116c30, #7ce0a3);
                  padding: .6rem .9rem; border-radius: .4rem; margin-bottom: 1rem; }
        .errors { background: #d3333322; border: 1px solid #d33; color: light-dark(#a22, #f0a0a0);
                  padding: .6rem .9rem; border-radius: .4rem; margin-bottom: 1rem; }
        .done-banner { text-align: center; padding: 1.4rem; }
        a { color: #6366f1; }
        code { background: light-dark(#eef0f4, #232833); padding: .1rem .35rem; border-radius: .3rem; font-size: .85em; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>🚚 ForgeMover <small>first-run setup</small></h1>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error) <div>{{ $error }}</div> @endforeach
        </div>
    @endif

    @if ($complete)
        <div class="card done-banner">
            <h2>✅ Setup is complete</h2>
            <p><a href="/admin">Open the ForgeMover dashboard →</a></p>
            @if ($serverCount === 0)
                <form method="post" action="{{ route('setup.sync') }}">
                    @csrf
                    <button type="submit">Run the first Forge sync now</button>
                </form>
                <p class="muted">Pulls all servers &amp; sites from the Forge API and queues ssh probes.</p>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>1 · Requirements</h2>
        @foreach ($checks as $check)
            <div class="check">
                <span class="{{ $check['ok'] ? 'ok' : ($check['required'] ? 'fail' : 'opt') }}">
                    {{ $check['ok'] ? '✓' : ($check['required'] ? '✗' : '○') }}
                </span>
                <span>{{ $check['label'] }}</span>
                @unless ($check['ok'])
                    <span class="detail">{{ $check['detail'] }}</span>
                @endunless
            </div>
        @endforeach
        <form method="post" action="{{ route('setup.migrate') }}">
            @csrf
            <button type="submit">Generate key &amp; migrate database</button>
            <span class="muted"> — safe to run repeatedly</span>
        </form>
    </div>

    <div class="card">
        <h2>2 · Secrets</h2>
        <form method="post" action="{{ route('setup.secrets') }}">
            @csrf
            <label for="token">Forge API token {{ $hasToken ? '(already configured — paste to replace)' : '' }}</label>
            <input type="password" id="token" name="token" autocomplete="off"
                   placeholder="{{ $hasToken ? '••••••••••••' : 'paste your token' }}">
            <p class="muted">Create one at <a href="https://forge.laravel.com/profile/api" target="_blank">forge.laravel.com/profile/api</a>.
                It is validated against the API before being written to <code>.env</code>.
                @if ($org) Current organization: <code>{{ $org }}</code>.@endif</p>

            <label for="gandi">Gandi account id <span class="muted">(optional, for DNS deep links)</span></label>
            <input type="text" id="gandi" name="gandi" value="{{ old('gandi') }}">
            <label for="cloudflare">Cloudflare account id <span class="muted">(optional, for DNS deep links)</span></label>
            <input type="text" id="cloudflare" name="cloudflare" value="{{ old('cloudflare') }}">

            <p class="muted">Per-server <code>&lt;HOST&gt;_SUDO_PASSWORD</code> / <code>&lt;HOST&gt;_MYSQL_PASSWORD</code>
                secrets used by the ops scripts stay in the repo-root <code>.env</code>, not here.</p>
            <button type="submit">Validate &amp; save</button>
        </form>
    </div>

    <div class="card">
        <h2>3 · Admin user</h2>
        @if ($hasUsers)
            <p class="muted">✓ An admin user exists. Log in at <a href="/admin">/admin</a>.</p>
        @else
            <form method="post" action="{{ route('setup.admin') }}">
                @csrf
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name', 'Admin') }}" required>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                <label for="password">Password (min 8 characters)</label>
                <input type="password" id="password" name="password" required>
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required>
                <button type="submit">Create admin user</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>4 · First sync</h2>
        <p class="muted">Pull every server and site from the Forge API, then queue ssh probes for
            facts (OS, PHP, disk) and site types. Needs steps 1–3 done.</p>
        <form method="post" action="{{ route('setup.sync') }}">
            @csrf
            <button type="submit" {{ $complete ? '' : 'disabled' }}>Sync servers &amp; sites</button>
        </form>
    </div>

    <div class="card">
        <h2>Running the app</h2>
        <p class="muted">
            ForgeMover needs three processes — <code>gloncher gloncher.ini</code> runs them all in one terminal:<br>
            · <code>php artisan serve --port=8600</code> (web)<br>
            · <code>php artisan queue:work</code> (relocation steps, probes)<br>
            · <code>php artisan schedule:work</code> (periodic Forge sync + server probes)
        </p>
    </div>
</div>
</body>
</html>
