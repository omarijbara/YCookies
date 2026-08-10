<x-filament-panels::page>
    <form wire:submit="openDebugger">
        {{ $this->form }}
    </form>

    @script
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('open-url-in-new-tab', (event) => {
                window.open(event.url, '_blank');
            });
        });
    </script>
    @endscript
</x-filament-panels::page>