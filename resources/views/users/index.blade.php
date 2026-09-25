<x-app-layout>
    <x-slot name="title">{{ __('users.title') }}</x-slot>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-brand-green">{{ __('users.title') }}</h1>
    </x-slot>

    <x-card>
        <form method="GET" action="{{ route('users.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="sm:col-span-2 lg:col-span-2">
                <x-input-label for="q" :value="__('users.index.search')" />
                <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q'] ?? ''" maxlength="100" />
            </div>

            <div>
                <x-input-label for="status" :value="__('users.index.filter_status')" />
                <x-select-input id="status" name="status" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="role" :value="__('users.index.filter_role')" />
                <x-select-input id="role" name="role" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(($filters['role'] ?? null) === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div>
                <x-input-label for="team_id" :value="__('users.index.filter_team')" />
                <x-select-input id="team_id" name="team_id" class="mt-1 block w-full">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($teams as $team)
                        <option value="{{ $team->id }}" @selected((int) ($filters['team_id'] ?? 0) === $team->id)>{{ $team->name }}</option>
                    @endforeach
                </x-select-input>
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-5">
                <x-primary-button>{{ __('common.actions.filter') }}</x-primary-button>
                <x-text-link :href="route('users.index')">{{ __('common.actions.clear') }}</x-text-link>
            </div>
        </form>
    </x-card>

    <x-table>
        <thead>
            <tr>
                <th class="px-4 py-3">{{ __('users.index.columns.name') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('users.index.columns.role') }}</th>
                <th class="hidden px-4 py-3 md:table-cell">{{ __('users.index.columns.teams') }}</th>
                <th class="px-4 py-3">{{ __('users.index.columns.status') }}</th>
                <th class="hidden px-4 py-3 lg:table-cell">{{ __('users.index.columns.registered') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ __('common.actions.view') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-brand-green/10">
            @forelse ($users as $listedUser)
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900">{{ $listedUser->name }}</p>
                        <p class="max-w-[6.5rem] truncate text-xs text-gray-500 sm:max-w-xs">{{ $listedUser->email }}</p>
                    </td>
                    <td class="hidden px-4 py-3 md:table-cell"><x-role-badge :role="$listedUser->roleEnum()" /></td>
                    <td class="hidden px-4 py-3 md:table-cell">{{ $listedUser->teams->isEmpty() ? __('users.index.no_team') : $listedUser->teams->pluck('name')->sort()->implode(', ') }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$listedUser->status" /></td>
                    <td class="hidden px-4 py-3 lg:table-cell"><x-local-datetime :value="$listedUser->created_at" /></td>
                    <td class="px-2 py-3 text-right sm:px-4">
                        <a href="{{ route('users.show', $listedUser) }}" class="inline-flex min-h-[44px] items-center px-2 font-medium text-brand-teal hover:text-brand-green hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">{{ __('common.actions.view') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">{{ __('common.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-table>

    {{ $users->links() }}
</x-app-layout>
