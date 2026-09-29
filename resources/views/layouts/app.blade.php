<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head')
    </head>
    <body class="bg-brand-mist font-sans text-gray-900 antialiased">
        <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-brand-green focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-brand-teal">{{ __('common.skip_to_content') }}</a>

        <div x-data="sidebar" class="min-h-screen lg:flex">
            @include('layouts.navigation')

            <div class="flex min-w-0 flex-1 flex-col">
                {{-- Barra superior (solo movil) --}}
                <div class="sticky top-0 z-20 flex items-center justify-between border-b border-brand-green/10 bg-white px-4 py-2 shadow-sm lg:hidden">
                    <a href="{{ route('dashboard') }}" class="inline-flex rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2">
                        <x-application-logo class="h-11" />
                    </a>
                    <button type="button" x-on:click="openMenu" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal" aria-label="{{ __('common.nav.open_menu') }}">
                        <x-icon name="menu" class="h-6 w-6" />
                    </button>
                </div>

                @isset($header)
                    <header class="border-b border-brand-green/10 bg-white [&_h1]:text-xl [&_h1]:font-bold [&_h1]:tracking-tight sm:[&_h1]:text-2xl">
                        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main id="contenido" class="mx-auto w-full max-w-7xl flex-1 space-y-6 px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                    <x-flash-messages />

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
