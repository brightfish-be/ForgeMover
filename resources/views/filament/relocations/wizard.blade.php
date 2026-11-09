<x-filament-panels::page>
    <div wire:poll.5s class="grid gap-6" style="grid-template-columns: minmax(0, 1fr) minmax(0, 24rem);">

        {{-- step cards --}}
        <div class="flex flex-col gap-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                unix user <strong>{{ $record->unix_user }}</strong>
                · target site <strong>{{ $record->targetSiteName() }}</strong>
                @if ($siteInfo['type'])
                    · type <strong>{{ $siteInfo['type'] }}</strong>@if ($siteInfo['static']) (HTML only — no PHP, no DB)@endif
                @endif
                @if ($siteInfo['web_dir'])
                    · web dir <strong>{{ $siteInfo['web_dir'] }}</strong>
                @endif
                @if ($sourceForgeUrl)
                    · <a href="{{ $sourceForgeUrl }}" target="_blank" class="text-primary-600 underline">source in Forge</a>
                @endif
                @if ($targetForgeUrl)
                    · <a href="{{ $targetForgeUrl }}" target="_blank" class="text-primary-600 underline">target in Forge</a>
                @endif
            </div>

            @foreach ($steps as $step)
                @continue(in_array($step['key'], ['transfer', 'restore']))

                @if ($step['key'] === 'collect')
                    {{-- collect / transfer / restore share one card: three columns + sizes below --}}
                    @php $trio = collect($steps)->whereIn('key', ['collect', 'transfer', 'restore'])->values(); @endphp
                    <x-filament::section
                        :compact="true"
                        style="{{ $trio->contains(fn (array $s) => $s['current']) ? 'border-inline-start: 3px solid rgb(var(--primary-500));' : '' }}"
                    >
                        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:1rem;">
                            @foreach ($trio as $mini)
                                <div class="flex flex-col gap-2"
                                     style="{{ $mini['locked'] ? 'opacity:.55;' : '' }} {{ ! $loop->last ? 'border-inline-end:1px solid rgba(107,114,128,.25); padding-inline-end:1rem;' : '' }}">
                                    <span class="flex items-center gap-2 text-sm font-medium">
                                        <span class="inline-flex items-center justify-center rounded-full text-xs font-bold"
                                              style="width:1.5rem;height:1.5rem;flex:none;background:{{ $mini['done'] ? 'rgb(22 163 74)' : ($mini['current'] ? 'rgb(var(--primary-500))' : 'rgb(107 114 128)') }};color:white;">
                                            {{ $mini['number'] }}
                                        </span>
                                        {{ $mini['label'] }}
                                        <x-filament::badge :color="match ($mini['state']) {
                                            'success', 'done', 'skipped' => 'success',
                                            'running' => 'warning',
                                            'failed' => 'danger',
                                            default => 'gray',
                                        }">
                                            {{ $mini['state'] }}
                                        </x-filament::badge>
                                    </span>
                                    @if ($mini['summary'])
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $mini['summary'] }}</p>
                                    @endif
                                    <div class="mt-auto flex flex-col gap-2">
                                        <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            @if ($mini['done'])
                                                <input type="checkbox" checked disabled/>
                                                {{ $mini['state'] === 'skipped' ? 'skipped' : 'done' }}
                                                @if ($mini['state'] === 'skipped')
                                                    <button type="button" class="underline"
                                                            wire:click="undoStep('{{ $mini['key'] }}')">undo</button>
                                                @endif
                                            @else
                                                <input type="checkbox" wire:model.live="skipSteps.{{ $mini['key'] }}"
                                                       @disabled($mini['state'] === 'running')/>
                                                nothing to {{ $mini['key'] }} — mark as done
                                            @endif
                                        </label>
                                        <div class="flex items-center gap-2">
                                            <x-filament::button
                                                size="sm"
                                                :disabled="! $mini['can_run']"
                                                :color="$mini['state'] === 'failed' ? 'danger' : 'primary'"
                                                wire:click="runStep('{{ $mini['key'] }}')"
                                                icon="fas-play"
                                            >
                                                {{ $mini['state'] === 'failed' ? 'Retry' : ($mini['state'] === 'success' ? 'Run again' : 'Run') }}
                                            </x-filament::button>
                                            @if ($mini['state'] === 'running')
                                                <x-filament::loading-indicator class="h-5 w-5"/>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- confirm the trio: run or ticked-as-skipped, then continue to Deploy & test --}}
                        @unless ($trio->every(fn (array $s) => $s['done']))
                            @php $allTicked = $trio->every(fn (array $s) => $s['done'] || ($skipSteps[$s['key']] ?? false)); @endphp
                            <div class="mt-3 flex items-center gap-2">
                                <x-filament::button
                                    size="sm"
                                    :disabled="! $allTicked || $trio->first()['locked'] || $running"
                                    wire:click="confirmScriptSteps"
                                    icon="fas-check"
                                >
                                    Done
                                </x-filament::button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    run each step or tick it as done (an HTML-only site has nothing to transfer or restore), then confirm to continue
                                </span>
                            </div>
                        @endunless

                        {{-- site + archive sizes on both servers --}}
                        <div class="mt-4" style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:1rem;">
                            @foreach (['old' => "source · {$record->sourceServer->name}", 'new' => "destination · {$record->targetServer->name}"] as $side => $title)
                                <div class="text-xs">
                                    <div class="mb-1 font-medium">{{ $title }}</div>
                                    @if (! $sizes[$side])
                                        <div class="text-gray-400">{{ $side === 'old' ? 'unreachable' : 'no site dir yet' }}</div>
                                    @else
                                        <div class="text-gray-500 dark:text-gray-400">
                                            {{ collect($sizes[$side]['dirs'])->map(fn ($d) => "{$d['label']} {$d['size']}")->implode(' · ') ?: 'site dir not found' }}
                                        </div>
                                        <table class="w-full mt-1">
                                            <tbody>
                                            @forelse ($sizes[$side]['files'] as $file)
                                                <tr>
                                                    <td class="font-mono">{{ $file['name'] }}</td>
                                                    <td class="text-end whitespace-nowrap">{{ \Illuminate\Support\Number::fileSize($file['bytes'], 1) }}</td>
                                                    <td class="text-end whitespace-nowrap text-gray-400">{{ $file['mtime'] }}</td>
                                                </tr>
                                            @empty
                                                <tr><td class="text-gray-400">no archives</td></tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-1 text-xs text-gray-400">sizes fetched {{ $sizes['fetched_at'] }}</div>
                    </x-filament::section>
                    @continue
                @endif

                <x-filament::section
                    :compact="true"
                    style="{{ $step['locked'] ? 'opacity:.55;' : '' }} {{ $step['current'] ? 'border-inline-start: 3px solid rgb(var(--primary-500));' : '' }}"
                >
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center rounded-full text-xs font-bold"
                                  style="width:1.5rem;height:1.5rem;background:{{ $step['done'] ? 'rgb(22 163 74)' : ($step['current'] ? 'rgb(var(--primary-500))' : 'rgb(107 114 128)') }};color:white;">
                                {{ $step['number'] }}
                            </span>
                            {{ $step['label'] }}
                            <x-filament::badge :color="match ($step['state']) {
                                'success', 'done' => 'success',
                                'running' => 'warning',
                                'failed' => 'danger',
                                default => 'gray',
                            }">
                                {{ $step['state'] }}
                            </x-filament::badge>
                        </span>
                    </x-slot>

                    {{-- sub-checks for manual steps --}}
                    @if (! $step['script'] && count($step['sub_checks']))
                        <ul class="flex flex-col gap-1 mb-3">
                            @foreach ($step['sub_checks'] as $check)
                                <li class="flex items-start gap-2 text-sm rounded px-2 py-1"
                                    style="{{ in_array($check['status'], ['warn', 'fail']) && ! $check['ticked'] ? 'background: rgba(245, 158, 11, .1);' : '' }}">
                                    <input type="checkbox"
                                           @checked($check['ticked'])
                                           @disabled($check['auto'] || $step['done'])
                                           wire:click="toggleCheck('{{ $check['key'] }}', $event.target.checked)"
                                           class="mt-1"/>
                                    <span>
                                        <span class="font-medium">{{ $check['label'] }}</span>
                                        @if ($check['status'] !== 'unknown')
                                            <x-filament::badge :color="match ($check['status']) {
                                                'pass' => 'success', 'warn' => 'warning', 'fail' => 'danger', default => 'gray',
                                            }" size="sm">{{ $check['status'] }}</x-filament::badge>
                                        @endif
                                        @if ($check['detail'])
                                            {{-- URLs in the detail become clickable links --}}
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{!! preg_replace(
                                                '~(https?://[^\s<]+)~',
                                                '<a href="$1" target="_blank" class="text-primary-600 underline">$1</a>',
                                                e($check['detail'])) !!}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- DNS zone panel: nameservers, SOA, editable management URL --}}
                    @if (in_array($step['key'], ['preflight', 'cutover']) && ! $step['done'] && ($dnsInfo['ok'] ?? false))
                        <div class="mb-3 rounded px-2 py-1.5 text-xs text-gray-500 dark:text-gray-400"
                             style="background: rgba(107,114,128,.08);">
                            <div>
                                <span class="font-medium">nameservers</span> {{ implode(' · ', $dnsInfo['nameservers']) }}
                            </div>
                            @if ($dnsInfo['soa'] ?? null)
                                <div>
                                    <span class="font-medium">SOA</span>
                                    {{ $dnsInfo['soa']['primary'] }} · {{ $dnsInfo['soa']['contact'] }}@if ($dnsInfo['soa']['serial']) · serial {{ $dnsInfo['soa']['serial'] }}@endif
                                </div>
                            @endif
                            <div class="mt-1 flex items-center gap-2">
                                @if (! ($dnsInfo['manage_custom'] ?? false))
                                    <label for="dnsManageUrl-{{ $step['key'] }}" class="font-medium whitespace-nowrap">
                                        DNS mgmt URL ({{ $dnsInfo['manage_ns'] }}):
                                    </label>
                                    <x-filament::input.wrapper class="flex-1">
                                        <x-filament::input type="url" wire:model="dnsManageUrl" id="dnsManageUrl-{{ $step['key'] }}"
                                                           placeholder="https://… ({zone} is replaced by the domain)"/>
                                    </x-filament::input.wrapper>
                                    <x-filament::button size="sm" color="gray" wire:click="saveDnsManageUrl">
                                        save
                                    </x-filament::button>
                                @endif
                                @if ($dnsInfo['manage'] ?? null)
                                    <a href="{{ $dnsInfo['manage'] }}" target="_blank"
                                       class="text-primary-600 underline whitespace-nowrap">open DNS ↗</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- temp domain input on the forge_site card --}}
                    @if ($step['key'] === 'forge_site' && ! $step['done'])
                        <div class="mb-3 flex items-center gap-2 text-sm">
                            <label for="tempDomain" class="font-medium">Temp domain:</label>
                            <x-filament::input.wrapper class="flex-1">
                                <x-filament::input type="text" wire:model="tempDomain" id="tempDomain"
                                                   placeholder="{{ $suggestedDomain }}"/>
                            </x-filament::input.wrapper>
                            <x-filament::button size="sm" color="gray"
                                                wire:click="$set('tempDomain', '{{ $suggestedDomain }}')">
                                use suggestion
                            </x-filament::button>
                        </div>
                    @endif

                    {{-- env template on the forge_site card --}}
                    @if ($step['key'] === 'forge_site' && $envTemplate)
                        <details class="mb-3 text-sm">
                            <summary class="cursor-pointer font-medium">.env template for the target site
                                ({{ count(array_filter(explode("\n", $envTemplate))) }} lines)</summary>
                            <textarea readonly rows="10" class="w-full font-mono text-xs mt-2 rounded border-gray-300 dark:bg-gray-900"
                                      onclick="this.select()">{{ $envTemplate }}</textarea>
                        </details>
                    @endif

                    {{-- one-line digest: run metrics for script steps, checklist summary for done manual steps --}}
                    @if ($step['summary'])
                        <p class="mb-3 text-sm text-gray-500">{{ $step['summary'] }}</p>
                    @endif

                    {{-- actions --}}
                    <div class="flex items-center gap-2">
                        @if ($step['script'])
                            <x-filament::button
                                size="sm"
                                :disabled="! $step['can_run']"
                                :color="$step['state'] === 'failed' ? 'danger' : 'primary'"
                                wire:click="runStep('{{ $step['key'] }}')"
                                icon="fas-play"
                            >
                                {{ $step['state'] === 'failed' ? 'Retry' : ($step['state'] === 'success' ? 'Run again' : 'Run') }}
                            </x-filament::button>
                            @if ($step['state'] === 'running')
                                <x-filament::loading-indicator class="h-5 w-5"/>
                            @endif
                        @elseif ($step['done'])
                            <x-filament::button size="sm" color="gray" wire:click="undoStep('{{ $step['key'] }}')">
                                undo
                            </x-filament::button>
                            <span class="text-xs text-gray-500">confirmed {{ $step['done_at']?->diffForHumans() }}</span>
                        @else
                            @php $todo = collect($step['sub_checks'])->reject(fn ($c) => $c['ticked'])->count(); @endphp
                            <x-filament::button
                                size="sm"
                                :disabled="$step['locked'] || $todo > 0"
                                wire:click="confirmStep('{{ $step['key'] }}')"
                                icon="fas-check"
                            >
                                Confirm
                            </x-filament::button>
                            @if ($todo > 0 && ! $step['locked'])
                                <span class="text-xs text-warning-600">{{ $todo }} of {{ count($step['sub_checks']) }} still to do</span>
                            @endif
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- log + history sidebar --}}
        <div class="flex flex-col gap-4">
            <x-filament::section :compact="true">
                <x-slot name="heading">
                    Log {{ $activeScript ? "· {$activeScript}" : '' }}
                    @if ($running)
                        <x-filament::badge color="warning">running</x-filament::badge>
                    @endif
                </x-slot>
                <pre class="text-xs font-mono whitespace-pre-wrap rounded p-2"
                     style="max-height: 420px; overflow-y: auto; background: rgba(0,0,0,.85); color: #9ae69a;"
                     @if($running) x-data x-init="$el.scrollTop = $el.scrollHeight" @endif
                >{{ $log !== '' ? $log : 'no runs yet' }}</pre>
            </x-filament::section>

            <x-filament::section :compact="true" heading="Run history">
                <table class="w-full text-xs">
                    <thead>
                    <tr class="text-start text-gray-500">
                        <th class="text-start">step</th>
                        <th class="text-start">state</th>
                        <th class="text-start">started</th>
                        <th class="text-start">finished</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($history as $run)
                        <tr>
                            <td>{{ $run->step }}</td>
                            <td>
                                <x-filament::badge :color="match ($run->state) {
                                    'success' => 'success', 'running' => 'warning', 'failed' => 'danger', default => 'gray',
                                }" size="sm">{{ $run->state }}</x-filament::badge>
                            </td>
                            <td>{{ $run->started_at?->format('m-d H:i') }}</td>
                            <td>{{ $run->finished_at?->format('m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-gray-500">no runs yet</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
