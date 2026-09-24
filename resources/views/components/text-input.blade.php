@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-[44px] rounded-md border-gray-500 shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal disabled:bg-gray-100 disabled:text-gray-600']) }}>
