<x-app-layout>
    <x-slot name="title">{{ __('audit.title') }}</x-slot>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('audit.title') }}</h1>
            <p class="text-sm text-gray-700">{{ __('audit.intro') }}</p>
        </div>
    </x-slot>

    <x-card>
        <form method="GET" action="{{ route('audit.index') }}" aria-label="{{ __('audit.filters.legend') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <x-input-label for="q" :value="__('audit.filters.search')" />
                <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q'] ?? ''" maxlength="100" />
                <x-input-error :messages="$errors->get('q')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="causer_id" :value="__('audit.filters.causer')" />
                <x-select-input id="causer_id" name="causer_id" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($causers as $causer)
                        <option value="{{ $causer->id }}" @selected((int) ($filters['causer_id'] ?? 0) === $causer->id)>{{ $causer->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('causer_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="event" :value="__('audit.filters.event')" />
                <x-select-input id="event" name="event" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($events as $event)
                        <option value="{{ $event }}" @selected(($filters['event'] ?? null) === $event)>{{ \Illuminate\Support\Facades\Lang::has('audit.events.'.$event) ? __('audit.events.'.$event) : $event }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('event')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="subject" :value="__('audit.filters.subject')" />
                <x-select-input id="subject" name="subject" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($subjectTypes as $subjectType)
                        <option value="{{ $subjectType }}" @selected(($filters['subject'] ?? null) === $subjectType)>{{ __('audit.subjects.'.$subjectType) }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('subject')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="from" :value="__('audit.filters.from')" />
                <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="$filters['from'] ?? ''" />
                <x-input-error :messages="$errors->get('from')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="to" :value="__('audit.filters.to')" />
                <x-text-input id="to" name="to" type="date" class="mt-1 block w-full" :value="$filters['to'] ?? ''" />
                <x-input-error :messages="$errors->get('to')" class="mt-1" />
            </div>

            <div class="flex items-end gap-2 sm:col-span-2">
                <x-primary-button>{{ __('common.actions.filter') }}</x-primary-button>
                <x-text-link :href="route('audit.index')">{{ __('common.actions.clear') }}</x-text-link>
            </div>
        </form>
    </x-card>

    @if ($rows->isEmpty())
        <x-card>
            <p class="text-sm text-gray-700">{{ __('audit.empty') }}</p>
        </x-card>
    @else
        <x-table>
            <thead>
                <tr>
                    <th scope="col" class="px-3 py-2">{{ __('audit.columns.when') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('audit.columns.event') }}</th>
                    <th scope="col" class="hidden px-3 py-2 md:table-cell">{{ __('audit.columns.who') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('audit.columns.subject') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('audit.columns.details') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-green/10 align-top">
                @foreach ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2"><x-local-datetime :value="$row['when']" /></td>
                        <td class="px-3 py-2">
                            <span class="font-medium">{{ $row['event_label'] }}</span>
                            <span class="block text-xs text-gray-600">{{ $row['log_name'] }}</span>
                            <span class="mt-1 block text-xs text-gray-700 md:hidden">{{ $row['causer'] ?? __('audit.system') }}</span>
                        </td>
                        <td class="hidden px-3 py-2 md:table-cell">{{ $row['causer'] ?? __('audit.system') }}</td>
                        <td class="px-3 py-2">
                            @if ($row['subject_type'] !== null)
                                <span class="block text-xs text-gray-600">{{ __('audit.subjects.'.$row['subject_type']) }}</span>
                            @endif
                            <span>{{ $row['subject'] ?? __('audit.no_subject') }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <details>
                                <summary class="flex min-h-[44px] cursor-pointer items-center text-sm font-medium text-brand-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
                                    {{ __('audit.details.toggle') }}
                                </summary>

                                <div class="mt-1 space-y-3 text-xs">
                                    <p class="text-gray-700">{{ $row['description'] }}</p>

                                    @if ($row['changes'] !== [])
                                        <div>
                                            <p class="font-semibold text-gray-800">{{ __('audit.details.changes') }}</p>
                                            <dl class="mt-1 space-y-1">
                                                @foreach ($row['changes'] as $change)
                                                    <div class="break-words">
                                                        <dt class="inline font-medium">{{ $change['field'] }}:</dt>
                                                        <dd class="inline">
                                                            <span class="text-gray-600">{{ $change['old'] }}</span>
                                                            <span aria-hidden="true">→</span>
                                                            <span class="sr-only">{{ __('audit.details.new') }}:</span>
                                                            <span class="font-medium">{{ $change['new'] }}</span>
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    @endif

                                    @if ($row['details'] !== [])
                                        <div>
                                            <p class="font-semibold text-gray-800">{{ __('audit.details.data') }}</p>
                                            <dl class="mt-1 space-y-1">
                                                @foreach ($row['details'] as $detail)
                                                    <div class="break-words">
                                                        <dt class="inline font-medium">{{ $detail['key'] }}:</dt>
                                                        <dd class="inline">{{ $detail['value'] }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    @endif

                                    @if ($row['changes'] === [] && $row['details'] === [])
                                        <p class="text-gray-600">{{ __('audit.details.none') }}</p>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>

        {{ $entries->links() }}
    @endif
</x-app-layout>
