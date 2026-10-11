@props([
    'href' => null,
    'icon' => null,
    'variant' => 'primary', // primary | outline
])

@php

    $base = 'inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold transition';

    $variants = [
        'primary' => 'text-white bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]
         shadow-[0_18px_45px_rgba(105,111,199,0.25)]
         hover:brightness-105',

        'outline' => 'text-slate-800 bg-white border border-slate-200
         shadow-[0_10px_30px_rgba(15,23,42,0.06)]
         hover:bg-slate-50',
    ];

    $style = $variants[$variant] ?? $variants['primary'];

@endphp


@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => "$base $style"]) }}>
    @else
        <button {{ $attributes->merge(['class' => "$base $style"]) }}>
@endif


@if ($icon)
    <i class="{{ $icon }} text-sm"></i>
@endif

<span>
    {{ $slot }}
</span>


@if ($href)
    </a>
@else
    </button>
@endif
