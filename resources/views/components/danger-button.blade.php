<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-[44px] items-center justify-center rounded-md border border-transparent bg-red-700 px-5 py-2 text-sm font-semibold tracking-wide text-white shadow-sm transition-colors hover:bg-red-800 active:bg-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2 disabled:opacity-50 motion-reduce:transition-none']) }}>
    {{ $slot }}
</button>
