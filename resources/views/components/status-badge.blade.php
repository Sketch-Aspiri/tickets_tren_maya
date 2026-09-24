@props(['status'])

@php
    /** @var \App\Enums\UserStatus $status */
    [$classes, $dot] = match ($status) {
        \App\Enums\UserStatus::Active => ['bg-brand-mint/20 text-brand-green', 'bg-brand-teal'],
        \App\Enums\UserStatus::Pending => ['bg-amber-100 text-amber-900', 'bg-amber-600'],
        \App\Enums\UserStatus::Inactive => ['bg-gray-200 text-gray-800', 'bg-gray-500'],
    };
@endphp

{{-- El estado se comunica con texto; el punto es solo decorativo. --}}
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
    {{ $status->label() }}
</span>
