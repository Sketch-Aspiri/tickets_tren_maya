{{-- Pie comun del ecosistema Zona Oriente (igual en el Dashboard). --}}
<footer {{ $attributes->merge(['class' => 'border-t border-brand-green/10 bg-white']) }}>
    <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-4 text-xs text-gray-500 sm:flex-row sm:px-6 lg:px-8">
        <p>{{ __('common.ecosystem.footer') }}</p>
        <p class="flex items-center gap-3">
            <a href="{{ config('ecosistema.urls.dashboard') }}/dashboard" class="font-medium text-brand-teal hover:underline">{{ __('common.ecosystem.dashboard') }}</a>
            <span aria-hidden="true">·</span>
            <a href="{{ config('ecosistema.urls.tickets') }}/dashboard" class="font-medium text-brand-teal hover:underline">{{ __('common.ecosystem.tickets') }}</a>
        </p>
    </div>
</footer>
