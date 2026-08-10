import { createHtmlInjector } from '../html-injector.js';
import { createBlockerStream } from '../html-blocker-stream.js';
import { Readable } from 'node:stream';

console.log('=== HTML Pipeline Chaos Fuzzer ===');

const GARBLED_PAYLOADS = [
  // Malformed tags
  Buffer.from('<html><body><script src="bad"> </scr' + 'ipt></body>'),
  Buffer.from('<head><scrip</head>'),
  // Giant streams of useless attributes
  Buffer.from('<html><head><script ' + 'a="1" '.repeat(50000) + ' src="bad.com"></script></head>'),
  // Infinite unclosed comments
  Buffer.from('<!-- This comment never ends... ' + 'abc'.repeat(50000)),
  // Corrupt UTF-8
  Buffer.from([0x80, 0x81, 0x82, 0xff, 0xfe, 0xfd, 0x3c, 0x73, 0x63, 0x72, 0x69, 0x70, 0x74, 0x3e]), // Invalid UTF-8 + <script>
  // Huge chunking test (simulating slow origin)
  Buffer.alloc(1024 * 1024 * 5, '<script src="tracker.com"></script>'), // 5MB of trackers
];

const mockConfig = {
  domain: 'fuzz.test',
  proxy: { proxy_pass: '1' },
  script_rules: [{ match_string: 'tracker', strategy: 'block_src' }],
  content_rules: [],
};

let passed = 0;
let errors = 0;

async function runFuzzer() {
  for (let i = 0; i < GARBLED_PAYLOADS.length; i++) {
    const payload = GARBLED_PAYLOADS[i];
    
    // Simulate pipeline
    const injector = createHtmlInjector(mockConfig, { nonce: 'fuzz-nonce', gpc: false });
    const blocker = createBlockerStream(mockConfig);
    
    const stream = Readable.from([payload]);
    
    try {
      await new Promise((resolve, reject) => {
        let outBytes = 0;
        const pipeline = stream.pipe(injector).pipe(blocker);
        
        pipeline.on('data', (chunk) => {
          outBytes += chunk.length;
        });
        pipeline.on('end', () => {
          console.log(`[PASS] Payload ${i}: Processed cleanly. OutBytes=${outBytes}`);
          passed++;
          resolve();
        });
        pipeline.on('error', (err) => {
          console.error(`[CATCH] Payload ${i}: Intercepted error (did not crash process): ${err.message}`);
          errors++;
          resolve();
        });
      });
    } catch (err) {
      console.error(`[CRASH] Payload ${i} crashed the promise wrapper:`, err);
    }
  }
  
  console.log('\\n=== Fuzzer Results ===');
  console.log(`Passed cleanly: ${passed}`);
  console.log(`Caught pipeline errors (safe): ${errors}`);
  if (passed + errors === GARBLED_PAYLOADS.length) {
    console.log('✅ Fuzzer test passed — no unhandled rejections or crashes.');
  } else {
    console.error('❌ Fuzzer failed — pipeline crashed.');
    process.exit(1);
  }
}

runFuzzer();
