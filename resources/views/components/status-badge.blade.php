@props(['status'])

@php
    /** @var \App\Enums\UserStatus|\App\Enums\TicketStatus $status */
    [$classes, $dot] = match ($status) {
        \App\Enums\UserStatus::Active => ['bg-brand-mint/20 text-brand-green', 'bg-brand-teal'],
        \App\Enums\UserStatus::Pending => ['bg-amber-100 text-amber-900', 'bg-amber-600'],
        \App\Enums\UserStatus::Inactive => ['bg-gray-200 text-gray-800', 'bg-gray-500'],
        \App\Enums\TicketStatus::Pending => ['bg-gray-200 text-gray-800', 'bg-gray-500'],
        \App\Enums\TicketStatus::InProgress => ['bg-sky-100 text-sky-900', 'bg-sky-600'],
        \App\Enums\TicketStatus::InReview => ['bg-amber-100 text-amber-900', 'bg-amber-600'],
        \App\Enums\TicketStatus::Completed => ['bg-brand-mint/20 text-brand-green', 'bg-brand-teal'],
        \App\Enums\TicketStatus::Cancelled => ['bg-red-100 text-red-900', 'bg-red-600'],
    };
@endphp

{{-- El estado se comunica con texto; el punto es solo decorativo. Sirve para estados de usuario y de ticket. --}}
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
    {{ $status->label() }}
</span>
