<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proxy Error - {{ config('app.name', 'YCookies') }}</title>
    <style>
        body { 
            background-color: #111827; 
            color: #f3f4f6; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            height: 100vh; 
            margin: 0; 
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
        }
        .glass-panel { 
            background: rgba(31, 41, 55, 0.7); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1); 
            border-radius: 1rem; 
            padding: 2.5rem 2rem; 
            max-width: 28rem; 
            width: 100%;
            text-align: center; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255,255,255,0.05) inset; 
            box-sizing: border-box;
        }
        .error-icon { 
            background: rgba(239, 68, 68, 0.15); 
            color: #ef4444; 
            width: 4rem; 
            height: 4rem; 
            border-radius: 9999px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0 auto 1.5rem auto; 
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        h1 { 
            font-size: 1.5rem; 
            font-weight: 600; 
            margin-top: 0;
            margin-bottom: 0.75rem; 
            color: #ffffff; 
            letter-spacing: -0.025em;
        }
        p { 
            color: #9ca3af; 
            margin-bottom: 1.5rem; 
            font-size: 0.95rem; 
            line-height: 1.5; 
        }
        .url-box { 
            background: rgba(0, 0, 0, 0.4); 
            padding: 0.875rem; 
            border-radius: 0.5rem; 
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; 
            font-size: 0.8rem; 
            color: #60a5fa; 
            word-break: break-all; 
            margin-bottom: 2rem; 
            border: 1px solid rgba(255,255,255,0.05); 
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
        }
        .btn { 
            background: #3b82f6; 
            color: white; 
            border: 1px solid rgba(255,255,255,0.1); 
            padding: 0.625rem 1.5rem; 
            border-radius: 0.5rem; 
            font-weight: 500; 
            cursor: pointer; 
            transition: all 0.2s ease-in-out; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .btn:hover { 
            background: #2563eb; 
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(0, 0, 0, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.06);
        }
        .btn:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <div class="glass-panel">
        <div class="error-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 2rem; height: 2rem;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h1>Connection Failed</h1>
        <p>{{ $message }}</p>
        @if(isset($url))
            <div class="url-box">{{ $url }}</div>
        @endif
        <button onclick="window.location.reload()" class="btn">
            Try Again
        </button>
    </div>
</body>
</html>
