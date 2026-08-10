<?php

namespace App\Services;

class ContentBlockerTemplates
{
    /**
     * All available design templates for the ContentBlocker form dropdown.
     * Each template pre-fills display_mode, floating_position, html_code, css_code, js_code.
     */
    public static function getTemplates(): array
    {
        return [
            'inline-default' => [
                'label' => 'Standard Inline Blocker',
                'display_mode' => 'inline',
                'floating_position' => null,
                'html_code' => static::inlineDefaultHtml(),
                'css_code' => static::inlineDefaultCss(),
                'js_code' => '',
            ],
            'floating-chat' => [
                'label' => 'Floating Chat Icon with Popup',
                'display_mode' => 'floating',
                'floating_position' => 'bottom-right',
                'html_code' => static::floatingChatHtml(),
                'css_code' => static::floatingChatCss(),
                'js_code' => '',
            ],
            'floating-notification' => [
                'label' => 'Floating Notification Badge',
                'display_mode' => 'floating',
                'floating_position' => 'bottom-right',
                'html_code' => static::floatingNotificationHtml(),
                'css_code' => static::floatingNotificationCss(),
                'js_code' => '',
            ],
            'floating-cookie' => [
                'label' => 'Floating Shield / Cookie Icon',
                'display_mode' => 'floating',
                'floating_position' => 'bottom-left',
                'html_code' => static::floatingCookieHtml(),
                'css_code' => static::floatingCookieCss(),
                'js_code' => '',
            ],
        ];
    }

    public static function getTemplate(string $key): ?array
    {
        return static::getTemplates()[$key] ?? null;
    }

    public static function getOptions(): array
    {
        $options = ['' => 'Custom (manual)'];
        foreach (static::getTemplates() as $key => $tpl) {
            $options[$key] = $tpl['label'];
        }

        return $options;
    }

    // ── Inline Default ──────────────────────────────────────────────

    private static function inlineDefaultHtml(): string
    {
        return <<<'HTML'
<div class="yc-cb-inline">
    <div class="yc-cb-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/>
            <line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/>
            <line x1="2" y1="12" x2="22" y2="12"/>
        </svg>
    </div>
    <p class="yc-cb-title">{{name}} content blocked</p>
    <p class="yc-cb-desc">Loading this content may share data with {{name}}. Please accept to continue.</p>
    <div class="yc-cb-actions">
        <button class="yc-unblock-btn yc-cb-btn-primary">Load content</button>
    </div>
</div>
HTML;
    }

