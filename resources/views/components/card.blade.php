@props(['title' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-brand-green/10 bg-white p-4 shadow-sm sm:p-6']) }}>
    @if ($title)
        <h2 class="mb-4 flex gap-2 text-lg font-semibold text-brand-green">
            <span class="w-1 shrink-0 self-stretch rounded-full bg-brand-mint" aria-hidden="true"></span>
            {{ $title }}
        </h2>
    @endif

    {{ $slot }}
</section>
