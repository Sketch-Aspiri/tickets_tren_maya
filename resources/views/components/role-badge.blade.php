@props(['role' => null])

@if ($role instanceof \App\Enums\UserRole)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-brand-green ring-1 ring-inset ring-brand-teal/50']) }}>
        {{ $role->label() }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-sm text-gray-500']) }}>{{ __('users.index.no_role') }}</span>
@endif
