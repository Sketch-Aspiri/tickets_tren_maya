@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold text-gray-800']) }}>
    {{ $value ?? $slot }}
    @if ($required)
        {{-- La marca es visual: el campo ya se anuncia como obligatorio por su atributo `required`. --}}
        <span aria-hidden="true" class="text-red-700">*</span>
    @endif
</label>
