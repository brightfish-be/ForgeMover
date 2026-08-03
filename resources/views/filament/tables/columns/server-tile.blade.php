@php
    /* Forge-style identity tile for servers; compromised servers get a red radiation tile. */
    $record = $getRecord();
    [$bg, $text] = $record->compromised
        ? ['bg-rose-50', 'text-rose-600']
        : [
            ['bg-emerald-50', 'text-emerald-600'],
            ['bg-orange-50', 'text-orange-600'],
            ['bg-blue-50', 'text-blue-600'],
            ['bg-violet-50', 'text-violet-600'],
            ['bg-sky-50', 'text-sky-600'],
            ['bg-amber-50', 'text-amber-700'],
        ][crc32($record->name) % 6];
@endphp

<div class="{{ $bg }} {{ $text }} flex h-10 w-10 items-center justify-center rounded-lg">
    <x-filament::icon :icon="$record->compromised ? 'fas-radiation' : 'fas-server'" class="h-4 w-4" />
</div>
