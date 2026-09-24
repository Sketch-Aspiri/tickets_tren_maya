@props(['item', 'storeUrl', 'hint'])

@php
    // Una sola comprobacion para toda la lista (no una consulta por adjunto): borra su autor o quien gestiona el
    // registro. Es solo UX; AttachmentPolicy::delete se vuelve a aplicar en la ruta.
    $canManage = auth()->user()->can('delete', $item);
@endphp

{{-- Adjuntos de un ticket o una actividad: solo se descargan por AttachmentController (disco privado + Policy).
     `attachments.user` ya cargado; el nombre del archivo es texto de usuario: siempre escapado. --}}
<x-card :title="__('tickets.show.attachments')" id="adjuntos">
    @forelse ($item->attachments as $attachment)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-green/10 py-2 text-sm last:border-0">
            <div class="min-w-0">
                <a href="{{ route('attachments.download', $attachment) }}" class="inline-flex min-h-[44px] items-center break-all font-medium text-brand-teal underline underline-offset-2 hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $attachment->original_name }}</a>
                <p class="text-xs text-gray-600">{{ number_format($attachment->size / 1024, 1) }} KB · {{ $attachment->user->name }} · <x-local-datetime :value="$attachment->created_at" /></p>
            </div>
            @if ($canManage || (int) $attachment->user_id === (int) auth()->id())
                <form method="POST" action="{{ route('attachments.destroy', $attachment) }}" x-data="confirmSubmit" data-confirm="{{ __('tickets.show.remove_attachment_confirm') }}" x-on:submit="onSubmit">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex min-h-[44px] items-center px-2 font-medium text-red-700 hover:text-red-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">{{ __('tickets.attachment.delete') }}</button>
                </form>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-700">{{ __('tickets.show.no_attachments') }}</p>
    @endforelse

    @can('attach', $item)
        <form method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" class="mt-4">
            @csrf
            <x-input-label for="file" :value="__('tickets.attachment.label')" />
            <input id="file" name="file" type="file" required aria-describedby="file_hint" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx,.txt" class="mt-1 block min-h-[44px] w-full text-sm text-gray-800 file:mr-3 file:min-h-[44px] file:cursor-pointer file:rounded-md file:border file:border-brand-teal file:bg-white file:px-4 file:text-sm file:font-semibold file:text-brand-green hover:file:bg-brand-mist">
            <p id="file_hint" class="mt-1 text-xs text-gray-600">{{ $hint }}</p>
            <x-input-error :messages="$errors->get('file')" class="mt-2" />
            <x-primary-button class="mt-3">{{ __('tickets.attachment.submit') }}</x-primary-button>
        </form>
    @endcan
</x-card>
