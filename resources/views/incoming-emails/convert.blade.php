@php
    /** @var \App\Models\IncomingEmail $email */
    /** @var \App\Models\Activity $activity */
    $senderLine = $email->from_name ? "{$email->from_name} <{$email->from_email}>" : $email->from_email;
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('emails.convert.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('emails.convert.title') }}</h1>
    </x-slot>

    <x-card class="max-w-3xl" :title="__('emails.convert.source_summary')">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-600">{{ __('emails.convert.sender') }}</dt><dd class="break-words font-medium">{{ $senderLine }}</dd></div>
            <div><dt class="text-gray-600">{{ __('emails.convert.received_at') }}</dt><dd><x-local-datetime :value="$email->received_at" /></dd></div>
        </dl>
    </x-card>

    @isset($proposal)
        <x-card class="max-w-3xl" :title="__('emails.proposal.title')">
            <div class="space-y-2 text-sm" role="note">
                <p>{{ __('emails.proposal.summary', [
                    'client' => $proposal->client?->name ?? __('emails.proposal.unknown_client'),
                    'responsible' => $proposal->responsible?->name,
                    'team' => $proposal->team?->name,
                    'date' => $proposal->due_date->format('d/m/Y'),
                    'confidence' => $proposal->confidence,
                ]) }}</p>
                @if ($proposal->rationale)
                    <p class="text-gray-700"><span class="font-medium">{{ __('emails.proposal.rationale') }}:</span> {{ $proposal->rationale }}</p>
                @endif
                <p class="text-gray-600">{{ __('emails.proposal.review_hint') }}</p>
            </div>
        </x-card>
    @endisset

    <x-card class="max-w-3xl">
        <form method="POST" action="{{ route('incoming-emails.convert.store', $email) }}">
            @include('activities._form', [
                'showRecurrence' => false,
                'submitLabel' => __('emails.convert.submit'),
                'cancelUrl' => route('incoming-emails.show', $email),
            ])
        </form>
    </x-card>
</x-app-layout>
