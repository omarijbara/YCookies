<style>
    :root {
        --primary: {{ config('larecipe.ui.colors.primary') }};
        --secondary: {{ config('larecipe.ui.colors.secondary') }};
    }

    :not(pre)>code[class*=language-], pre[class*=language-] {
        border-top: 3px solid {{ config('larecipe.ui.colors.primary') }};
    }
    
    .bg-gradient-primary {
        background: linear-gradient(87deg, {{ config('larecipe.ui.colors.primary') }} 0, {{ config('larecipe.ui.colors.secondary') }} 100%) !important;
    }

    [v-cloak] > * { 
        display: none; 
    }
    
    [v-cloak]::before { 
        content: " ";
        position: absolute;
        width: 100%;
        height: 100%;
        background-color: #F2F6FA;
    }

    /* Make Documentation 100% Full Width */
    .documentation, .documentation.expanded {
        max-width: 100% !important;
        width: 100% !important;
    }
    .documentation > div, .documentation .container, .documentation .content, .documentation .is-expanded {
        max-width: 100% !important;
        width: auto !important;
        padding-left: 2rem !important;
        padding-right: 2rem !important;
    }
</style>