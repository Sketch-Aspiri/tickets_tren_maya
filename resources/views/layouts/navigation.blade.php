{{-- Fondo oscurecido del menu movil --}}
<div x-cloak x-show="menuOpen" x-on:click="closeMenu" class="fixed inset-0 z-30 bg-brand-green-dark/60 lg:hidden" aria-hidden="true"></div>

<aside x-cloak
    class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col border-r border-brand-green/10 bg-white text-gray-800 shadow-xl transition-transform duration-200 motion-reduce:transition-none lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none"
    x-bind:class="panelClass"
    aria-label="{{ config('app.name') }}"
>
    <div class="flex items-center justify-between border-b border-brand-green/10 px-4 py-3">
        <a href="{{ route('dashboard') }}" class="inline-flex rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
            <x-application-logo class="h-16" />
        </a>
        <button type="button" x-on:click="closeMenu" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal lg:hidden" aria-label="{{ __('common.nav.close_menu') }}">
            <x-icon name="close" />
        </button>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-3" aria-label="{{ __('common.nav.main') }}">
        <x-sidebar-link icon="home" :href="route('dashboard')" :active="request()->routeIs('dashboard')">
            {{ __('common.nav.dashboard') }}
        </x-sidebar-link>

        <x-sidebar-link icon="bell" :href="route('notifications.index')" :active="request()->routeIs('notifications.*')">
            <span class="flex w-full items-center justify-between gap-2" x-data="chatUnread" data-url="{{ route('notifications.summary') }}">
                {{ __('common.nav.notifications') }}
                <span x-show="hasUnread" x-cloak x-text="count" class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-red-700 px-2 py-0.5 text-xs font-bold text-white"></span>
            </span>
        </x-sidebar-link>

        @can('viewAny', \App\Models\Ticket::class)
            <x-nav-heading>{{ __('common.nav.tickets_section') }}</x-nav-heading>
            <x-sidebar-link icon="check" :href="route('tickets.pending')" :active="request()->routeIs('tickets.pending')">
                {{ __('common.nav.pending') }}
            </x-sidebar-link>
            <x-sidebar-link icon="list" :href="route('tickets.index')" :active="request()->routeIs('tickets.*') && ! request()->routeIs('tickets.pending')">
                {{ __('common.nav.tickets') }}
            </x-sidebar-link>
            {{-- Listado y alta de actividades: solo quien las gestiona (jefe / coordinador). El empleado ve las suyas en "Mis pendientes". --}}
            @can('viewAny', \App\Models\Activity::class)
                <x-sidebar-link icon="calendar" :href="route('activities.index')" :active="request()->routeIs('activities.*') && ! request()->routeIs('activities.templates.*')">
                    {{ __('common.nav.activities') }}
                </x-sidebar-link>
                {{-- Plantillas de recurrencia: nunca aparecen en el listado principal (misma visibilidad que la gestion de actividades). --}}
                <x-sidebar-link icon="repeat" :href="route('activities.templates.index')" :active="request()->routeIs('activities.templates.*')">
                    {{ __('activities.templates.nav') }}
                </x-sidebar-link>
            @endcan
        @endcan

        {{-- Panel de seguimiento: solo jefe (global) y coordinador (su equipo). La ruta la protege la Policy; esto solo oculta el enlace. --}}
        @can('view-dashboard')
            <x-nav-heading>{{ __('common.nav.tracking_section') }}</x-nav-heading>
            <x-sidebar-link icon="chart" :href="route('tracking.index')" :active="request()->routeIs('tracking.*')">
                {{ __('common.nav.tracking') }}
            </x-sidebar-link>
        @endcan

        {{-- Bandeja de correos entrantes: jefe, administrador y coordinador (permiso `emails.view`). --}}
        @can('viewAny', \App\Models\IncomingEmail::class)
            <x-nav-heading>{{ __('common.nav.emails_section') }}</x-nav-heading>
            <x-sidebar-link icon="mail" :href="route('incoming-emails.index')" :active="request()->routeIs('incoming-emails.*')">
                {{ __('common.nav.incoming_emails') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\Conversation::class)
            <x-nav-heading>{{ __('common.nav.chat') }}</x-nav-heading>
            <x-sidebar-link icon="chat" :href="route('chat.index')" :active="request()->routeIs('chat.*')">
                <span class="flex w-full items-center justify-between gap-2" x-data="chatUnread" data-url="{{ route('chat.unread') }}">
                    {{ __('common.nav.chat') }}
                    <span x-show="hasUnread" x-cloak x-text="count" class="inline-flex min-w-[1.5rem] justify-center rounded-full bg-red-700 px-2 py-0.5 text-xs font-bold text-white"></span>
                </span>
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\User::class)
            <x-nav-heading>{{ __('common.nav.management') }}</x-nav-heading>
            <x-sidebar-link icon="users" :href="route('users.index')" :active="request()->routeIs('users.*')">
                {{ __('common.nav.users') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\Team::class)
            <x-sidebar-link icon="flag" :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                {{ __('common.nav.teams') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \App\Models\Category::class)
            <x-sidebar-link icon="tag" :href="route('categories.index')" :active="request()->routeIs('categories.*')">
                {{ __('common.nav.categories') }}
            </x-sidebar-link>
        @endcan

        @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
            <x-sidebar-link icon="shield" :href="route('audit.index')" :active="request()->routeIs('audit.*')">
                {{ __('common.nav.audit') }}
            </x-sidebar-link>
        @endcan
    </nav>

    <div class="space-y-0.5 border-t border-brand-green/10 bg-brand-mist/60 px-3 py-3">
        <div class="flex items-center gap-3 px-3 pb-2">
            <x-user-avatar :user="auth()->user()" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-gray-600">{{ auth()->user()->roleEnum()?->label() ?? auth()->user()->email }}</p>
            </div>
        </div>
        <x-sidebar-link icon="user" :href="route('profile.edit')" :active="request()->routeIs('profile.*')">
            {{ __('common.nav.profile') }}
        </x-sidebar-link>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="group flex min-h-[44px] w-full items-center gap-3 rounded-lg px-3 text-left text-sm font-medium text-gray-700 hover:bg-white hover:text-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-teal">
                <x-icon name="logout" class="h-5 w-5 shrink-0 text-gray-600 group-hover:text-red-800" />
                {{ __('auth.logout') }}
            </button>
        </form>
    </div>
</aside>
