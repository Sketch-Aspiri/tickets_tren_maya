@props(['value' => null])

{{-- Se guarda en UTC y se muestra en la zona horaria local (config app.display_timezone). --}}
@if ($value)
    <time datetime="{{ $value->toIso8601String() }}" {{ $attributes }}>{{ $value->copy()->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</time>
@else
    <span {{ $attributes }}>{{ __('common.none') }}</span>
@endif