    private static function inlineDefaultCss(): string
    {
        return <<<'CSS'
.yc-cb-inline{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:32px 24px;text-align:center;font-family:system-ui,-apple-system,sans-serif}
.yc-cb-icon{width:48px;height:48px;border-radius:50%;background:#1e293b;display:flex;align-items:center;justify-content:center;color:#64748b}
.yc-cb-title{font-weight:600;font-size:16px;margin:0;color:#e2e8f0}
.yc-cb-desc{font-size:13px;margin:0;color:#94a3b8;max-width:400px;line-height:1.5}
.yc-cb-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:4px}
.yc-cb-btn-primary{background:#3b82f6;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer}
.yc-cb-btn-primary:hover{background:#2563eb}
CSS;
    }

    // ── Floating Chat ───────────────────────────────────────────────

    private static function floatingChatHtml(): string
    {
        return <<<'HTML'
<div class="yc-float-trigger" data-yc-float-toggle title="{{name}}">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
    </svg>
</div>
<div class="yc-float-popup yc-float-hidden">
    <div class="yc-float-popup-header">
        <span class="yc-float-popup-title">{{name}}</span>
        <button class="yc-float-popup-close" data-yc-float-toggle>&times;</button>
    </div>
    <div class="yc-float-popup-body">
        <p>This feature uses <strong>{{name}}</strong> and may share data with the provider.</p>
        <p class="yc-float-popup-privacy"><a href="{{privacy_policy_url}}" target="_blank" rel="noopener">Privacy Policy</a></p>
    </div>
    <div class="yc-float-popup-actions">
        <button class="yc-unblock-btn yc-float-btn-allow">Allow {{name}}</button>
    </div>
</div>
HTML;
    }

    private static function floatingChatCss(): string
    {
        return <<<'CSS'
.yc-float-trigger{width:56px;height:56px;border-radius:50%;background:#3b82f6;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);transition:transform .2s,background .2s}
.yc-float-trigger:hover{transform:scale(1.08);background:#2563eb}
.yc-float-popup{position:absolute;bottom:68px;right:0;width:320px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);overflow:hidden;font-family:system-ui,-apple-system,sans-serif;animation:ycFloatIn .2s ease}
.yc-float-hidden{display:none!important}
.yc-float-popup-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.yc-float-popup-title{font-weight:600;font-size:15px;color:#1e293b}
.yc-float-popup-close{background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0 4px}
.yc-float-popup-close:hover{color:#1e293b}
.yc-float-popup-body{padding:16px;font-size:14px;color:#475569;line-height:1.5}
.yc-float-popup-body p{margin:0 0 8px}
.yc-float-popup-privacy a{color:#3b82f6;text-decoration:none;font-size:13px}
.yc-float-popup-privacy a:hover{text-decoration:underline}
.yc-float-popup-actions{padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0}
.yc-float-btn-allow{width:100%;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.yc-float-btn-allow:hover{background:#2563eb}
@keyframes ycFloatIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
CSS;
    }

    // ── Floating Notification ───────────────────────────────────────

    private static function floatingNotificationHtml(): string
    {
        return <<<'HTML'
<div class="yc-float-trigger yc-float-notif-trigger" data-yc-float-toggle title="{{name}}">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <span class="yc-float-notif-dot"></span>
</div>
<div class="yc-float-popup yc-float-hidden">
    <div class="yc-float-popup-header">
        <span class="yc-float-popup-title">{{name}}</span>
        <button class="yc-float-popup-close" data-yc-float-toggle>&times;</button>
    </div>
    <div class="yc-float-popup-body">
        <p><strong>{{name}}</strong> is blocked. Allow it to enable this feature.</p>
    </div>
    <div class="yc-float-popup-actions">
        <button class="yc-unblock-btn yc-float-btn-allow">Allow {{name}}</button>
    </div>
</div>
HTML;
    }

    private static function floatingNotificationCss(): string
    {
        return <<<'CSS'
.yc-float-notif-trigger{width:52px;height:52px;border-radius:50%;background:#1e293b;color:#e2e8f0;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);position:relative;transition:transform .2s}
.yc-float-notif-trigger:hover{transform:scale(1.08)}
.yc-float-notif-dot{position:absolute;top:2px;right:2px;width:12px;height:12px;border-radius:50%;background:#ef4444;border:2px solid #1e293b}
.yc-float-popup{position:absolute;bottom:64px;right:0;width:300px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);overflow:hidden;font-family:system-ui,-apple-system,sans-serif;animation:ycFloatIn .2s ease}
.yc-float-hidden{display:none!important}
.yc-float-popup-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.yc-float-popup-title{font-weight:600;font-size:15px;color:#1e293b}
.yc-float-popup-close{background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0 4px}
.yc-float-popup-body{padding:16px;font-size:14px;color:#475569;line-height:1.5}
.yc-float-popup-body p{margin:0}
.yc-float-popup-actions{padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0}
.yc-float-btn-allow{width:100%;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
.yc-float-btn-allow:hover{background:#2563eb}
@keyframes ycFloatIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
CSS;
    }

    // ── Floating Cookie / Shield ────────────────────────────────────

    private static function floatingCookieHtml(): string
    {
        return <<<'HTML'
<div class="yc-float-trigger yc-float-shield-trigger" data-yc-float-toggle title="{{name}} — blocked">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
</div>
<div class="yc-float-popup yc-float-hidden">
    <div class="yc-float-popup-header">
        <span class="yc-float-popup-title">{{name}}</span>
        <button class="yc-float-popup-close" data-yc-float-toggle>&times;</button>
    </div>
    <div class="yc-float-popup-body">
        <p><strong>{{name}}</strong> is currently blocked for your privacy. Allow it to use this feature.</p>
        <p class="yc-float-popup-privacy"><a href="{{privacy_policy_url}}" target="_blank" rel="noopener">Privacy Policy</a></p>
    </div>
    <div class="yc-float-popup-actions">
        <button class="yc-unblock-btn yc-float-btn-allow">Allow {{name}}</button>
    </div>
</div>
HTML;
    }

    private static function floatingCookieCss(): string
    {
        return <<<'CSS'
.yc-float-shield-trigger{width:52px;height:52px;border-radius:50%;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);transition:transform .2s}
.yc-float-shield-trigger:hover{transform:scale(1.08);background:#047857}
.yc-float-popup{position:absolute;bottom:64px;left:0;width:300px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);overflow:hidden;font-family:system-ui,-apple-system,sans-serif;animation:ycFloatIn .2s ease}
.yc-float-hidden{display:none!important}
.yc-float-popup-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f0fdf4;border-bottom:1px solid #bbf7d0}
.yc-float-popup-title{font-weight:600;font-size:15px;color:#166534}
.yc-float-popup-close{background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0 4px}
.yc-float-popup-body{padding:16px;font-size:14px;color:#475569;line-height:1.5}
.yc-float-popup-body p{margin:0 0 8px}
.yc-float-popup-privacy a{color:#059669;text-decoration:none;font-size:13px}
.yc-float-popup-actions{padding:12px 16px;background:#f0fdf4;border-top:1px solid #bbf7d0}
.yc-float-btn-allow{width:100%;padding:10px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
.yc-float-btn-allow:hover{background:#047857}
@keyframes ycFloatIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
CSS;
    }
}
