{{-- Un grupo «Edificio › Coordinación» de casillas de equipos (lo usa team-checkboxes). --}}
<div>
    <p class="text-xs font-semibold uppercase tracking-wide text-brand-green">{{ $group->first()->coordination->station->name }} › {{ $group->first()->coordination->name }}</p>
    <div class="mt-1 grid gap-1 sm:grid-cols-2">
        @foreach ($group as $team)
            <label for="{{ $prefix }}_{{ $team->id }}" class="flex min-h-[44px] items-center gap-2 rounded-md px-2 text-sm text-gray-800 hover:bg-brand-teal/5">
                <input
                    id="{{ $prefix }}_{{ $team->id }}"
                    type="checkbox"
                    name="team_ids[]"
                    value="{{ $team->id }}"
                    class="h-4 w-4 rounded border-gray-300 text-brand-green focus:ring-brand-teal"
                    @checked(in_array((int) $team->id, $selectedIds, true))
                >
                <span>{{ $team->name }}</span>
            </label>
        @endforeach
    </div>
</div>
