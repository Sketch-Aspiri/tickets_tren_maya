@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-md border-l-4 border-brand-teal bg-brand-mint/15 p-3 text-sm font-medium text-brand-green']) }}>
        {{ $status }}
    </div>
@endif
