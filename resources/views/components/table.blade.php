{{-- Tabla responsiva: desplazamiento horizontal en pantallas angostas. Usa `hidden md:table-cell` en columnas secundarias.
     El estilo de <thead> y de las filas vive aqui para que todas las tablas se vean igual. --}}
<div class="relative overflow-x-auto rounded-lg border border-brand-green/10 bg-white shadow-sm">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-brand-green/10 text-sm text-gray-800 [&_thead]:border-b-2 [&_thead]:border-brand-mint [&_thead]:bg-brand-mist [&_thead]:text-left [&_thead]:text-xs [&_thead]:font-semibold [&_thead]:uppercase [&_thead]:tracking-wider [&_thead]:text-gray-700 [&_tbody_tr:hover]:bg-brand-mist/60']) }}>
        {{ $slot }}
    </table>
</div>
