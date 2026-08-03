<x-filament-panels::page>
    <div class="flex flex-col gap-6">

        <x-filament::section
            icon="fas-server"
            heading="Source server"
            description="Package-manager credentials are read from this server. Secrets are never shown — only masked summaries."
        >
            <x-slot name="afterHeader">
                <x-filament::input.wrapper class="w-56">
                    <x-filament::input.select wire:model.live="from">
                        <option value="">— pick a source —</option>
                        @foreach ($this->servers as $server)
                            <option value="{{ $server->id }}">
                                {{ $server->name }}{{ $server->compromised ? ' ☢ COMPROMISED' : '' }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-slot>

            @if (($server = $this->servers->firstWhere('id', $from))?->compromised)
                <x-filament::callout
                    color="danger"
                    icon="fas-radiation"
                    heading="{{ $server->name }} is flagged compromised"
                    class="mb-4"
                >
                    Rotate these credentials instead of copying them onward.
                </x-filament::callout>
            @endif

            <div wire:loading.delay wire:target="from" class="flex items-center gap-2 text-sm text-gray-500">
                <x-filament::loading-indicator class="h-4 w-4" />
                probing…
            </div>

            <div wire:loading.remove wire:target="from">
                @if ($this->sourceInfo)
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <p class="text-sm font-medium text-gray-950">composer auth.json</p>
                            <ul class="mt-2 flex flex-col gap-1 text-sm text-gray-500">
                                @forelse ($this->sourceInfo['composer'] as $entry)
                                    <li>
                                        {{ $entry['section'] }} · <code class="font-mono text-xs">{{ $entry['host'] }}</code>
                                        @if ($entry['username'])
                                            · user <code class="font-mono text-xs">{{ $entry['username'] }}</code>
                                        @endif
                                    </li>
                                @empty
                                    <li>none</li>
                                @endforelse
                            </ul>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-950">.npmrc</p>
                            <ul class="mt-2 flex flex-col gap-1 text-sm text-gray-500">
                                @forelse ($this->sourceInfo['npm'] as $entry)
                                    <li>{{ $entry['kind'] }} · <code class="font-mono text-xs">{{ $entry['registry'] }}</code></li>
                                @empty
                                    <li>none</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                @elseif ($from)
                    <p class="text-sm text-gray-500">Could not probe this server — is it reachable over ssh?</p>
                @else
                    <p class="text-sm text-gray-500">Pick a source server to see its credentials.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section
            icon="fas-cube"
            heading="composer auth.json"
            description="Private Packagist, GitHub and other composer tokens from the server's auth.json."
        >
            <x-slot name="afterHeader">
                <x-filament::toggle state="$wire.entangle('copyComposer')" />
            </x-slot>
        </x-filament::section>

        <x-filament::section
            icon="fab-npm"
            heading=".npmrc"
            description="npm registries and auth tokens from the server's .npmrc."
        >
            <x-slot name="afterHeader">
                <x-filament::toggle state="$wire.entangle('copyNpm')" />
            </x-slot>
        </x-filament::section>

        <x-filament::section
            icon="fas-download"
            heading="Destination server"
            description="Copying merges credentials into the destination — the source wins on conflict."
        >
            <x-slot name="afterHeader">
                <x-filament::input.wrapper class="w-56">
                    <x-filament::input.select wire:model.live="to">
                        <option value="">— pick a destination —</option>
                        @foreach ($this->servers as $server)
                            @if ($server->id !== $from)
                                <option value="{{ $server->id }}">{{ $server->name }}</option>
                            @endif
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-slot>

            @if ($this->destinationInfo)
                <p class="text-sm text-gray-500">
                    Currently has {{ count($this->destinationInfo['composer']) }} composer credential(s)
                    and {{ count($this->destinationInfo['npm']) }} npm entr(ies).
                </p>
            @else
                <p class="text-sm text-gray-500">Pick a destination server to copy the credentials to.</p>
            @endif

            <x-slot name="footer">
                <div class="flex items-center gap-3">
                    <x-filament::button
                        icon="fas-copy"
                        :disabled="! $from || ! $to"
                        wire:click="copy"
                        wire:confirm="Copy package credentials between servers?"
                    >
                        Copy credentials
                    </x-filament::button>

                    @foreach ($result as $kind => $status)
                        <x-filament::badge :color="$status === 'copied' ? 'success' : 'warning'">
                            {{ $kind }}: {{ $status }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </x-slot>
        </x-filament::section>
    </div>
</x-filament-panels::page>
