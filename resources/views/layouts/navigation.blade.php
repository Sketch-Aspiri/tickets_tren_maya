{{-- Fondo oscurecido del menu movil --}}
<div x-cloak x-show="menuOpen" x-on:click="closeMenu" class="fixed inset-0 z-30 bg-brand-green-dark/60 lg:hidden" aria-hidden="true"></div>

<aside x-cloak
    class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col border-r border-t-4 border-brand-green/10 border-t-brand-green bg-white text-gray-800 shadow-xl transition-transform duration-200 motion-reduce:transition-none lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none"
    x-bind:class="panelClass"
    aria-label="{{ config('app.name') }}"
>
    <div class="flex items-center justify-between px-4 py-3">
        <a href="{{ route('dashboard') }}" class="inline-flex rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
            <x-application-logo class="h-16" />
        </a>
        <button type="button" x-on:click="closeMenu" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal lg:hidden" aria-label="{{ __('common.nav.close_menu') }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto border-t border-brand-green/10 px-3 py-3">
        <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
            {{ __('common.nav.dashboard') }}
        </x-sidebar-link>

        @can('viewAny', \App\Models\Ticket::class)
            <p class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('common.nav.tickets_section') }}</p>
            <x-sidebar-link :href="route('tickets.pending')" :active="request()->routeIs('tickets.pending')">
                {{ __('common.nav.pending') }}
            </x-sidebar-link>
            <x-sidebar-link :href="route('tickets.index')" :active="request()->routeIs('tickets.*') && ! request()->routeIs('tickets.pending')">
                {{ __('common.nav.tickets') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\User::class)
            <p class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-gray-600">{{ __('common.nav.management') }}</p>
            <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                {{ __('common.nav.users') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\Team::class)
            <x-sidebar-link :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                {{ __('common.nav.teams') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\Category::class)
            <x-sidebar-link :href="route('categories.index')" :active="request()->routeIs('categories.*')">
                {{ __('common.nav.categories') }}
            </x-sidebar-link>
        @endcan
    </nav>

    <div class="space-y-1 border-t border-brand-green/10 bg-brand-mist/60 px-3 py-3">
        <div class="px-3 pb-2">
            <p class="truncate text-sm font-semibold text-gray-900">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-gray-600">{{ auth()->user()->email }}</p>
        </div>
        <x-sidebar-link :href="route('profile.edit')" :active="request()->routeIs('profile.*')">
            {{ __('common.nav.profile') }}
        </x-sidebar-link>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex min-h-[44px] w-full items-center rounded-md border-s-4 border-transparent px-3 text-left text-sm font-medium text-gray-700 hover:bg-white hover:text-brand-green focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal">
                {{ __('auth.logout') }}
            </button>
        </form>
    </div>
</aside>
