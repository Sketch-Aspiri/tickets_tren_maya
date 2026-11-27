@php $caption ??= __('users.title'); @endphp
<x-table :caption="$caption">
    <thead>
        <tr>
            <th scope="col" class="px-4 py-3">{{ __('users.index.columns.name') }}</th>
            <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('users.index.columns.role') }}</th>
            <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('users.index.columns.station') }}</th>
            <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('users.index.columns.coordination') }}</th>
            <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('users.index.columns.teams') }}</th>
            <th scope="col" class="px-4 py-3">{{ __('users.index.columns.status') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-brand-green/10">
        @forelse ($users as $listedUser)
            @php $places = $listedUser->workplaces(); @endphp
            <tr>
                <td class="px-4 py-3">
                    @can('view', $listedUser)
                        <a href="{{ route('users.show', $listedUser) }}" class="font-medium text-brand-green hover:underline">{{ $listedUser->name }}</a>
                    @else
                        <span class="font-medium text-gray-900">{{ $listedUser->name }}</span>
                    @endcan
                    <p class="max-w-[8rem] truncate text-xs text-gray-500 sm:max-w-xs">{{ $listedUser->email }}</p>
                    <div class="mt-1 space-y-0.5 text-xs text-gray-700">
                        <span class="block md:hidden"><span class="font-medium">{{ __('users.index.columns.role') }}:</span> <x-role-badge :role="$listedUser->roleEnum()" /></span>
                        <span class="block md:hidden"><span class="font-medium">{{ __('users.index.columns.station') }}:</span> {{ $places->pluck('station.name')->unique()->implode(', ') ?: '—' }}</span>
                        <span class="block md:hidden"><span class="font-medium">{{ __('users.index.columns.coordination') }}:</span> {{ $places->pluck('name')->implode(', ') ?: '—' }}</span>
                        <span class="block lg:hidden"><span class="font-medium">{{ __('users.index.columns.teams') }}:</span> {{ $listedUser->teams->isEmpty() ? __('users.index.no_team') : $listedUser->teams->pluck('name')->sort()->implode(', ') }}</span>
                    </div>
                </td>
                <td class="hidden px-4 py-3 md:table-cell"><x-role-badge :role="$listedUser->roleEnum()" /></td>
                <td class="hidden px-4 py-3 md:table-cell">{{ $places->pluck('station.name')->unique()->implode(', ') ?: '—' }}</td>
                <td class="hidden px-4 py-3 md:table-cell">{{ $places->pluck('name')->implode(', ') ?: '—' }}</td>
                <td class="hidden px-4 py-3 lg:table-cell">{{ $listedUser->teams->isEmpty() ? __('users.index.no_team') : $listedUser->teams->pluck('name')->sort()->implode(', ') }}</td>
                <td class="px-4 py-3"><x-status-badge :status="$listedUser->status" /></td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-0"><x-empty-state :title="__('common.empty')" /></td></tr>
        @endforelse
    </tbody>
</x-table>
{{ $users->links() }}
