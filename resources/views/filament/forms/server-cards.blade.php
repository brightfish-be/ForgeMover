{{-- Destination-server picker for the relocate action: radio cards, green for a current Ubuntu, gray when it reaches EOL within 2 years. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="server-cards fi-fo-radio" role="radiogroup">
        @foreach ($servers as $server)
            @php
                $eol = $server->osEol();
                $id = $getId().'-'.$server->id;
                $eolDate = $eol ? \Illuminate\Support\Carbon::parse($eol['date'])->format('M Y') : null;
                $tooltip = match (true) {
                    $eol === null => 'Ubuntu version unknown',
                    $eol['eol'] => "Ubuntu {$server->os_version} EOL since {$eolDate}",
                    default => "Ubuntu {$server->os_version} EOL {$eolDate}",
                };
                $disk = rtrim((string) $server->disk_total, 'G');
                $phpEol = $server->php_default ? \App\Services\FactsService::phpEolDate($server->php_default) : null;
            @endphp
            <label for="{{ $id }}" title="{{ $tooltip }}" @class(['fi-fo-radio-label', 'server-card-eol' => $server->osEolSoon(), 'server-card-current' => ! $server->osEolSoon()])>
                <input type="radio" id="{{ $id }}" name="{{ $getId() }}" value="{{ $server->id }}"
                       wire:model="{{ $getStatePath() }}" class="fi-radio-input">
                <div class="fi-fo-radio-label-text">
                    <p>{{ $server->name }}@if ($server->compromised) ☢ COMPROMISED @endif</p>
                    <p class="fi-fo-radio-label-description server-card-specs">
                        <span @class(['eol-pill' => $server->osEolSoon()]) title="{{ $tooltip }}">{{ $server->os_version ? "Ubuntu {$server->os_version}" : 'Ubuntu unknown' }}</span>
                        @if ($server->php_default)
                            <span @class(['eol-pill' => \App\Services\FactsService::phpEolSoon($server->php_default)])
                                  title="{{ $phpEol ? 'PHP EOL '.\Illuminate\Support\Carbon::parse($phpEol)->format('M Y') : 'PHP version unknown' }}">PHP {{ $server->php_default }}</span>
                        @endif
                        @if ($server->cpus)
                            <span title="{{ $server->cpus }} vCPU">{{ $server->cpus }}<x-fas-microchip /></span>
                        @endif
                        @if ($server->ram_gb !== '')
                            <span title="{{ $server->ram_gb }} GB RAM">{{ round((float) $server->ram_gb) }}<x-fas-memory /></span>
                        @endif
                        @if ($disk !== '')
                            <span title="{{ $disk }} GB SSD{{ $server->disk_pct ? " ({$server->disk_pct}% used)" : '' }}">{{ $disk }}<x-fas-hard-drive /></span>
                        @endif
                        <span>{{ $server->sites_count }} {{ str('site')->plural($server->sites_count) }}</span>
                        @if ($server->destination_only)
                            <span>destination-only</span>
                        @endif
                    </p>
                </div>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
