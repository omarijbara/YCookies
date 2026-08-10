import http from 'http';
import zlib from 'zlib';

// ==========================================
// YCOOKIES SYNTHETIC ORIGIN SERVER
// Track B: Dispoable Tenant Emulation
// Port: 4000
// ==========================================

const dummyHtml = `
<!DOCTYPE html>
<html>
<head>
    <title>Synthetic Test Protocol</title>
    <!-- MOCK CSP HEAVY Tenant emulation -->
    <meta http-equiv="Content-Security-Policy" content="script-src 'self' 'nonce-RANDOMIZED' https://analytics.example.com; style-src 'self' 'unsafe-inline';">
</head>
<body>
    <h1>Controlled Payload</h1>
    <!-- Large HTML injection will scale this if needed -->
    <script nonce="RANDOMIZED">console.log('Test Analytics');</script>
</body>
</html>
`;

const server = http.createServer((req, res) => {
  const url = req.url;

  // Emulate structural anomalies via URL paths matching the Playwright tests

  // 1. Large HTML Payload (1MB repetitive DOM to test Buffer limits)
  if (url === '/size-guard') {
    res.writeHead(200, {
      'Content-Type': 'text/html',
      'Set-Cookie': 'PHPSESSID=heavy_load; path=/',
      'Cache-Control': 'public, max-age=3600'
    });
    res.write('<html><body>');
    res.write('A'.repeat(1024 * 1024)); // 1MB filler simulating large template outputs
    res.write('</body></html>');
    return res.end();
  }

  // 2. Upstream Compression Simulation
  if (url === '/compressed-brotli') {
    res.writeHead(200, {
      'Content-Type': 'text/html',
      'Content-Encoding': 'br',
      'Set-Cookie': 'PHPSESSID=brotli123; path=/',
      'Cache-Control': 'public, max-age=300'
    });
    const brData = zlib.brotliCompressSync(Buffer.from(dummyHtml));
    return res.end(brData);
  }

  // 3. Status Override Simulation (500 Edge Case Testing)
  if (url === '/throw-500') {
    res.writeHead(500, { 'Content-Type': 'text/html' });
    return res.end('<h1>Native Fatal Crash</h1>');
  }

  // Default: Normal HTML Payload simulating WooCommerce/Laravel
  res.writeHead(200, {
    'Content-Type': 'text/html',
    'Set-Cookie': 'PHPSESSID=synthetic123; path=/',
    'Cache-Control': 'public, s-maxage=3600' // Test edge caching priority
  });
  res.end(dummyHtml);
});

const PORT = 4000;
server.listen(PORT, '0.0.0.0', () => {
  console.log(`[Track B] Synthetic Origin successfully listening on Port ${PORT}`);
});
