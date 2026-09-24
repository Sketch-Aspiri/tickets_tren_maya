<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-[44px] items-center justify-center rounded-md border border-brand-teal bg-white px-5 py-2 text-sm font-semibold tracking-wide text-brand-green shadow-sm transition-colors hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 disabled:opacity-50 motion-reduce:transition-none']) }}>
    {{ $slot }}
</button>
