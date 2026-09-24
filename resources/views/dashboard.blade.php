<x-app-layout>
    <x-slot name="title">{{ __('common.dashboard.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('common.dashboard.welcome', ['name' => $user->name]) }}</h1>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-card>
            <p class="text-sm text-gray-500">{{ __('common.dashboard.role', ['role' => $user->roleEnum()?->label() ?? '—']) }}</p>
            <p class="mt-2 text-sm text-gray-600">{{ __('common.dashboard.coming_soon') }}</p>
        </x-card>

        @if ($pendingCount !== null)
            <x-card :title="__('common.dashboard.pending_registrations')">
                <p class="text-4xl font-semibold text-brand-green">{{ $pendingCount }}</p>
                <x-text-link :href="route('users.index', ['status' => \App\Enums\UserStatus::Pending->value])" class="mt-2">
                    {{ __('common.dashboard.review_pending') }}
                </x-text-link>
            </x-card>
        @endif
    </div>
</x-app-layout>
