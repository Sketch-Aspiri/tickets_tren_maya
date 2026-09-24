@props(['title', 'caption' => null, 'config'])

{{-- Grafica accesible: el <canvas> lo dibuja Chart.js (resources/js/charts.js) a partir de `data-chart` (JSON plano,
     escapado con {{ }}); la MISMA informacion va en una tabla dentro de <details> como alternativa textual. Si el
     JavaScript falla, el texto dentro del <canvas> y la tabla siguen disponibles. --}}
<x-card :title="$title">
    <figure>
        <div class="relative h-64 w-full">
            <canvas data-chart="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}" role="img" aria-label="{{ $title }}">
                {{ __('tracking.charts.unsupported') }}
            </canvas>
        </div>

        @if ($caption)
            <figcaption class="mt-2 text-xs text-gray-600">{{ $caption }}</figcaption>
        @endif
    </figure>

    <details class="mt-3">
        <summary class="flex min-h-[44px] cursor-pointer items-center text-sm font-medium text-brand-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal">
            {{ __('tracking.charts.show_data') }}
        </summary>

        <div class="mt-2">
            <x-table>
                <caption class="sr-only">{{ $title }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="px-3 py-2">{{ __('tracking.charts.point') }}</th>
                        @foreach ($config['datasets'] as $dataset)
                            <th scope="col" class="px-3 py-2 text-right">{{ $dataset['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-green/10">
                    @foreach ($config['labels'] as $index => $label)
                        <tr>
                            <th scope="row" class="px-3 py-2 text-left font-medium">{{ $label }}</th>
                            @foreach ($config['datasets'] as $dataset)
                                <td class="px-3 py-2 text-right tabular-nums">{{ $dataset['data'][$index] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
    </details>
</x-card>
