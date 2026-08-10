<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <!-- Config Form Elements (Handled by Filament Grid/Fieldsets implicitly above this component) -->

    <!-- LIVE Preview Iframe (Right Column) -->
    <div class="border rounded-xl overflow-hidden shadow-lg bg-gray-50 h-[600px] sticky top-6">
        <iframe
            src="{{ $record ? route('ycookies.preview', ['site_id' => $record->site_id]) : '#' }}"
            class="w-full h-full border-0"
            title="Live Banner Preview"
            id="ycookies-preview-iframe">
        </iframe>
    </div>
</div>

<script>
    // Livewire Hook: Automatically refresh the right-hand iframe whenever Filament form states change and save.
    document.addEventListener('livewire:initialized', () => {
        Livewire.hook('morph.updated', ({
            component,
            el
        }) => {
            const iframe = document.getElementById('ycookies-preview-iframe');
            if (iframe) {
                // To keep it smooth we could postMessage, but a reload is 100% accurate to the new state
                iframe.contentWindow.location.reload();
            }
        });
    });
</script>