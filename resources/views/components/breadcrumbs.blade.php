@props([
    'breadcrumbs',
])

<div {{ $attributes->class(['filament-breadcrumbs flex-1']) }}>
    <ul
        @class([
            'flex flex-row items-center gap-4 text-sm font-medium',
            'dark:text-white' => config('filament.dark_mode'),
        ])
    >
        @foreach ($breadcrumbs as ['label' => $label, 'action' => $action, 'disabled' => $disabled])
            <li>
                <button
                    @unless ($disabled)
                        type="button"
                        wire:click="{{ $action }}"
                    @endunless
                    @class([
                        'text-gray-500' => $loop->last && (! $loop->first),
                        'dark:text-gray-300' => ((! $loop->last) || $loop->first) && config('filament.dark_mode'),
                        'dark:text-gray-400' => $loop->last && (! $loop->first) && config('filament.dark_mode'),
                        'cursor-not-allowed' => $disabled,
                    ])
                >
                    {{ $label }}
                </button>
            </li>

            @if (! $loop->last)
                <li
                    @class([
                        'h-6 -skew-x-12 border-r border-gray-300',
                        'dark:border-gray-500' => config('filament.dark_mode'),
                    ])
                ></li>
            @endif
        @endforeach
    </ul>
</div>
