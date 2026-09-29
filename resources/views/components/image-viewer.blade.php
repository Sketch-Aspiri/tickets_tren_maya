{{-- Visor de imagen a pantalla completa. Va dentro de un elemento con x-data="imageViewer" (ver chat.js). --}}
<div x-show="isOpen" x-cloak x-on:keydown.escape.window="close" x-on:click="close" role="dialog" aria-modal="true" aria-label="{{ __('chat.preview') }}" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
    <button type="button" x-on:click="close" x-ref="closeButton" class="absolute right-3 top-3 inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full bg-white text-xl font-semibold text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
        <span aria-hidden="true">&times;</span><span class="sr-only">{{ __('chat.close_preview') }}</span>
    </button>
    <img x-bind:src="src" x-bind:alt="alt" class="max-h-full max-w-full rounded-md object-contain shadow-lg">
</div>
