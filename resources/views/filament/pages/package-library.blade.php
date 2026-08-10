<x-filament-panels::page>
    @php
        $colorMap = [
            'primary' => ['from' => '#f59e0b', 'to' => '#d97706'],
            'danger'  => ['from' => '#ef4444', 'to' => '#b91c1c'],
            'warning' => ['from' => '#f59e0b', 'to' => '#d97706'],
            'success' => ['from' => '#22c55e', 'to' => '#15803d'],
            'info'    => ['from' => '#3b82f6', 'to' => '#1d4ed8'],
            'gray'    => ['from' => '#6b7280', 'to' => '#374151'],
            'blue'    => ['from' => '#3b82f6', 'to' => '#1e40af'],
            'indigo'  => ['from' => '#6366f1', 'to' => '#3730a3'],
            'violet'  => ['from' => '#8b5cf6', 'to' => '#5b21b6'],
            'purple'  => ['from' => '#a855f7', 'to' => '#7e22ce'],
            'pink'    => ['from' => '#ec4899', 'to' => '#be185d'],
            'rose'    => ['from' => '#f43f5e', 'to' => '#be123c'],
            'red'     => ['from' => '#ef4444', 'to' => '#b91c1c'],
            'orange'  => ['from' => '#f97316', 'to' => '#c2410c'],
            'amber'   => ['from' => '#f59e0b', 'to' => '#b45309'],
            'yellow'  => ['from' => '#eab308', 'to' => '#a16207'],
            'lime'    => ['from' => '#84cc16', 'to' => '#4d7c0f'],
            'green'   => ['from' => '#22c55e', 'to' => '#15803d'],
            'emerald' => ['from' => '#10b981', 'to' => '#047857'],
            'teal'    => ['from' => '#14b8a6', 'to' => '#0f766e'],
            'cyan'    => ['from' => '#06b6d4', 'to' => '#0e7490'],
            'sky'     => ['from' => '#0ea5e9', 'to' => '#0369a1'],
        ];

        $typeLabels = [
            'service' => 'Service',
            'script_blocker' => 'Script Blocker',
            'content_blocker' => 'Content Blocker',
            'style_blocker' => 'Style Blocker',
        ];

        $filterCounts = $this->getFilterCounts();
        $templates = $this->getFilteredTemplates();
        $updateCount = $this->getUpdateCount();
    @endphp

    <style>
        .pkg-filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
        }
        .pkg-filter-tabs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }
        .pkg-filter-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1.5px solid transparent;
            white-space: nowrap;
            outline: none;
        }
        .pkg-filter-btn:focus-visible {
            box-shadow: 0 0 0 2px rgba(59,130,246,0.4);
        }
        .pkg-filter-btn--active {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 8px rgba(37,99,235,0.3);
        }
        .pkg-filter-btn--inactive {
            background: rgba(255,255,255,0.05);
            color: rgba(255,255,255,0.7);
            border-color: rgba(255,255,255,0.1);
        }
        .pkg-filter-btn--inactive:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-color: rgba(255,255,255,0.2);
        }
        /* Light mode overrides */
        :is(.fi-body:not(.dark)) .pkg-filter-btn--inactive {
            background: #fff;
            color: #4b5563;
            border-color: #e5e7eb;
        }
        :is(.fi-body:not(.dark)) .pkg-filter-btn--inactive:hover {
            background: #f3f4f6;
            color: #111827;
            border-color: #d1d5db;
        }
        .pkg-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .pkg-count--active {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }
        .pkg-count--inactive {
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.5);
        }
        :is(.fi-body:not(.dark)) .pkg-count--inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .pkg-search {
            position: relative;
            width: 280px;
            flex-shrink: 0;
        }
        .pkg-search__icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: rgba(255,255,255,0.35);
            pointer-events: none;
        }
        :is(.fi-body:not(.dark)) .pkg-search__icon { color: #9ca3af; }
        .pkg-search__input {
            width: 100%;
            padding: 9px 16px 9px 38px;
            border-radius: 10px;
            border: 1.5px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
            color: #e5e7eb;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
        }
        .pkg-search__input::placeholder { color: rgba(255,255,255,0.35); }
        .pkg-search__input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
            background: rgba(255,255,255,0.08);
        }
        :is(.fi-body:not(.dark)) .pkg-search__input {
            border-color: #e5e7eb;
            background: #fff;
            color: #111827;
        }
        :is(.fi-body:not(.dark)) .pkg-search__input::placeholder { color: #9ca3af; }
        :is(.fi-body:not(.dark)) .pkg-search__input:focus {
            border-color: #3b82f6;
            background: #fff;
        }

        /* Card Styles */
        .pkg-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .pkg-card {
            display: flex;
            flex-direction: column;
            border-radius: 14px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }
        :is(.fi-body:not(.dark)) .pkg-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .pkg-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
            border-color: rgba(255,255,255,0.15);
        }
        :is(.fi-body:not(.dark)) .pkg-card:hover {
            box-shadow: 0 12px 40px rgba(0,0,0,0.1);
            border-color: #d1d5db;
        }
        .pkg-card--installed {
            border-color: rgba(34,197,94,0.3) !important;
        }
        :is(.fi-body:not(.dark)) .pkg-card--installed {
            border-color: rgba(34,197,94,0.4) !important;
        }
        .pkg-card__header {
            position: relative;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .pkg-card__circle {
            position: absolute;
            border-radius: 50%;
            border: 1.5px solid rgba(255,255,255,0.08);
        }
        .pkg-card__badge {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            background: rgba(0,0,0,0.3);
            color: rgba(255,255,255,0.9);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .pkg-card__installed-check {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 5;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #22c55e;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(34,197,94,0.4);
        }
        .pkg-card__icon-wrap {
            position: relative;
            z-index: 3;
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .pkg-card:hover .pkg-card__icon-wrap {
            transform: scale(1.1);
        }
        .pkg-card__body {
            flex: 1;
            padding: 16px 18px 12px;
        }
        .pkg-card__name {
            font-size: 15px;
            font-weight: 700;
            color: #f3f4f6;
            line-height: 1.3;
            margin: 0;
        }
        :is(.fi-body:not(.dark)) .pkg-card__name { color: #111827; }
        .pkg-card__provider {
            font-size: 12px;
            font-weight: 500;
            color: rgba(255,255,255,0.45);
            margin-top: 2px;
        }
        :is(.fi-body:not(.dark)) .pkg-card__provider { color: #6b7280; }
        .pkg-card__desc {
            font-size: 13px;
            line-height: 1.5;
            color: rgba(255,255,255,0.6);
            margin-top: 10px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        :is(.fi-body:not(.dark)) .pkg-card__desc { color: #6b7280; }

        .pkg-card__meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-top: 1px solid rgba(255,255,255,0.06);
            text-align: center;
        }
        :is(.fi-body:not(.dark)) .pkg-card__meta { border-top-color: #f3f4f6; }
        .pkg-card__meta-item {
            padding: 10px 12px;
        }
        .pkg-card__meta-item:first-child {
            border-right: 1px solid rgba(255,255,255,0.06);
        }
        :is(.fi-body:not(.dark)) .pkg-card__meta-item:first-child { border-right-color: #f3f4f6; }
        .pkg-card__meta-label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255,255,255,0.3);
        }
        :is(.fi-body:not(.dark)) .pkg-card__meta-label { color: #9ca3af; }
        .pkg-card__meta-value {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-top: 2px;
            color: rgba(255,255,255,0.8);
        }
        :is(.fi-body:not(.dark)) .pkg-card__meta-value { color: #374151; }
        .pkg-card__meta-value--service { color: #60a5fa; }
        .pkg-card__meta-value--script_blocker { color: #fbbf24; }
        .pkg-card__meta-value--content_blocker { color: #c084fc; }
        .pkg-card__meta-value--style_blocker { color: #2dd4bf; }

        .pkg-card__action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 11px 0;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            color: #fff;
            outline: none;
        }
        .pkg-card__action:hover { filter: brightness(1.15); }
        .pkg-card__action--installed {
            background: rgba(34,197,94,0.1);
            color: #22c55e;
            border-top: 1px solid rgba(34,197,94,0.15);
            cursor: default;
        }
        :is(.fi-body:not(.dark)) .pkg-card__action--installed {
            background: #f0fdf4;
            border-top-color: #bbf7d0;
        }
        .pkg-card__action--update {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            border-top: none;
        }
        .pkg-card__action--update:hover {
            filter: brightness(1.1);
        }

        /* Update notification bar */
        .pkg-update-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(251,191,36,0.08), rgba(245,158,11,0.12));
            border: 1px solid rgba(251,191,36,0.25);
            margin-bottom: 20px;
        }
        :is(.fi-body:not(.dark)) .pkg-update-bar {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border-color: #fde68a;
        }
        .pkg-update-bar__icon {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(245,158,11,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        :is(.fi-body:not(.dark)) .pkg-update-bar__icon { background: rgba(245,158,11,0.12); }
        .pkg-update-bar__text {
            flex: 1;
            font-size: 13px;
            font-weight: 600;
            color: #fbbf24;
        }
        :is(.fi-body:not(.dark)) .pkg-update-bar__text { color: #92400e; }
        .pkg-update-bar__text strong { font-weight: 800; }

        /* Update badge on card header */
        .pkg-card__update-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            box-shadow: 0 2px 8px rgba(245,158,11,0.4);
            animation: pkg-pulse 2s ease-in-out infinite;
        }
        @keyframes pkg-pulse {
            0%, 100% { box-shadow: 0 2px 8px rgba(245,158,11,0.4); }
            50% { box-shadow: 0 2px 16px rgba(245,158,11,0.7); }
        }

        /* Version comparison in metadata */
        .pkg-version-update {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .pkg-version-old {
            text-decoration: line-through;
            opacity: 0.5;
            font-size: 11px;
        }
        .pkg-version-arrow {
            color: #f59e0b;
            font-size: 10px;
        }
        .pkg-version-new {
            color: #f59e0b;
            font-weight: 800;
        }

        /* Empty state */
        .pkg-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            border-radius: 14px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.1);
        }
        :is(.fi-body:not(.dark)) .pkg-empty {
            background: #fafafa;
            border-color: #e5e7eb;
        }
        .pkg-empty__icon { width: 48px; height: 48px; color: rgba(255,255,255,0.15); margin-bottom: 12px; }
        :is(.fi-body:not(.dark)) .pkg-empty__icon { color: #d1d5db; }
        .pkg-empty__title { font-size: 16px; font-weight: 600; color: rgba(255,255,255,0.5); }
        :is(.fi-body:not(.dark)) .pkg-empty__title { color: #6b7280; }
        .pkg-empty__desc { font-size: 13px; color: rgba(255,255,255,0.3); margin-top: 4px; }
        :is(.fi-body:not(.dark)) .pkg-empty__desc { color: #9ca3af; }

        @media (max-width: 640px) {
            .pkg-filter-bar { flex-direction: column; align-items: stretch; }
            .pkg-search { width: 100%; }
            .pkg-grid { grid-template-columns: 1fr; }
        }
    </style>

    {{-- ════════════════════════════════════════════
         FILTER BAR + SEARCH
         ════════════════════════════════════════════ --}}
    <div class="pkg-filter-bar">
        <div class="pkg-filter-tabs">
            @php
                $filters = [
                    'all' => ['label' => 'All', 'icon' => 'heroicon-o-squares-2x2'],
                    'service' => ['label' => 'Services', 'icon' => 'heroicon-o-cube'],
                    'content_blocker' => ['label' => 'Content Blocker', 'icon' => 'heroicon-o-film'],
                    'script_blocker' => ['label' => 'Script Blocker', 'icon' => 'heroicon-o-code-bracket'],
                    'style_blocker' => ['label' => 'Style Blocker', 'icon' => 'heroicon-o-paint-brush'],
                    'installed' => ['label' => 'Installed', 'icon' => 'heroicon-o-check-badge'],
                ];
            @endphp

            @foreach($filters as $filterKey => $filter)
                <button
                    wire:click="setFilter('{{ $filterKey }}')"
                    class="pkg-filter-btn {{ $activeFilter === $filterKey ? 'pkg-filter-btn--active' : 'pkg-filter-btn--inactive' }}"
                >
                    <x-filament::icon :icon="$filter['icon']" style="width:15px;height:15px;" />
                    {{ $filter['label'] }}
                    <span class="pkg-count {{ $activeFilter === $filterKey ? 'pkg-count--active' : 'pkg-count--inactive' }}">
                        {{ $filterCounts[$filterKey] ?? 0 }}
                    </span>
                </button>
            @endforeach
        </div>

        <div class="pkg-search">
            <svg class="pkg-search__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search packages..."
                class="pkg-search__input"
            />
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         UPDATE NOTIFICATION BAR
         ════════════════════════════════════════════ --}}
    @if($updateCount > 0)
        <div class="pkg-update-bar">
            <div class="pkg-update-bar__icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/>
                </svg>
            </div>
            <div class="pkg-update-bar__text">
                <strong>{{ $updateCount }}</strong> {{ $updateCount === 1 ? 'package has' : 'packages have' }} updates available. Review and update to get the latest templates.
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════════════
         EMPTY STATE
         ════════════════════════════════════════════ --}}
    @if(count($templates) === 0)
        <div class="pkg-empty">
            <svg class="pkg-empty__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <h3 class="pkg-empty__title">No packages found</h3>
            <p class="pkg-empty__desc">
                @if($search)
                    No results for "{{ $search }}". Try a different search term.
                @else
                    No packages available for this filter.
                @endif
            </p>
        </div>
    @endif

    {{-- ════════════════════════════════════════════
         PACKAGE GRID
         ════════════════════════════════════════════ --}}
    <div class="pkg-grid">
        @foreach($templates as $key => $template)
            @php
                $color = $template['color'] ?? 'primary';
                $gradientFrom = $colorMap[$color]['from'] ?? $colorMap['primary']['from'];
                $gradientTo   = $colorMap[$color]['to']   ?? $colorMap['primary']['to'];
                $type = $template['type'] ?? 'service';
                $isInstalled = $this->isInstalled($template['key']);
                $packageHasUpdate = $isInstalled && $this->hasUpdate($template['key']);
                $installedVersion = $isInstalled ? $this->getInstalledVersion($template['key']) : null;
            @endphp

            <div class="pkg-card {{ $isInstalled ? 'pkg-card--installed' : '' }}">
                {{-- Gradient Header --}}
                <div class="pkg-card__header" style="background: linear-gradient(135deg, {{ $gradientFrom }}, {{ $gradientTo }});">
                    {{-- Decorative circles --}}
                    <div class="pkg-card__circle" style="width:100px;height:100px;right:-30px;top:-30px;"></div>
                    <div class="pkg-card__circle" style="width:70px;height:70px;right:-10px;top:-10px;"></div>
                    <div class="pkg-card__circle" style="width:80px;height:80px;left:-25px;bottom:-25px;"></div>

                    {{-- Type badge --}}
                    <div class="pkg-card__badge">
                        {{ $typeLabels[$type] ?? 'Package' }}
                    </div>

                    {{-- Installed checkmark or Update badge --}}
                    @if($packageHasUpdate)
                        <div class="pkg-card__update-badge">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                            Update
                        </div>
                    @elseif($isInstalled)
                        <div class="pkg-card__installed-check">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                    @endif

                    {{-- Icon --}}
                    <div class="pkg-card__icon-wrap">
                        <x-filament::icon :icon="$template['icon']" style="width:28px;height:28px;color:#fff;filter:drop-shadow(0 1px 2px rgba(0,0,0,0.2));" />
                    </div>
                </div>

                {{-- Body --}}
                <div class="pkg-card__body">
                    <h3 class="pkg-card__name">{{ $template['name'] }}</h3>
                    <p class="pkg-card__provider">{{ $template['provider'] }}</p>
                    <p class="pkg-card__desc">{{ $template['purpose'] }}</p>
                </div>

                {{-- Metadata --}}
                <div class="pkg-card__meta">
                    <div class="pkg-card__meta-item">
                        <span class="pkg-card__meta-label">Type</span>
                        <span class="pkg-card__meta-value pkg-card__meta-value--{{ $type }}">{{ $typeLabels[$type] ?? 'Package' }}</span>
                    </div>
                    <div class="pkg-card__meta-item">
                        <span class="pkg-card__meta-label">Version</span>
                        @if($packageHasUpdate)
                            <span class="pkg-card__meta-value pkg-version-update">
                                <span class="pkg-version-old">{{ $installedVersion }}</span>
                                <span class="pkg-version-arrow">→</span>
                                <span class="pkg-version-new">{{ $template['version'] ?? '1.0.0' }}</span>
                            </span>
                        @else
                            <span class="pkg-card__meta-value">{{ $template['version'] ?? '1.0.0' }}</span>
                        @endif
                    </div>
                </div>

                {{-- Action --}}
                @if($packageHasUpdate)
                    <button
                        wire:click="mountAction('updatePackage', { template: '{{ $key }}' })"
                        class="pkg-card__action pkg-card__action--update"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9M3 12a9 9 0 0 0 9 9m0-18v4m0 14v-4m9-5h-4M3 12h4"/></svg>
                        Update to v{{ $template['version'] ?? '1.0.0' }}
                    </button>
                @elseif($isInstalled)
                    <div class="pkg-card__action pkg-card__action--installed">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Installed
                    </div>
                @else
                    <button
                        wire:click="mountAction('installPackage', { template: '{{ $key }}' })"
                        class="pkg-card__action"
                        style="background: linear-gradient(135deg, {{ $gradientFrom }}, {{ $gradientTo }});"
                    >
                        @switch($type)
                            @case('service')
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Service
                                @break
                            @case('script_blocker')
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Script Blocker
                                @break
                            @case('content_blocker')
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Content Blocker
                                @break
                            @case('style_blocker')
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Add Style Blocker
                                @break
                            @default
                                Install Package
                        @endswitch
                    </button>
                @endif
            </div>
        @endforeach
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
