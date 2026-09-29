@props(['title' => null])

{{-- Tarjeta base. Con `title` lleva un encabezado con filete inferior para separar el titulo del contenido. --}}
<section {{ $attributes->merge(['class' => 'rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm sm:p-6']) }}>
    @if ($title)
        <h2 class="-mx-4 -mt-4 mb-4 border-b border-brand-green/10 px-4 py-3 text-base font-semibold text-brand-green sm:-mx-6 sm:-mt-6 sm:mb-5 sm:px-6 sm:py-4">
            {{ $title }}
        </h2>
    @endif

    {{ $slot }}
</section>
