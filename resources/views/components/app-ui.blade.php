{{-- Interfaz propia de la aplicación (resources/js/ui.js): barra de carga superior y diálogo de confirmación que
     reemplaza window.confirm. Va al final del <body> de los layouts app y guest. --}}
<div id="app-page-loader" class="hidden fixed inset-x-0 top-0 z-[60] h-1 overflow-hidden bg-brand-green/10" role="progressbar" aria-label="{{ __('common.loading') }}" aria-hidden="true" data-state="idle">
    <div class="app-loader-bar h-full bg-brand-teal"></div>
</div>

<template id="app-validation-messages" data-messages="{{ json_encode(__('common.validation_ui'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></template>

<div x-data="appDialog" x-on:app-confirm.window="show" x-on:keydown.window="onKeydown">
    <div x-show="isOpen" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-gray-900/50 p-4 sm:items-center" x-on:click.self="cancel">
        <div role="alertdialog" aria-modal="true" aria-labelledby="app-dialog-title" aria-describedby="app-dialog-message"
             class="w-full max-w-md rounded-xl border border-brand-green/10 bg-white p-5 shadow-xl">
            <div class="flex items-start gap-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-700" aria-hidden="true">
                    <x-icon name="alert" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h2 id="app-dialog-title" class="text-base font-semibold text-gray-900">{{ __('common.dialog.title') }}</h2>
                    <p id="app-dialog-message" class="mt-1 whitespace-pre-line text-sm text-gray-700" x-text="message"></p>
                </div>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-ref="cancel" x-on:click="cancel" class="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-800 hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.dialog.cancel') }}</button>
                <button type="button" x-ref="accept" x-on:click="accept" class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2">{{ __('common.dialog.confirm') }}</button>
            </div>
        </div>
    </div>
</div>
