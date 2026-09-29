@props(['user' => null, 'size' => 'md', 'label' => null])

{{-- Foto de perfil redonda; sin foto, la inicial del nombre. Decorativa por defecto (el nombre ya va al lado);
     con `label` se anuncia a lectores de pantalla. Requiere `avatar_path` cargado en el usuario. --}}
@php
    $sizes = ['sm' => 'h-8 w-8 text-xs', 'md' => 'h-9 w-9 text-sm', 'lg' => 'h-20 w-20 text-2xl'];
    $classes = 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-green font-semibold uppercase text-white '.($sizes[$size] ?? $sizes['md']);
    $url = $user?->avatarUrl();
    $initial = mb_substr((string) ($label ?? $user?->name ?? '?'), 0, 1);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }} @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
    @else
        {{ $initial }}
    @endif
</span>
