<x-app-layout>
    <x-slot name="title">{{ __('common.dashboard.title') }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-medium text-brand-teal">{{ __('common.dashboard.role', ['role' => $user->roleEnum()?->label() ?? '—']) }}</p>
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('common.dashboard.welcome', ['name' => $user->name]) }}</h1>
    </x-slot>

    @if ($pendingCount !== null)
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-300 border-l-4 border-l-amber-600 bg-amber-50 p-4 shadow-sm" aria-labelledby="pending-registrations">
            <div>
                <h2 id="pending-registrations" class="text-sm font-semibold text-amber-900">{{ __('common.dashboard.pending_registrations') }}</h2>
                <p class="mt-1 text-4xl font-bold tabular-nums text-amber-900">{{ $pendingCount }}</p>
            </div>
            <x-secondary-button :href="route('users.index', ['status' => \App\Enums\UserStatus::Pending->value])">
                {{ __('common.dashboard.review_pending') }}
            </x-secondary-button>
        </section>
    @endif

    <section aria-labelledby="quick-access" class="space-y-3">
        <h2 id="quick-access" class="text-base font-semibold text-gray-900">{{ __('common.dashboard.quick_access') }}</h2>
        <p class="text-sm text-gray-700">{{ __('common.dashboard.coming_soon') }}</p>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @php
                $links = [
                    ['route' => route('tickets.pending'), 'icon' => 'check', 'label' => __('common.nav.pending'), 'hint' => __('common.dashboard.hint_pending'), 'show' => true],
                    ['route' => route('tickets.create'), 'icon' => 'plus', 'label' => __('tickets.new'), 'hint' => __('common.dashboard.hint_new_ticket'), 'show' => auth()->user()->can('create', \App\Models\Ticket::class)],
                    ['route' => route('chat.index'), 'icon' => 'chat', 'label' => __('common.nav.chat'), 'hint' => __('common.dashboard.hint_chat'), 'show' => auth()->user()->can('viewAny', \App\Models\Conversation::class)],
                    ['route' => route('tracking.index'), 'icon' => 'chart', 'label' => __('common.dashboard.open_tracking'), 'hint' => __('common.dashboard.hint_tracking'), 'show' => auth()->user()->can('view-dashboard')],
                ];
            @endphp

            @foreach ($links as $link)
                @if ($link['show'])
                    <a href="{{ $link['route'] }}" class="group flex min-h-[44px] items-start gap-4 rounded-xl border border-brand-green/10 bg-white p-4 shadow-sm transition-colors hover:border-brand-teal hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal motion-reduce:transition-none">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-mist text-brand-green group-hover:bg-white">
                            <x-icon :name="$link['icon']" class="h-6 w-6" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-brand-green">{{ $link['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-gray-700">{{ $link['hint'] }}</span>
                        </span>
                        <x-icon name="arrow-right" class="mt-1 h-5 w-5 text-gray-500 group-hover:text-brand-green" />
                    </a>
                @endif
            @endforeach
        </div>
    </section>
</x-app-layout>
