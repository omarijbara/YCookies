<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies - Self-Hosted Cookie Consent Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-900 via-slate-800 to-black text-white overflow-x-hidden antialiased">

    <!-- NAVIGATION -->
    <nav class="fixed w-full z-50 bg-slate-900/80 backdrop-blur-md border-b border-white/10">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div class="text-2xl font-black bg-gradient-to-r from-blue-400 via-purple-400 to-pink-400 bg-clip-text text-transparent tracking-tighter">
                YCookies.
            </div>
            <div class="flex items-center gap-6 font-medium text-sm md:text-base">
                <a href="#features" class="hover:text-blue-400 transition-colors hidden sm:block">Features</a>
                <a href="#pricing" class="hover:text-blue-400 transition-colors hidden sm:block">Pricing</a>
                @auth
                <div class="relative group">
                    <button class="flex items-center gap-3 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-700 px-2 py-1.5 pr-4 rounded-full font-medium transition-all shadow-lg backdrop-blur-md">
                        <div class="w-8 h-8 bg-gradient-to-tr from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white text-xs font-bold uppercase shadow-inner border border-white/10">
                            {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                        </div>
                        <span class="text-slate-200 hidden sm:block text-sm">{{ auth()->user()->name ?? 'Account' }}</span>
                        <svg class="w-4 h-4 text-slate-500 group-hover:text-slate-300 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <!-- Dropdown Menu -->
                    <div class="absolute right-0 top-full mt-3 w-60 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 transform origin-top-right scale-95 group-hover:scale-100 group-hover:translate-y-0 z-50">
                        <!-- Invisible safe hover zone -->
                        <div class="absolute -top-3 left-0 right-0 h-4"></div>

                        <div class="bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 rounded-2xl shadow-2xl shadow-black p-2 flex flex-col gap-1 relative overflow-hidden">
                            <!-- Glow effect -->
                            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-32 bg-blue-500/10 blur-2xl rounded-full pointer-events-none"></div>

                            <a href="{{ url('/admin/' . (auth()->user()->getDefaultTenant(filament()->getPanel('admin'))?->id ?? 'login')) }}" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-blue-500/10 hover:text-blue-400 transition-colors relative z-10 font-medium">
                                <svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                <span>Dashboard</span>
                            </a>
                            <a href="{{ url('/admin/' . (auth()->user()->getDefaultTenant(filament()->getPanel('admin'))?->id ?? 'login') . '/billing-upgrade') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-purple-500/10 hover:text-purple-400 transition-colors relative z-10 font-medium">
                                <svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                                <span>Subscription & Billing</span>
                            </a>
                            <div class="h-px bg-slate-800 my-1 mx-2 relative z-10"></div>
                            <form method="POST" action="{{ route('filament.admin.auth.logout') }}" class="m-0 p-0 relative z-10">
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-red-400 hover:bg-red-500/10 transition-colors text-left font-medium">
                                    <svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    <span>Log Out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                @else
                <a href="{{ url('/admin/login') }}" class="hover:text-blue-400 transition-colors">Log In</a>
                <a href="{{ url('/admin/register') }}" class="bg-blue-600 hover:bg-blue-500 px-5 py-2.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 transition-all transform hover:-translate-y-0.5">Start Free</a>
                @endauth
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="min-h-screen flex items-center justify-center relative pt-20">
        <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-5"></div>
        <div class="container mx-auto px-6 text-center relative z-10">
            <div class="max-w-4xl mx-auto">
                <div class="inline-block mb-6 px-4 py-1.5 rounded-full border border-purple-500/30 bg-purple-500/10 text-purple-300 font-semibold text-sm backdrop-blur-sm">
                    🚀 Now featuring Live Design Preview & TCF v2.2
                </div>
                <h1 class="text-5xl md:text-7xl lg:text-8xl font-black mb-8 bg-gradient-to-r from-blue-400 via-purple-400 to-pink-400 bg-clip-text text-transparent drop-shadow-2xl tracking-tight leading-tight">
                    Self-Hosted<br>
                    <span class="text-4xl md:text-6xl lg:text-7xl">Cookie Consent.</span>
                </h1>
                <p class="text-lg md:text-2xl lg:text-3xl mb-12 max-w-3xl mx-auto text-slate-300 leading-relaxed font-light">
                    Premium enterprise features without the WordPress limits. Manage
                    unlimited client domains from a single installation.
                </p>
                <div class="flex flex-col sm:flex-row gap-6 justify-center items-center mb-20">
                    <a href="{{ url('/admin/register') }}"
                        class="group bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 px-10 py-5 rounded-2xl text-xl font-bold shadow-2xl shadow-blue-500/20 transform hover:-translate-y-1 transition-all duration-300 inline-flex items-center w-full sm:w-auto justify-center">
                        <span>Start Free Trial</span>
                        <svg class="w-6 h-6 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                        </svg>
                    </a>
                    <a href="#demo" class="border-2 border-slate-600 hover:border-slate-400 hover:bg-slate-800/50 px-10 py-5 rounded-2xl text-xl font-semibold transition-all duration-300 inline-block w-full sm:w-auto text-center backdrop-blur-sm">
                        Watch Demo (30s)
                    </a>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 max-w-4xl mx-auto opacity-80 pt-8 border-t border-slate-700/50">
                    <div class="flex flex-col items-center space-y-3">
                        <div class="w-12 h-12 bg-blue-500/20 rounded-2xl flex items-center justify-center text-blue-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-sm md:text-base">Live Preview</span>
                    </div>
                    <div class="flex flex-col items-center space-y-3">
                        <div class="w-12 h-12 bg-purple-500/20 rounded-2xl flex items-center justify-center text-purple-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-sm md:text-base">Auto Scanner</span>
                    </div>
                    <div class="flex flex-col items-center space-y-3">
                        <div class="w-12 h-12 bg-pink-500/20 rounded-2xl flex items-center justify-center text-pink-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-sm md:text-base">TCF v2.2</span>
                    </div>
                    <div class="flex flex-col items-center space-y-3">
                        <div class="w-12 h-12 bg-emerald-500/20 rounded-2xl flex items-center justify-center text-emerald-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-sm md:text-base">
                            < 20ms p99</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURES OVERVIEW -->
    <section id="features" class="py-24 bg-slate-900 relative">
        <div class="container mx-auto px-6">
            <div class="text-center max-w-3xl mx-auto mb-20">
                <h2 class="text-4xl md:text-5xl font-bold mb-6">Built for Agencies. <br><span class="text-blue-400">Engineered for Scale.</span></h2>
                <p class="text-xl text-slate-400">Everything you need to manage consent compliance across hundreds of client domains from a single dashboard.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="bg-slate-800/50 border border-slate-700 p-8 rounded-3xl hover:-translate-y-2 transition-transform duration-300">
                    <div class="w-14 h-14 bg-blue-500/20 rounded-2xl flex items-center justify-center text-blue-400 mb-6">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Centralized Multi-Tenant</h3>
                    <p class="text-slate-400 leading-relaxed">Manage permissions, UI configurations, and scan logs for practically unlimited domains across all your clients securely.</p>
                </div>

                <!-- Feature 2 -->
                <div class="bg-slate-800/50 border border-slate-700 p-8 rounded-3xl hover:-translate-y-2 transition-transform duration-300">
                    <div class="w-14 h-14 bg-purple-500/20 rounded-2xl flex items-center justify-center text-purple-400 mb-6">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Headless Auto-Scanner</h3>
                    <p class="text-slate-400 leading-relaxed">Our Puppeteer-driven recursive scanner traverses your client sites silently, identifying trackers using Levenshtein fuzzy matching.</p>
                </div>

                <!-- Feature 3 -->
                <div class="bg-slate-800/50 border border-slate-700 p-8 rounded-3xl hover:-translate-y-2 transition-transform duration-300">
                    <div class="w-14 h-14 bg-pink-500/20 rounded-2xl flex items-center justify-center text-pink-400 mb-6">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">250+ Customization Controls</h3>
                    <p class="text-slate-400 leading-relaxed">4 Core layouts. Deep CSS variable injections. Live iframe previews. Dial entirely bespoke un-blockable frontends.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- PRICING SECTION -->
    <section id="pricing" class="py-24 bg-slate-950 relative">
        <div class="container mx-auto px-6">
            <div class="text-center max-w-3xl mx-auto mb-20">
                <h2 class="text-4xl md:text-5xl font-bold mb-6">Simple, Transparent Pricing</h2>
                <p class="text-xl text-slate-400">Start for free. Scale when your agency requires it.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8 max-w-6xl mx-auto items-center">

                <!-- Free Tier -->
                <div class="bg-slate-900 border border-slate-800 p-8 rounded-3xl">
                    <h3 class="text-2xl font-bold text-slate-300 mb-2">Free</h3>
                    <div class="mb-6"><span class="text-5xl font-black">$0</span> <span class="text-slate-400">/ forever</span></div>
                    <ul class="space-y-4 mb-8 text-slate-400">
                        <li class="flex items-center"><svg class="w-5 h-5 text-emerald-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> 1 Domain limit</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-emerald-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Core Compliance UI</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-emerald-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Community Support</li>
                    </ul>
                    <a href="{{ url('/admin/register') }}" class="block w-full py-4 rounded-xl font-bold text-center border-2 border-slate-700 hover:bg-slate-800 transition-colors">Start Free</a>
                </div>

                <!-- Pro Tier (Highlighted) -->
                <div class="bg-gradient-to-b from-blue-900/40 to-slate-900 border border-blue-500/50 p-8 rounded-3xl relative transform md:-translate-y-4 shadow-2xl shadow-blue-900/20">
                    <div class="absolute top-0 right-8 -translate-y-1/2 bg-blue-500 text-white px-3 py-1 text-sm font-bold rounded-full">MOST POPULAR</div>
                    <h3 class="text-2xl font-bold text-blue-400 mb-2">Pro</h3>
                    <div class="mb-6"><span class="text-5xl font-black">$29</span> <span class="text-slate-400">/ month</span></div>
                    <ul class="space-y-4 mb-8 text-slate-300">
                        <li class="flex items-center"><svg class="w-5 h-5 text-blue-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> 10 Domains</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-blue-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Live Design Preview</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-blue-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Deep Scanner Engine</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-blue-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> 300+ Templates</li>
                    </ul>
                    <a href="{{ url('/admin/register') }}" class="block w-full py-4 rounded-xl font-bold text-center bg-blue-600 hover:bg-blue-500 shadow-lg shadow-blue-500/30 transition-colors">Upgrade to Pro</a>
                </div>

                <!-- Agency Tier -->
                <div class="bg-slate-900 border border-slate-800 p-8 rounded-3xl">
                    <h3 class="text-2xl font-bold text-purple-400 mb-2">Agency</h3>
                    <div class="mb-6"><span class="text-5xl font-black">$99</span> <span class="text-slate-400">/ month</span></div>
                    <ul class="space-y-4 mb-8 text-slate-400">
                        <li class="flex items-center"><svg class="w-5 h-5 text-purple-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> <strong class="ml-1 text-white">Unlimited Domains</strong></li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-purple-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> IAB TCF v2.2 Access</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-purple-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Google Consent Mode v2</li>
                        <li class="flex items-center"><svg class="w-5 h-5 text-purple-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg> Priority Support</li>
                    </ul>
                    <a href="{{ url('/admin/register') }}" class="block w-full py-4 rounded-xl font-bold text-center border-2 border-slate-700 hover:bg-slate-800 transition-colors">Go Agency</a>
                </div>

            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="bg-black py-12 border-t border-slate-800">
        <div class="container mx-auto px-6 text-center text-slate-500">
            <div class="text-2xl font-black bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent tracking-tighter mb-4 inline-block">
                YCookies.
            </div>
            <p class="mb-6">&copy; {{ date('Y') }} Ypsilon.dev UG. All rights reserved.</p>
            <div class="flex justify-center gap-6 text-sm">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-white transition-colors">Imprint</a>
            </div>
        </div>
    </footer>

</body>

</html>