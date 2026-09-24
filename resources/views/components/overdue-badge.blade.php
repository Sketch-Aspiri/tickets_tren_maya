{{-- "Vencido" es un calculo (Ticket::isOverdue), no un estado. Texto + icono, no solo color. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-900']) }}>
    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    {{ __('tickets.show.overdue') }}
</span>
