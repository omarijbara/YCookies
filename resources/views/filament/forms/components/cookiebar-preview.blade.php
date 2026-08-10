@php
    $cookieBar = $this->record ?? null;
    $domain = $cookieBar ? $cookieBar->domains()->first() : null;
    if (!$domain) {
        $tenant = \Filament\Facades\Filament::getTenant();
        $domain = $tenant ? $tenant->domains()->first() : \App\Models\Domain::first();
    }
    $previewUrl = $domain ? route('ycookies.preview', ['site_id' => $domain->site_id]) : null;
@endphp

<div x-data="{
        formState: $wire.entangle('data').live,
        previewUrl: '{{ $previewUrl }}',
        previewTimeout: null,
        initialized: false,

        getConfigPayload() {
            // Ensure the form state exists
            const rawData = this.formState || {};
            
            return {
                type: 'ycookies_live_preview',
                ui_config: rawData.theme_settings ? JSON.parse(JSON.stringify(rawData.theme_settings)) : {},
                translations: rawData.translations ? JSON.parse(JSON.stringify(rawData.translations)) : {},
            };
        },

        updatePreview() {
            if (!this.initialized) return;

            if (this.previewTimeout) {
                clearTimeout(this.previewTimeout);
            }
            this.previewTimeout = setTimeout(() => {
                const iframe = document.getElementById('ycookies-cookiebar-preview');
                if (!iframe || !iframe.contentWindow) return;
                iframe.contentWindow.postMessage(JSON.stringify(this.getConfigPayload()), '*');
            }, 600); // Debounce typing/rapid updates
        },

        handlePreviewLoad() {
            // Iframe loaded securely with PHP-injected config.
            // Now we can allow Livewire form edits to push postMessage updates.
            setTimeout(() => { 
                this.initialized = true; 
                this.updatePreview();
            }, 500); 
        },

        openInNewWindow() {
            // Store the current config BEFORE opening the window
            sessionStorage.setItem('ycookies_preview_override', JSON.stringify(this.getConfigPayload()));
            window.open(this.previewUrl, '_blank', 'width=1200,height=800');
        }
    }"
    x-init="
        sessionStorage.setItem('ycookies_preview_override', JSON.stringify(getConfigPayload()));
        $watch('formState', () => updatePreview());
    ">

    {{-- Open in new window --}}
    @if ($previewUrl)
        <div style="display: flex; justify-content: flex-end; margin-bottom: 8px;">
            <button
                type="button"
                x-on:click="openInNewWindow()"
                style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 13px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.06); color: #9ca3af; cursor: pointer; transition: all 0.15s;"
                onmouseover="this.style.background='rgba(255,255,255,0.12)'; this.style.color='#e5e7eb'"
                onmouseout="this.style.background='rgba(255,255,255,0.06)'; this.style.color='#9ca3af'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px; height:16px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>
                In neuem Fenster öffnen
            </button>
        </div>
    @endif

    {{-- Preview iframe --}}
    <div wire:ignore id="ycookies-preview-wrapper" style="height: 700px; min-height: 700px; position: relative; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08);">
        @if ($previewUrl)
            <div style="width: 100%; height: 100%;">
                <iframe
                    src="{{ $previewUrl }}"
                    style="width: 100%; height: 100%; border: 0; position: absolute; inset: 0;"
                    title="Live Banner Preview"
                    id="ycookies-cookiebar-preview"
                    x-on:load="handlePreviewLoad()"
                    sandbox="allow-scripts allow-same-origin allow-storage-access-by-user-activation">
                </iframe>
            </div>
        @else
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #6b7280; background: rgba(255,255,255,0.03);">
                <p style="padding: 2rem; text-align: center;">Keine Domain verknüpft — erstellen Sie zuerst eine Domain, um die Vorschau zu sehen.</p>
            </div>
        @endif
    </div>
</div>
