<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation Groups
    |--------------------------------------------------------------------------
    */
    'nav' => [
        'workspace' => 'Workspace',
        'agency_workspace' => 'Overview',
        'domains_proxy' => 'Infrastructure',
        'consent_management' => 'Consent',
        'blocker' => 'Consent',
        'settings' => 'Settings',
        'system' => 'Settings',
        'library_scanner' => 'Tools',
        'consent_mgmt' => 'Consent',
        'tools' => 'Tools',
        'consent' => 'Consent',
        'blockers' => 'Blockers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Labels
    |--------------------------------------------------------------------------
    */
    'resources' => [
        'cookie_bars' => 'Cookie Bars',
        'cookie_groups' => 'Cookie Groups',
        'service_groups' => 'Service Groups',
        'services' => 'Services',
        'providers' => 'Providers',
        'domains' => 'Domains',
        'languages' => 'Languages',
        'language_lines' => 'Language Lines',
        'admin_translations' => 'Admin Panel Translation',
        'translation' => 'Translation',
        'content_blockers' => 'Content Blockers',
        'script_blockers' => 'Script & Style Blockers',
        'style_blockers' => 'Style Blockers',
        'consent_logs' => 'Consent Logs',
        'consent_debugger' => 'Consent Debugger',
        'setup_wizard' => 'Setup Wizard',
        'subscription' => 'Subscription',
        'library' => 'Library',
        'scanner' => 'Scanner',
        'dashboard' => 'Dashboard',
        'groups' => 'Groups',
        'group_invitations' => 'Group Invitations',
        'billing_upgrade' => 'Billing Upgrade',
        'manage_subscription' => 'Manage Subscription',
        'upgrade_required' => 'Upgrade Required',
        'webhook_endpoints' => 'Webhooks',
        'webhook_endpoint' => 'Webhook endpoint',
    ],

    'webhook_endpoint' => [
        'section' => 'Outbound webhook',
        'name' => 'Label',
        'url' => 'Endpoint URL',
        'secret' => 'Signing secret',
        'secret_help' => 'Used for HMAC-SHA256 over the raw JSON body (header X-YCookies-Signature). Leave blank when editing to keep the current secret.',
        'events' => 'Events',
        'active' => 'Active',
        'updated' => 'Updated',
    ],

    /*
    |--------------------------------------------------------------------------
    | CookieBar Form
    |--------------------------------------------------------------------------
    */
    'cookie_bar' => [
        'general' => 'General',
        'colors' => 'Colors',
        'layout' => 'Layout',
        'typography' => 'Typography',
        'buttons' => 'Buttons',
        'translations' => 'Translations',
        'name' => 'Name',
        'banner_title' => 'Banner Title',
        'banner_description' => 'Banner Description',
        'cookie_declaration' => 'Cookie Declaration',
        'cross_domain_info' => 'Cross-Domain Info Text',
        'accept_all' => 'Accept All',
        'preferences' => 'Preferences / Settings',
        'save' => 'Save',
        'save_consent' => 'Save Consent',
        'essential_only' => 'Essential Only',
        'imprint_text' => 'Imprint Link Text',
        'imprint_url' => 'Imprint URL',
        'privacy_text' => 'Privacy Link Text',
        'privacy_url' => 'Privacy URL',
        'banner_text' => 'Banner Text',
        'button_labels' => 'Button Labels',
        'legal_links' => 'Legal Links',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Form
    |--------------------------------------------------------------------------
    */
    'service' => [
        'information' => 'Service Information',
        'master_group' => 'Master Group',
        'assigned_domains' => 'Assigned Domains',
        'cookie_group' => 'Cookie Group',
        'provider' => 'Provider',
        'service_name' => 'Service Name',
        'key' => 'Key',
        'sort_order' => 'Sort order',
        'is_active' => 'Is active',
        'cookies' => 'Cookies',
        'purpose' => 'Purpose',
        'additional_settings' => 'Additional Settings',
        'add_cookie' => 'Add Cookie',
        'gtm_id' => 'GTM ID',
        'ga_id' => 'Google Analytics ID',
        'pixel_id' => 'Meta Pixel ID',
        'cache_gtm' => 'Cache GTM Locally',
        'opt_in_code' => 'Opt-in Code (HTML/JS)',
        'opt_out_code' => 'Opt-out Code (HTML/JS)',
        'fallback_code' => 'Fallback Code (HTML/JS)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookie Group Form
    |--------------------------------------------------------------------------
    */
    'cookie_group' => [
        'identity' => 'Group Identity',
        'layout_behavior' => 'Layout & Behavior',
        'group_name' => 'Group Name',
        'description' => 'Description',
        'is_required' => 'Required',
        'is_preselected' => 'Pre-selected',
        'required_help' => 'If checked, users cannot opt-out of this group.',
        'preselected_help' => "If checked, this group's checkbox will be pre-checked when the banner loads. Users can still uncheck it before saving.",
        'usage_on_edit' => 'Usage',
        'usage_on_edit_desc' => 'The overview only shows name, key, and type. Assign domains under “Group identity” below; linked services are summarized here.',
        'linked_services' => 'Linked services',
        'services_count' => '{0} No services linked|{1} :count service uses this group.|[2,*] :count services use this group.',
        'services_none_bar_hint' => 'Until a service is linked, this category may not appear in the cookie bar.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Blocker Form
    |--------------------------------------------------------------------------
    */
    'content_blocker' => [
        'information' => 'Content Blocker Information',
        'name' => 'Name',
        'id' => 'ID',
        'id_help' => 'Unique identifier like "youtube" or "vimeo".',
        'status' => 'Status',
        'privacy_url' => 'Privacy Policy URL',
        'hostnames' => 'Hostnames',
        'hostnames_help' => 'Domains that trigger this blocker (e.g. youtube.com, youtu.be)',
        'assigned_domain' => 'Assigned Domain',
        'appearance' => 'Appearance',
        'appearance_desc' => 'Appearance settings for the blocked content overlay.',
        'preview_image' => 'Preview Image',
        'html_css_js' => 'HTML / CSS / JavaScript',
        'html_css_js_desc' => 'Code executed and displayed when the content matches.',
        'html' => 'HTML',
        'css' => 'CSS',
        'javascript' => 'JavaScript',
        'additional_settings' => 'Additional Settings',
        'text_replacements' => 'Text Replacements',
        'variable_name' => 'Variable Name',
        'replacement_text' => 'Replacement Text',
        'service_provider' => 'Service & Provider',
        'service_provider_desc' => 'Link this blocker to a legal provider and an opt-in service.',
        'service_context' => 'Service Context',
    ],

    /*
    |--------------------------------------------------------------------------
    | Common / Shared
    |--------------------------------------------------------------------------
    */
    'common' => [
        'language' => 'Language',
        'no_active_languages' => 'No active languages configured. Go to Languages to add and activate languages.',
        'translation_group' => 'Translation Group',
        'translation_key' => 'Translation Key or Original Text',
        'translations' => 'Translations',
        'add_translation' => 'Add Translation',
        'language_code' => 'Language Code (e.g. en, de, fr)',
        'translated_text' => 'Translated Text',
        'available_translations' => 'Available Translations',
        'original_text' => 'Original Text / Key',
        'type_group' => 'Type / Group',
        'import_from_files' => 'Import from Files',
        'import_translations' => 'Import Translations',
        'import_translations_desc' => 'This will scan your language files and import all translation keys into the database. Existing entries will be updated with any new values. You can then edit them directly from this page.',
        'import' => 'Import',
        'import_complete' => 'Import Complete',
        'translation_notice' => 'Switch language in the top bar to translate content.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Columns & Filters
    |--------------------------------------------------------------------------
    */
    'table' => [
        'domain' => 'Domain',
        'cookie_group' => 'Cookie Group',
        'name' => 'Name',
        'provider' => 'Provider',
        'source' => 'Source',
        'update' => 'Update',
        'is_active' => 'Active',
        'sort_order' => 'Sort Order',
        'filter_by_domain' => 'Filter by Domain',
        'filter_by_group' => 'Filter by Cookie Group',
        'active_status' => 'Active Status',
        'service_source' => 'Service Source',
        'all' => 'All',
        'library' => 'Library',
        'custom' => 'Custom',
        'add_custom_service' => 'Add Custom Service',
        'update_packages' => 'Update Packages',
    ],

    /*
    |--------------------------------------------------------------------------
    | System Pages
    |--------------------------------------------------------------------------
    */
    'system' => [
        'audit_log' => 'Audit Log',
        'consent_statistics' => 'Consent Statistics',
        'installation' => 'Installation',
        'email_smtp' => 'Email / SMTP',
        'consent_logs' => 'Consent Logs',
        'purge_consent_logs_action' => 'Purge by retention policy',
        'purge_consent_logs_modal_heading' => 'Purge old consent logs for this organization?',
        'purge_consent_logs_modal_description' => 'Deletes consent log rows strictly older than this organization’s consent_retention_days for its domains only. The same logic runs automatically once per day (scheduled).',
        'purge_consent_logs_success' => 'Retention purge completed',
        'purge_consent_logs_failed' => 'Retention purge failed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Installation Page
    |--------------------------------------------------------------------------
    */
    'installation' => [
        'site_id' => 'Site ID',
        'domain' => 'Domain',
        'no_domain' => 'No active domain found. Please create a domain first and activate it.',

        // Method Comparison
        'choose_method' => 'Choose Your Integration Method',
        'choose_method_desc' => 'Each method offers different levels of control. Pick the one that best fits your site.',
        'full_control' => 'Full Control',

        // Basic Script Card
        'compare_basic_title' => 'Basic Script',
        'compare_basic_desc' => 'One line of code. Works with Google Consent Mode and blocks tag-manager-injected scripts after page load.',
        'compare_basic_pro1' => 'Easiest setup — just paste one script tag',
        'compare_basic_pro2' => 'Google Consent Mode v2 support',
        'compare_basic_con1' => 'Cannot block scripts already in page HTML',

        // Advanced Script Card
        'compare_advanced_title' => 'Advanced Script',
        'compare_advanced_desc' => 'Strongest client-side protection. Intercepts dynamically injected scripts, document.write(), and tag managers before execution.',
        'compare_advanced_pro1' => 'Blocks dynamic insertions, tag managers, iframes',
        'compare_advanced_pro2' => 'No server setup — works on any website',
        'compare_advanced_con1' => 'Cannot block static <script> tags in raw HTML',

        // Proxy Mode Card
        'compare_proxy_title' => 'Proxy Mode',
        'compare_proxy_desc' => 'Full server-side control. All scripts are blocked before reaching the visitor. No code changes on your site.',
        'compare_proxy_pro1' => 'Blocks ALL scripts including static HTML tags',
        'compare_proxy_pro2' => 'Zero code changes on your website',
        'compare_proxy_con1' => 'Requires DNS change (CNAME record)',

        // Tab Labels
        'method_script' => 'Script Embed',
        'method_proxy' => 'Proxy Tunnel',

        // Method 1: Script Embed
        'script_title' => 'Integrate via Script Tag',
        'script_description' => 'Copy the code snippet below and paste it into the <head> section of your website, before all other scripts.',
        'basic' => 'Basic',
        'advanced' => 'Advanced',
        'recommended' => 'Recommended',
        'copy' => 'Copy',
        'copied' => 'Copied!',
        'basic_note_title' => 'Best for most sites',
        'basic_note_text' => 'A simple one-line script tag. Works with Google Consent Mode and blocks dynamically injected scripts. If your site only uses Google Tag Manager, this is all you need.',
        'advanced_note_title' => 'Strongest client-side protection',
        'advanced_note_text' => 'Includes a synchronous bootstrapper that intercepts dynamically injected and later-loading third-party scripts before they execute. Blocks scripts added via JavaScript APIs, document.write(), and tag managers. Does not block scripts that are already present as static <script> tags in your page HTML — for that, use Proxy Mode.',
        'csp_note_title' => 'Content Security Policy (CSP)',
        'csp_note_text' => 'If your website uses a strict Content Security Policy with a script-src directive that does not include \'unsafe-inline\', the bootstrapper script may be blocked by the browser. In that case, you will need to add a nonce or hash to your CSP policy, or use Proxy Mode which handles blocking server-side.',
        'instructions_title' => 'Installation Steps',
        'step_1' => 'Copy the snippet above.',
        'step_2' => 'Paste it as the FIRST element inside the <head> tag of your website.',
        'step_3' => 'Verify the consent banner appears on your website.',

        // Method 2: Proxy Tunnel
        'proxy_title' => 'Proxy Tunnel — Full Control',
        'auto' => 'Auto',
        'proxy_description' => 'Point your domain\'s DNS to YCookies and all traffic will be automatically proxied. Scripts are blocked server-side before reaching the visitor — no code changes needed on your website. This is the only method that blocks statically rendered scripts in raw HTML.',
        'how_it_works' => 'How It Works',
        'proxy_step_1' => 'Enter your current server\'s IP address below.',
        'proxy_step_2' => 'Add a CNAME record in your DNS: your-domain.com → :target',
        'proxy_step_3' => 'YCookies will proxy all traffic, blocking scripts and injecting the consent banner automatically.',
        'origin_ip' => 'Origin Server IP',
        'origin_ip_help' => 'The IP address of your current web server. YCookies will connect directly to this IP using your domain name as the hostname.',
        'origin_host' => 'Upstream Hostname (optional)',
        'origin_host_help' => 'Leave empty to use :domain. Only set this if your origin server expects a different hostname (e.g., your origin cert is for the apex domain but you are proxying www).',
        'enable_proxy' => 'Enable Proxy Tunnel',
        'dns_record' => 'DNS Record to Add',
        'dns_help' => 'Add this CNAME record at your domain registrar. DNS propagation may take up to 24 hours.',
        'save' => 'Save Settings',
        'saving' => 'Saving...',
        'verify_dns' => 'Verify DNS',
        'checking' => 'Checking...',

        // Status Messages
        'status_active' => 'Connected — Proxy is active, SSL provisioned',
        'status_dns_error' => 'DNS not pointing to YCookies — Please check your CNAME record',
        'status_ssl_pending' => 'DNS verified — SSL certificate being provisioned...',
        'status_pending' => 'Waiting for DNS propagation...',
    ],

    'script_scanner' => [
        'page_title' => 'Script Scanner',
        'select_all' => 'Select all',
        'delete_selected' => 'Delete selected',
        'confirm_delete_selected' => 'Delete the selected scans? This cannot be undone.',
        'select_for_bulk' => 'Select for bulk delete',
        'none_selected_hint' => 'Select one or more scans first (or use Select all).',
        'section_start' => 'Run a scan',
        'section_start_desc' => 'Choose a domain you manage in YCookies, or use “Custom URL” for a one-off analysis. Results and history always refer to the selection below.',
        'label_domain' => 'Website',
        'placeholder_domain' => 'Choose a domain…',
        'custom_url' => 'Custom URL (any website)',
        'url_label' => 'URL',
        'custom_url_placeholder' => 'https://example.com',
        'start_scan' => 'Start scan',
        'scanning' => 'Scanning…',
        'how_it_works' => 'How it works',
        'step_1' => 'Pick the site to analyse (managed domain or custom URL).',
        'step_2' => 'We discover pages, fetch them, and list third-party scripts and embeds.',
        'step_3' => 'Install blockers from the library, then use Scan history to compare past runs.',
        'empty_title' => 'No results yet',
        'empty_lead' => 'Run a scan to see external scripts, iframes, and stylesheets. We match them to your library and suggest blockers.',
        'empty_tag_http' => 'HTTP analysis',
        'empty_tag_chrome' => 'Headless Chrome',
        'scan_history_hint' => 'Click a row to load that scan’s details above. Use checkboxes to remove multiple entries.',
        'viewing' => 'Viewing',
        'delete_scan' => 'Delete this scan',
        'click_row_hint' => 'Click row to view this scan',
        'scan_failed' => 'Scan failed',
        'scan_log' => 'Page log',
        'pages_unit' => 'pages',
        'page_unit' => 'page',
        'scripts_unit' => 'scripts',
        'scan_history' => 'Scan history',
        'scans_recorded' => ':count scans recorded',
        'stat_total' => 'Total',
        'stat_protected' => 'Installed',
        'stat_suggested' => 'Suggested',
        'stat_unknown' => 'Unknown',
        'total_label' => 'total',
        'unknown_domain' => 'Unknown domain',
        'scheduler_last_scan' => 'Last automatic scan',
        'scheduler_next_scan' => 'Next scan (estimate)',
        'scheduler_scan_na' => 'Not available for this selection.',
        'scheduler_never_scanned' => 'Never yet',
        'scheduler_at_local' => 'Local time: :time',
        'scheduler_next_disabled' => '— Auto-scan is off',
        'scheduler_next_daily_cap' => 'Daily limit reached (:used / :max scans today).',
        'scheduler_next_daily_cap_hint' => 'Next automatic scan after the daily counter resets (usually from midnight, app timezone).',
        'scheduler_next_eligible_now' => 'Eligible now',
        'scheduler_next_eligible_hint' => 'No previous automatic scan recorded; the next scheduler or traffic check can start a scan.',
        'scheduler_next_ready' => 'Interval satisfied — eligible now',
        'scheduler_next_ready_hint' => 'Waits for the next run of the scanner command, traffic trigger, or web cron ping.',
        'scheduler_next_in' => 'About :when',
        'scheduler_next_earliest_at' => 'Earliest slot (min. interval): :time local',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared field labels (tables & forms)
    |--------------------------------------------------------------------------
    */
    'fields' => [
        'group' => 'Group',
        'email' => 'Email',
        'role' => 'Role',
        'expires_at' => 'Expires at',
        'created_at' => 'Created at',
    ],

    'script_blocker' => [
        'blocker_type' => 'Type',
        'type_script' => 'Script',
        'type_style' => 'Stylesheet',
        'section_information' => 'Blocker details',
        'name_helper' => 'Display name for this blocker.',
        'search_terms_description' => 'Phrases or handles that identify third-party scripts or stylesheets to block until consent.',
        'handles_helper' => 'Specific handles (e.g. vendor ids); press Enter to add.',
        'phrases_helper' => 'Text snippets found in inline code or URLs; press Enter to add.',
        'on_exist_helper' => 'JavaScript to run after the resource exists or is unblocked.',
        'service_description' => 'Link to a service so this blocker unblocks when the visitor opts in to that service.',
    ],

    'roles' => [
        'member' => 'Member',
        'admin' => 'Admin',
    ],

    'copy_invite_link' => 'Copy invite link',
    'resend_invite' => 'Resend invitation',
    'invitation_resent' => 'Invitation email sent again.',

    'group_invitation' => [
        'help_group' => 'The agency (tenant) this person will join after accepting the invite.',
        'help_email' => 'Must match the account email they use to sign in. They receive an email with an acceptance link.',
        'help_role' => 'Admins can manage the group; members have normal access within their permissions.',
        'list_subheading' => 'Invite colleagues to this agency. Each invite expires in 7 days; use “Copy invite link” to share the acceptance URL manually.',
    ],

    /*
    |--------------------------------------------------------------------------
    | List page subheadings (what this admin area is for)
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'list' => [
            'group_invitations' => 'Email invites so additional users can join this agency group with a chosen role.',
            'webhook_endpoints' => 'Your HTTPS URLs that YCookies POSTs to on events (e.g. script scan completed), signed with HMAC.',
            'services' => 'Third-party services and tags mapped to cookie consent categories per domain.',
            'groups' => 'Agency / organisation records (tenants). Usually one per customer organisation you manage.',
            'domains' => 'Websites using YCookies: installation, proxy, cookie bar assignment, and scanner settings.',
            'cookie_bars' => 'Consent banner appearance, texts, and behaviour for your cookie UI.',
            'cookie_groups' => 'Cookie categories (e.g. marketing, statistics) used to group services and legal text.',
            'providers' => 'Vendor definitions linked to services (who sets which cookies).',
            'script_blockers' => 'Rules that block or control third-party scripts until consent is given.',
            'script_and_style_blockers' => 'Script and stylesheet blockers in one list. Use the type column or filter to tell them apart.',
            'style_blockers' => 'Rules that block or control third-party stylesheets until consent is given.',
            'content_blockers' => 'Block embedded content (e.g. iframes) until the visitor opts in.',
            'languages' => 'Locales available for consent UI and admin where applicable.',
            'language_lines' => 'Override or extend translation strings used in the admin panel UI.',
            'roles' => 'Filament Shield roles and permissions for admin users.',
            'traffic_alerts' => 'Traffic and anomaly alerts (often opened from the Health Checker).',
        ],
        'page' => [
            'package_library' => 'Install ready-made service, script-blocker, and content-blocker templates onto your domains.',
            'script_scanner' => 'Discover pages and third-party scripts on a site; review history and manage blockers from scan results.',
            'consent_logs' => 'Per-consent records from visitors (choices, categories, domain). Filter and export for audits.',
            'installation' => 'Per-domain integration: script snippet, proxy tunnel, and related technical setup.',
            'health_checker' => 'Automated checks on your sites: banner, CMP, TLS, and related signals.',
            'settings' => 'Tenant-wide preferences and configuration for this admin experience.',
            'audit_log' => 'Immutable log of important changes made by users in the admin panel.',
            'consent_statistics' => 'Charts and aggregates of consent activity across domains.',
            'billing_upgrade' => 'Plan, subscription status, and limits for this agency group.',
            'consent_debugger' => 'Send test consent events to verify your site integration and tag firing.',
            'setup_wizard' => 'Guided first-time setup: domains, banner, and core consent configuration.',
        ],
    ],

    'saved' => 'Saved successfully!',
];
