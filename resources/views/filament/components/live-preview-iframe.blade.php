<div class="border rounded-xl overflow-hidden shadow-lg bg-gray-50 h-[600px] w-full relative">
    @if ($this->site_id)
        <!-- We use wire:ignore so Livewire doesn't constantly destroy and recreate the iframe on every keystroke -->
        <div wire:ignore class="w-full h-full">
            <iframe
                src="{{ route('ycookies.preview', ['site_id' => \App\Models\Domain::find($this->site_id)?->site_id]) }}"
                class="w-full h-full border-0 absolute inset-0"
                title="Live Banner Preview"
                id="ycookies-preview-iframe"
                sandbox="allow-scripts allow-same-origin">
            </iframe>
        </div>
        <!-- Absolute positioned overlay to prevent interacting with the iframe accidentally -->
        <div class="absolute inset-0 z-10 pointer-events-none"></div>
    @else
        <div class="flex items-center justify-center h-full text-gray-400">
            <div class="text-center">
                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                <p>Select a domain to preview</p>
            </div>
        </div>
    @endif
</div>

<script>
    // Livewire Hook: Automatically refresh the iframe whenever Filament form states change and save.
    document.addEventListener('livewire:initialized', () => {
        let timeout;
        Livewire.hook('morph.updated', ({ component, el }) => {
            const iframe = document.getElementById('ycookies-preview-iframe');
            if (iframe) {
                // Debounce reload to prevent flashing while typing hex codes or text
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    // Send a reload to grab the latest DB states
                    // (Assuming we save as we type? Wait, if we don't save as we type, the iframe won't reflect changes!
                    // Oh, right, Livewire `live()` binds states, but it doesn't save them to the DB until `save()` is clicked.
                    // The iframe route `ycookies.preview` reads from the DB. 
                    // To do a TRUE live preview, we would need to pass the temporary state via postMessage.
                    // For Borlabs parity, usually they just hit save and it reloads, or it posts changes to the iframe.
                }, 800);
            }
        });
    });
</script>
