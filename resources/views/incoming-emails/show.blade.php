@php
    /** @var \App\Models\IncomingEmail $email */
    $senderLine = $email->from_name ? "{$email->from_name} <{$email->from_email}>" : $email->from_email;
@endphp

<x-app-layout>
    <x-slot name="title">{{ \Illuminate\Support\Str::limit($email->subject, 60) }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="text-xs text-gray-700">{{ __('emails.show.subject') }}</p>
                <h1 class="truncate text-xl font-semibold leading-tight text-brand-green">{{ $email->subject }}</h1>
            </div>
            <x-text-link :href="route('incoming-emails.index')" class="shrink-0">{{ __('emails.show.back') }}</x-text-link>
        </div>
    </x-slot>

    <x-card>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <x-status-badge :status="$email->status" />
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-600">{{ __('emails.show.sender') }}</dt><dd class="break-words font-medium">{{ $senderLine }}</dd></div>
            <div><dt class="text-gray-600">{{ __('emails.show.received_at') }}</dt><dd><x-local-datetime :value="$email->received_at" /></dd></div>
        </dl>

        <h3 class="mb-1 mt-5 text-sm font-semibold text-gray-800">{{ __('emails.show.body') }}</h3>
        {{-- Contenido de un correo externo: dato no confiable, siempre escapado; whitespace-pre-line conserva los
             saltos de linea sin interpretar HTML (mismo criterio que comentarios/descripciones). --}}
        <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $email->body }}</p>
    </x-card>

    <x-card :title="__('emails.show.attachments')">
        @forelse ($email->attachments as $attachment)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-green/10 py-2 text-sm last:border-0">
                <div class="min-w-0">
                    <a href="{{ route('incoming-email-attachments.download', $attachment) }}" class="inline-flex min-h-[44px] items-center break-all font-medium text-brand-teal underline underline-offset-2 hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $attachment->original_name }}</a>
                    <p class="text-xs text-gray-600">{{ number_format($attachment->size / 1024, 1) }} KB</p>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-700">{{ __('emails.show.no_attachments') }}</p>
        @endforelse
    </x-card>

    @if ($email->status === \App\Enums\IncomingEmailStatus::Converted)
        <x-card :title="__('emails.show.converted_info')">
            <p class="text-sm text-gray-900">
                @can('view', $email->activity)
                    <x-text-link :href="route('activities.show', $email->activity)">{{ $email->activity->folio }}</x-text-link>
                @else
                    {{ $email->activity?->folio }}
                @endcan
            </p>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-600">{{ __('emails.show.reviewed_by') }}</dt><dd class="font-medium">{{ $email->reviewer?->name }}</dd></div>
                <div><dt class="text-gray-600">{{ __('emails.show.reviewed_at') }}</dt><dd><x-local-datetime :value="$email->reviewed_at" /></dd></div>
            </dl>
        </x-card>
    @elseif ($email->status === \App\Enums\IncomingEmailStatus::Discarded)
        <x-card :title="__('emails.show.discard_reason')">
            <p class="whitespace-pre-line break-words text-sm text-gray-900">{{ $email->discard_reason }}</p>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-600">{{ __('emails.show.reviewed_by') }}</dt><dd class="font-medium">{{ $email->reviewer?->name }}</dd></div>
                <div><dt class="text-gray-600">{{ __('emails.show.reviewed_at') }}</dt><dd><x-local-datetime :value="$email->reviewed_at" /></dd></div>
            </dl>
        </x-card>
    @endif

    @if ($email->status === \App\Enums\IncomingEmailStatus::PendingReview)
        <x-card>
            <div class="flex flex-wrap items-center gap-3">
                @can('convert', $email)
                    <x-primary-button :href="route('incoming-emails.convert', $email)">{{ __('emails.show.convert_button') }}</x-primary-button>
                @endcan

                {{-- Descartar exige un motivo obligatorio: mismo patron que "rechazar" en users/show.blade.php
                     (modal + Alpine.data('modalTrigger')/('modal'), ya registrados en resources/js/app.js; el
                     build de Alpine es CSP-safe, sin expresiones nuevas en linea). --}}
                @can('discard', $email)
                    <x-danger-button type="button" x-data="modalTrigger" data-modal="discard-email" x-on:click.prevent="open">{{ __('emails.show.discard_button') }}</x-danger-button>

                    <x-modal name="discard-email" maxWidth="md" focusable>
                        <form method="POST" action="{{ route('incoming-emails.discard', $email) }}" class="p-6">
                            @csrf
                            <p class="text-sm text-gray-700">{{ __('emails.show.discard_confirm') }}</p>
                            <x-input-label for="discard_reason" class="mt-4" :value="__('emails.show.discard_reason_label')" />
                            <textarea id="discard_reason" name="reason" rows="4" maxlength="1000" required class="mt-1 block min-h-[44px] w-full rounded-lg border-gray-500 text-sm shadow-sm focus:border-brand-teal focus:ring-2 focus:ring-brand-teal">{{ old('reason') }}</textarea>
                            <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                            <div class="mt-6 flex justify-end gap-3">
                                <x-secondary-button x-on:click="close">{{ __('common.actions.cancel') }}</x-secondary-button>
                                <x-danger-button>{{ __('emails.show.discard_button') }}</x-danger-button>
                            </div>
                        </form>
                    </x-modal>
                @endcan
            </div>
        </x-card>
    @endif
</x-app-layout>
