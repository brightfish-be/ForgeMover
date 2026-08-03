{{-- Forge-style record header: identity tile · name + status badge · subtitle (DESIGN.md §3.1) --}}
<x-filament-panels::header
    :actions="$this->getCachedHeaderActions()"
    :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []"
>
    <x-slot name="heading">
        <span class="flex items-center gap-4">
            <span
                class="{{ $server->compromised ? 'bg-rose-50 text-rose-600' : 'bg-blue-50 text-blue-600' }} flex h-12 w-12 items-center justify-center rounded-xl"
            >
                <x-filament::icon
                    :icon="$server->compromised ? 'fas-radiation' : 'fas-server'"
                    class="h-5 w-5"
                />
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="flex items-center gap-2">
                    {{ $server->name }}
                    <x-filament::badge
                        :color="$server->reachable ? 'success' : 'danger'"
                        :icon="$server->reachable ? 'fas-circle-check' : 'fas-circle-xmark'"
                    >
                        {{ $server->reachable ? 'Connected' : 'Unreachable' }}
                    </x-filament::badge>
                    @if ($server->compromised)
                        <x-filament::badge color="danger" icon="fas-radiation">
                            Compromised
                        </x-filament::badge>
                    @endif
                </span>
                <span class="text-sm font-normal text-gray-500">
                    {{ collect([$server->region, $server->size])->filter()->implode(' · ') ?: 'App server' }}
                </span>
            </span>
        </span>
    </x-slot>
</x-filament-panels::header>
