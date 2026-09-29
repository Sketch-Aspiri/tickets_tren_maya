@php
    $status = session('status');
    $statusKey = is_string($status) ? "common.flash.{$status}" : null;
@endphp

<div class="space-y-3" role="status">
    @if ($statusKey && \Illuminate\Support\Facades\Lang::has($statusKey))
        <div class="flex items-start gap-3 rounded-lg border border-brand-teal/30 border-l-4 border-l-brand-teal bg-white p-3 text-sm font-medium text-brand-green shadow-sm"><x-icon name="check" class="mt-0.5 h-5 w-5 text-brand-teal" /><span>{{ __($statusKey) }}</span></div>
    @endif

    @if (session('error'))
        <div class="flex items-start gap-3 rounded-lg border border-red-200 border-l-4 border-l-red-600 bg-red-50 p-3 text-sm font-medium text-red-800 shadow-sm"><x-icon name="info" class="mt-0.5 h-5 w-5 text-red-700" /><span>{{ session('error') }}</span></div>
    @endif
</div>
