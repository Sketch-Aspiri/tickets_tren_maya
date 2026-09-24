@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl'
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
@endphp

{{-- Alpine (build CSP): la logica vive en Alpine.data('modal') (resources/js/app.js). Los datos van en data-*. --}}
<div
    x-data="modal"
    data-name="{{ $name }}"
    data-show="{{ $show ? '1' : '0' }}"
    data-focusable="{{ $attributes->has('focusable') ? '1' : '0' }}"
    x-on:open-modal.window="onOpenModal"
    x-on:close-modal.window="onCloseModal"
    x-on:close.stop="close"
    x-on:keydown.escape.window="close"
    x-on:keydown.tab="onTab"
    x-show="show"
    x-cloak
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all motion-reduce:transition-none"
        x-on:click="close"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-brand-green-dark opacity-60"></div>
    </div>

    <div
        x-show="show"
        class="mb-6 overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-xl transform transition-all motion-reduce:transition-none sm:w-full {{ $maxWidth }} sm:mx-auto"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>
