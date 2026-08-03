<x-filament-panels::page>
    <div class="grid gap-6" style="grid-template-columns: 1fr 1fr;">

        <x-filament::section heading="Source server">
            <div class="flex flex-col gap-3">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="from">
                        <option value="">— pick a source —</option>
                        @foreach ($this->servers as $server)
                            <option value="{{ $server->id }}">
                                {{ $server->name }}{{ $server->compromised ? ' ☢ COMPROMISED' : '' }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                @if ($this->sourceInfo)
                    @if (($server = $this->servers->firstWhere('id', $from))?->compromised)
                        <div class="rounded p-3 text-sm" style="background: rgba(220, 38, 38, .1); color: rgb(185, 28, 28);">
                            <strong>{{ $server->name }} is flagged compromised.</strong>
                            Rotate these credentials instead of copying them onward.
                        </div>
                    @endif
                    <div class="text-sm">
                        <p class="font-medium mb-1">composer auth.json</p>
                        <ul class="list-disc ms-5">
                            @forelse ($this->sourceInfo['composer'] as $entry)
                                <li>{{ $entry['section'] }} · {{ $entry['host'] }}
                                    @if ($entry['username']) · user <code>{{ $entry['username'] }}</code> @endif
                                </li>
                            @empty
                                <li class="text-gray-500">none</li>
                            @endforelse
                        </ul>
                        <p class="font-medium mb-1 mt-3">.npmrc</p>
                        <ul class="list-disc ms-5">
                            @forelse ($this->sourceInfo['npm'] as $entry)
                                <li>{{ $entry['kind'] }} · <code>{{ $entry['registry'] }}</code></li>
                            @empty
                                <li class="text-gray-500">none</li>
                            @endforelse
                        </ul>
                    </div>
                @elseif ($from)
                    <p class="text-sm text-gray-500">probing…</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Copy to destination">
            <div class="flex flex-col gap-3">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="to">
                        <option value="">— pick a destination —</option>
                        @foreach ($this->servers as $server)
                            @if ($server->id !== $from)
                                <option value="{{ $server->id }}">{{ $server->name }}</option>
                            @endif
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="copyComposer"/> composer auth.json
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="copyNpm"/> .npmrc
                </label>

                @if ($this->destinationInfo)
                    <p class="text-sm text-gray-500">
                        Destination currently has {{ count($this->destinationInfo['composer']) }} composer credential(s)
                        and {{ count($this->destinationInfo['npm']) }} npm entr(ies) — copying merges, source wins.
                    </p>
                @endif

                <x-filament::button
                    icon="fas-copy"
                    :disabled="! $from || ! $to"
                    wire:click="copy"
                    wire:confirm="Copy package credentials between servers?"
                >
                    Copy credentials
                </x-filament::button>

                @if ($result)
                    <ul class="text-sm">
                        @foreach ($result as $kind => $status)
                            <li>
                                <x-filament::badge :color="$status === 'copied' ? 'success' : 'warning'">
                                    {{ $kind }}: {{ $status }}
                                </x-filament::badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
