@php
    $user = auth()->user();
    $audiences = array_filter([
        'all' => true,
        'manage' => $user?->can('viewAny', \App\Models\Activity::class),
        'admin' => $user?->can('viewAny', \App\Models\User::class),
    ]);
    $faqs = collect(__('assistant.faqs'))
        ->filter(fn ($faq) => isset($audiences[$faq['audience']]))
        ->map(fn ($faq) => ['q' => $faq['q'], 'a' => $faq['a']]);
    $offset = request()->routeIs('chat.*') ? 'bottom-24 sm:bottom-4' : 'bottom-4';
@endphp

@if ($user && $faqs->isNotEmpty())
    <div x-data="temayin" data-faqs="{{ $faqs->toJson() }}" class="fixed right-4 z-50 {{ $offset }}">
        <section x-ref="panel" x-show="open" x-cloak tabindex="-1" id="temayin-panel" role="dialog" aria-label="{{ __('assistant.name') }}"
                 x-on:keydown.escape.window="close"
                 class="mb-3 flex max-h-[70vh] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-xl border border-brand-green/10 bg-white shadow-xl focus:outline-none">
            <header class="flex items-center gap-3 bg-brand-green px-4 py-3 text-white">
                <img src="{{ asset('temayin.webp') }}" alt="" width="40" height="40" class="h-10 w-10 shrink-0 rounded-full bg-white object-cover">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ __('assistant.name') }}</p>
                    <p class="text-xs text-white/80">{{ __('assistant.subtitle') }}</p>
                </div>
                <button type="button" x-on:click="close" aria-label="{{ __('assistant.close') }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    <x-icon name="close" class="h-5 w-5" />
                </button>
            </header>

            <div class="space-y-3 overflow-y-auto p-4 text-sm" aria-live="polite">
                <p class="rounded-lg bg-brand-mist px-3 py-2 text-gray-800">{{ __('assistant.greeting') }}</p>

                <div x-show="hasNoActive">
                    <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('assistant.choose') }}</h2>
                    <ul class="space-y-2">
                        @foreach ($faqs as $id => $faq)
                            <li>
                                <button type="button" data-id="{{ $id }}" x-on:click="ask" class="w-full rounded-lg border border-brand-teal/30 px-3 py-2 text-left text-brand-green hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ $faq['q'] }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div x-show="hasActive" x-cloak class="space-y-3">
                    <p class="ml-auto max-w-[90%] rounded-lg bg-brand-green px-3 py-2 text-white" x-text="activeQuestion"></p>
                    <p class="max-w-[95%] whitespace-pre-line rounded-lg bg-brand-mist px-3 py-2 text-gray-800" x-text="activeAnswer"></p>
                    <button type="button" x-on:click="back" class="inline-flex items-center gap-1 rounded-md text-sm font-semibold text-brand-teal hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                        <x-icon name="arrow-uturn-left" class="h-4 w-4" />
                        {{ __('assistant.back') }}
                    </button>
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="button" x-ref="toggle" x-on:click="toggle" x-bind:aria-expanded="toggleExpanded" aria-controls="temayin-panel" aria-label="{{ __('assistant.open') }}"
                    class="h-14 w-14 overflow-hidden rounded-full border-2 border-white bg-white shadow-lg transition hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 motion-reduce:transition-none">
                <img src="{{ asset('temayin.webp') }}" alt="{{ __('assistant.avatar_alt') }}" width="56" height="56" class="h-full w-full object-cover">
            </button>
        </div>
    </div>
@endif
