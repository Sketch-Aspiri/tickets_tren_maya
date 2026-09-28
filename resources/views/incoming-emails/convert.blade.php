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
