{{-- Botón "Eliminar vencidos" (tickets y actividades de los equipos que gestiona el usuario). Solo se muestra a quien
     pasa la habilidad `purge-overdue` y si hay algo que eliminar; la barrera real es la habilidad en el servidor. --}}
@can('purge-overdue')
    @php($counts = app(\App\Services\OverduePurgeService::class)->counts(auth()->user()))
    @if ($counts['tickets'] + $counts['activities'] > 0)
        <form method="POST" action="{{ route('overdue.purge') }}" x-data="confirmSubmit" data-confirm="{{ __('overdue.confirm', $counts) }}" x-on:submit="onSubmit">
            @csrf
            <button type="submit" class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-lg border border-red-700 bg-white px-4 py-2 text-sm font-semibold text-red-800 shadow-sm hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2">
                <x-icon name="close" class="h-5 w-5" />
                {{ __('overdue.button', ['count' => $counts['tickets'] + $counts['activities']]) }}
            </button>
        </form>
    @endif
@endcan
