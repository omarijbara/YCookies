<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="fi-form-actions mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check" size="lg">
                Save All Settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>


