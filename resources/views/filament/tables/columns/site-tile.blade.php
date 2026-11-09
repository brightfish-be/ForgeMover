@php
    /* Forge-style identity tile: the domain's icon from avatar.qsmd.io (generated avatar when it has none). */
    $name = $getRecord()->name;
@endphp

<div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
    <img src="https://avatar.qsmd.io/domain/{{ rawurlencode($name) }}.webp"
         alt="" loading="lazy" class="h-7 w-7 object-contain">
</div>
