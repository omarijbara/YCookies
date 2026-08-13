# YCookies — Scanner Worker

The [`ypsilondev/ycookies`](https://hub.docker.com/r/ypsilondev/ycookies) control-plane image **plus headless Chromium/Puppeteer**, used as a dedicated queue worker for website scans: it crawls customer sites, detects trackers against 300+ service templates, runs deep scans in a real browser, and produces GDPR compliance verdicts.

📦 **Source, full documentation & compose stack:** https://github.com/omarijbara/YCookies

## Usage

Runs as the `scanner-worker` service in the [ready-made compose stack](https://github.com/omarijbara/YCookies/blob/main/deploy/docker-compose.yml):

```yaml
scanner-worker:
  image: ypsilondev/ycookies-scanner
  command: ["php", "artisan", "queue:work", "--queue=scanner", "--sleep=5", "--tries=1", "--timeout=600"]
  # same environment as the control plane image
  cpus: 0.5
  mem_limit: 1536m
```

Scan jobs are dispatched to the `scanner` queue by the control plane; this worker is the only one that should consume it (the deep-scan phase needs the bundled Chromium). Resource caps are recommended — headless browsing is memory-hungry.

## Environment

Identical to [`ypsilondev/ycookies`](https://hub.docker.com/r/ypsilondev/ycookies) (`APP_KEY`, `DB_*`, `PROXY_SHARED_SECRET`, …), plus optional scanner tuning (`SCANNER_SCHEDULED_DEEP_SCAN_ENABLED`, `SCANNER_*` pacing knobs). `CHROME_PATH` and Puppeteer variables are pre-configured in the image.

Full reference: https://github.com/omarijbara/YCookies#%EF%B8%8F-environment-reference

## Tags

- `latest`, `X.Y.Z`, `X.Y` — releases
- `edge` — latest development build

## License

[Elastic License 2.0](https://github.com/omarijbara/YCookies/blob/main/LICENSE).
