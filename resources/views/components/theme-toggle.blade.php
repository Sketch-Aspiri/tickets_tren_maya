{{--
    Selector de tema: sistema, claro u oscuro (Alpine.data('themeToggle') en resources/js/theme.js).
    Compatible con @alpinejs/csp: solo metodos y propiedades por nombre; el boton activo se marca con
    aria-pressed y se estiliza con la variante aria-pressed: de Tailwind.
--}}
<div x-data="themeToggle" {{ $attributes->merge(['class' => 'flex items-center justify-between gap-2 px-3']) }}>
    <span class="text-xs font-semibold uppercase tracking-wide text-gray-600" id="theme-toggle-label">{{ __('common.theme.label') }}</span>
    <div class="inline-flex rounded-lg border border-brand-green/15 bg-white p-0.5" role="group" aria-labelledby="theme-toggle-label">
        @foreach (['setSystem' => ['isSystem', 'monitor', 'system'], 'setLight' => ['isLight', 'sun', 'light'], 'setDark' => ['isDark', 'moon', 'dark']] as $method => [$state, $icon, $key])
            <button type="button" x-on:click="{{ $method }}" x-bind:aria-pressed="{{ $state }}"
                    title="{{ __('common.theme.'.$key) }}"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-600 transition hover:bg-brand-mist hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal aria-pressed:bg-brand-green aria-pressed:text-white aria-pressed:hover:bg-brand-green">
                <x-icon :name="$icon" class="h-4 w-4" />
                <span class="sr-only">{{ __('common.theme.'.$key) }}</span>
            </button>
        @endforeach
    </div>
</div>
