<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.head')
    </head>
    <body class="bg-brand-mist font-sans text-gray-900 antialiased">
        <div class="flex min-h-screen flex-col items-center px-4 py-8 sm:justify-center sm:py-12">
            <a href="/" class="mb-6 inline-flex rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 focus-visible:ring-offset-brand-mist">
                <x-application-logo class="h-24 sm:h-28" />
            </a>

            <main class="w-full sm:max-w-md">
                <div class="overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-lg shadow-brand-green/5">
                    <div class="h-1 bg-brand-mint" aria-hidden="true"></div>
                    <div class="px-6 py-6 sm:px-8 sm:py-8">
                        {{ $slot }}
                    </div>
                </div>
            </main>

            <p class="mt-6 text-center text-xs text-gray-600">{{ config('app.name') }}</p>
        </div>
    </body>
</html>
