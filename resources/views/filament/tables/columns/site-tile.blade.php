@php
    /* Forge-style identity tile: pastel initial, hue picked from the site name. */
    $name = $getRecord()->name;
    [$bg, $text] = [
        ['bg-emerald-50', 'text-emerald-600'],
        ['bg-orange-50', 'text-orange-600'],
        ['bg-blue-50', 'text-blue-600'],
        ['bg-violet-50', 'text-violet-600'],
        ['bg-rose-50', 'text-rose-600'],
        ['bg-amber-50', 'text-amber-700'],
    ][crc32($name) % 6];
@endphp

<div class="{{ $bg }} {{ $text }} flex h-10 w-10 items-center justify-center rounded-lg text-sm font-semibold uppercase">
    {{ substr($name, 0, 1) }}
</div>
