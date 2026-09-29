@props(['attachment'])

{{-- Miniatura de un adjunto imagen (png/jpg). Al hacer clic se abre en el visor (imageViewer); sin JavaScript el
     enlace abre la imagen en otra pestana. Solo se sirve por la ruta con Policy; el nombre va escapado. --}}
@if ($attachment->isPreviewableImage())
    <a href="{{ route('attachments.preview', $attachment) }}" data-preview data-name="{{ $attachment->original_name }}" target="_blank" rel="noopener" class="mb-1 block w-fit focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
        <img src="{{ route('attachments.preview', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy" decoding="async" class="max-h-48 max-w-full rounded-md border border-brand-green/10 object-contain">
    </a>
@endif
