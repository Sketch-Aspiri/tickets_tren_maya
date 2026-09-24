<x-guest-layout>
    <h1 class="mb-2 text-lg font-semibold text-brand-green">{{ __("account.status.{$stateKey}.heading") }}</h1>
    <p class="text-sm text-gray-600">{{ __("account.status.{$stateKey}.body") }}</p>

    <dl class="mt-4 space-y-2 rounded-md border border-brand-green/10 bg-brand-mist p-3 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('users.index.columns.name') }}</dt><dd class="truncate font-medium">{{ $user->name }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('users.index.columns.email') }}</dt><dd class="truncate font-medium">{{ $user->email }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('users.index.columns.status') }}</dt><dd><x-status-badge :status="$user->status" /></dd></div>
    </dl>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <x-secondary-button type="submit" class="w-full justify-center">{{ __('auth.logout') }}</x-secondary-button>
    </form>
</x-guest-layout>
