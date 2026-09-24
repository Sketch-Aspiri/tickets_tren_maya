{{--
    Logo oficial Tren Maya. Se recorta con object-cover (aspect 21/10) para quitar el margen transparente
    del PNG (792x480) sin alterar el archivo. Siempre sobre superficie clara (blanco / mist).
    Uso: <x-application-logo class="h-12" /> (el ancho se calcula solo).
--}}
<img src="{{ asset('logo.png') }}" width="792" height="480" alt="{{ __('common.logo_alt') }}" {{ $attributes->merge(['class' => 'aspect-[21/10] w-auto max-w-none object-cover']) }}>
