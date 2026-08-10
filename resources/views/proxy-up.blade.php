<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Node Proxy Status</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Tailwind CSS (via CDN for simplicity, or use local if built) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }
        .pulse-blob {
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: pulse-green 2s infinite;
        }
        .pulse-blob.error {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            animation: pulse-red 2s infinite;
        }

        @keyframes pulse-green {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 10px rgba(34, 197, 94, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
            }
        }
        @keyframes pulse-red {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }
    </style>
</head>
<body class="antialiased h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-100">
        <div class="bg-slate-50 border-b border-slate-100 px-6 py-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-indigo-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75 16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z" />
                </svg>
                YCookies Node Proxy
            </h1>
            <div>
                @if($status === 'ok')
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                        <span class="w-2 h-2 rounded-full bg-green-500 pulse-blob"></span>
                        Operational
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">
                        <span class="w-2 h-2 rounded-full bg-red-500 pulse-blob error"></span>
                        Offline
                    </span>
                @endif
            </div>
        </div>
        
        <div class="px-6 py-6 space-y-6">
            @if($status === 'ok')
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                        <p class="text-xs font-medium text-slate-500 mb-1 uppercase tracking-wider">Uptime</p>
                        <p class="text-xl font-semibold text-slate-800">{{ number_format($uptime ?? 0, 2) }} s</p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                        <p class="text-xs font-medium text-slate-500 mb-1 uppercase tracking-wider">Latency</p>
                        <p class="text-xl font-semibold text-slate-800">{{ $latency ?? 0 }} ms</p>
                    </div>
                </div>
                
                <div class="flex items-center justify-center p-4 bg-indigo-50/50 rounded-xl border border-indigo-100/50">
                    <p class="text-sm text-indigo-700 text-center">
                        Proxy is active and responding to internal requests.
                    </p>
                </div>
            @else
                <div class="flex flex-col items-center justify-center p-6 bg-red-50/50 rounded-xl border border-red-100/50 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-10 h-10 text-red-500 mb-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3Z" />
                    </svg>
                    <p class="text-sm font-medium text-red-800 mb-1">Connection Failed</p>
                    <p class="text-xs text-red-600">{{ $message ?? 'Unknown error' }}</p>
                </div>
            @endif
        </div>
        
        <div class="bg-slate-50 border-t border-slate-100 px-6 py-3">
            <p class="text-xs text-slate-400 text-center flex items-center justify-center gap-1">
                Checked at {{ now()->timezone('UTC')->format('Y-m-d H:i:s') }} UTC
            </p>
        </div>
    </div>
</body>
</html>
