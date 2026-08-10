<div class="mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-800 pt-6">
    <div class="flex-1 text-sm text-gray-500 dark:text-gray-400">
        @if(($get('mode') ?? 'test') === 'test')
            <strong>Test Page:</strong> Loads an isolated sandbox page internally. Perfect for safely verifying design without external interference.
        @else
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-globe-alt" class="w-5 h-5 text-danger-500 inline-block shrink-0" />
                <strong>Live Website:</strong> Loads your actual production website. Crucial for verifying real Tag Manager integrations and existing trackers.
            </div>
        @endif
    </div>

    <x-filament::button
        type="submit"
        icon="heroicon-m-play"
        size="lg"
        class="shrink-0">
        Start Debugging Session
    </x-filament::button>
</div>
