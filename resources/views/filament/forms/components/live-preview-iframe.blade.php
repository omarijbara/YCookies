@php
    $previewUrls = \App\Models\Domain::query()
        ->get(['id', 'site_id'])
        ->mapWithKeys(fn (\App\Models\Domain $domain) => [
            $domain->id => route('ycookies.preview', ['site_id' => $domain->site_id]),
        ]);
@endphp

<div x-data="{
        config: @entangle('ui_config'),
        siteId: @entangle('site_id'),
        previewUrls: @js($previewUrls),
        reloadPreview() {
            const iframe = document.getElementById('ycookies-preview-iframe');
            const nextUrl = this.previewUrls?.[this.siteId];

            if (iframe && nextUrl && iframe.getAttribute('src') !== nextUrl) {
                iframe.setAttribute('src', nextUrl);
            }
        },
        updatePreview() {
            const iframe = document.getElementById('ycookies-preview-iframe');

            if (iframe && iframe.contentWindow) {
                const payload = JSON.stringify({
                    type: 'ycookies_live_preview',
                    ui_config: this.config ? JSON.parse(JSON.stringify(this.config)) : {},
                });

                iframe.contentWindow.postMessage(payload, '*');
            }
        },
        handlePreviewLoad() {
            this.updatePreview();
        }
    }"
    x-init="
        $watch('config', () => updatePreview());
        $watch('siteId', () => reloadPreview());
    "
    class="border rounded-xl overflow-hidden shadow-lg bg-gray-50 w-full relative"
    style="height: 600px; min-height: 600px; position: relative;">
    @if ($this->site_id)
        <!-- We use wire:ignore so Livewire doesn't constantly destroy and recreate the iframe on every keystroke -->
        <div wire:ignore style="width: 100%; height: 100%;">
            <iframe
                src="{{ $previewUrls[$this->site_id] ?? route('ycookies.preview', ['site_id' => \App\Models\Domain::find($this->site_id)?->site_id]) }}"
                style="width: 100%; height: 100%; border: 0; position: absolute; inset: 0;"
                title="Live Banner Preview"
                id="ycookies-preview-iframe"
                x-on:load="handlePreviewLoad()"
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
