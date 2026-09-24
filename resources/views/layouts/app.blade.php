<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head')
    </head>
    <body class="bg-brand-mist font-sans text-gray-900 antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-brand-green focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-brand-teal">{{ __('common.skip_to_content') }}</a>

        <div x-data="{ sidebarOpen: false }" class="min-h-screen lg:flex">
            @include('layouts.navigation')

            <div class="flex min-w-0 flex-1 flex-col">
                {{-- Barra superior (solo movil) --}}
                <div class="sticky top-0 z-20 flex items-center justify-between border-b border-t-4 border-brand-green/10 border-t-brand-green bg-white px-4 py-2 lg:hidden">
                    <a href="{{ route('dashboard') }}" class="inline-flex rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
                        <x-application-logo class="h-12" />
                    </a>
                    <button type="button" x-on:click="sidebarOpen = true" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal" aria-label="{{ __('common.nav.open_menu') }}">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>

                @isset($header)
                    <header class="border-b border-brand-green/10 bg-white shadow-sm">
                        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main id="contenido" class="mx-auto w-full max-w-7xl flex-1 space-y-4 px-4 py-6 sm:px-6 lg:px-8">
                    <x-flash-messages />

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
