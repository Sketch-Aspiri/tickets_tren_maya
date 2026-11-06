{{-- Botón "Activar notificaciones" (Web Push). Lógica en Alpine.data('pushToggle') de resources/js/push.js. --}}
@php
    $labels = collect(['state_on', 'state_off', 'state_blocked', 'state_unsupported', 'state_ios_install', 'state_missing_key', 'error'])
        ->mapWithKeys(fn (string $key): array => [$key => __('push.ui.'.$key)])
        ->all();
@endphp
<x-card :title="__('push.ui.title')">
    <div x-data="pushToggle"
         data-subscribe-url="{{ route('push.subscribe') }}"
         data-unsubscribe-url="{{ route('push.unsubscribe') }}"
         data-labels="{{ json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
         class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 space-y-1">
            <p class="text-sm text-gray-700">{{ __('push.ui.intro') }}</p>
            <p class="text-sm font-semibold text-gray-900" role="status" aria-live="polite" x-text="statusText"></p>
        </div>

        <div class="shrink-0">
            <button type="button" x-show="isOff" x-cloak x-on:click="enable" x-bind:disabled="busy"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-brand-green px-4 py-2 text-sm font-semibold text-white hover:bg-brand-green/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 disabled:opacity-60">
                <x-icon name="bell" class="me-2 h-5 w-5" />{{ __('push.ui.enable') }}
            </button>
            <button type="button" x-show="isOn" x-cloak x-on:click="disable" x-bind:disabled="busy"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-brand-green/30 bg-white px-4 py-2 text-sm font-semibold text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 disabled:opacity-60">
                {{ __('push.ui.disable') }}
            </button>
        </div>
    </div>
</x-card>
