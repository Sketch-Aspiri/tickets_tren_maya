@php
    $status = session('status');
    $statusKey = is_string($status) ? "common.flash.{$status}" : null;
@endphp

<div class="space-y-3" role="status">
    @if ($statusKey && \Illuminate\Support\Facades\Lang::has($statusKey))
        <div class="rounded-md border-l-4 border-brand-teal bg-brand-mint/15 p-3 text-sm font-medium text-brand-green">{{ __($statusKey) }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-md border-l-4 border-red-600 bg-red-50 p-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
    @endif
</div>
