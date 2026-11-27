{{-- Explica la marca de los campos obligatorios (WCAG 3.3.2). Va al inicio del formulario. --}}
<p {{ $attributes->merge(['class' => 'mb-4 text-xs text-gray-700']) }}>{{ __('common.required_note') }}</p>
