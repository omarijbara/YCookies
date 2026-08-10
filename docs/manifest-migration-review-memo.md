# Manifest Migration Review Memo

Review basis:

- Locked design references: `ai-help/context/node_proxy_plan.md:7-16`, `ai-help/context/node_proxy_plan.md:226-251`, `ai-help/context/02-request-lifecycle.md:15-25`, `ai-help/context/02-request-lifecycle.md:63-120`, `ai-help/context/03-risk-and-invariants.md:7-13`, `ai-help/context/03-risk-and-invariants.md:57-61`.
- Current code in this repository as reviewed on 2026-03-28.
- Live checks against `duftz.de` and `cookies.ypsilon.dev` taken on 2026-03-28 between 04:57 and 05:02 Europe/Berlin.

## 1. Executive judgment

- The compiler -> signer -> publisher -> pointer pipeline is real and correctly wired. `DomainCompiler` produces canonical artifacts, `RevisionPublisher` signs and stores them transactionally, and `domains.active_revision_id` is the live pointer (`app/Runtime/Compiler/DomainCompiler.php:59-114`, `app/Runtime/Publisher/RevisionPublisher.php:48-108`, `database/migrations/2026_03_28_003126_create_runtime_revisions_table.php:11-65`).
- The live canary is currently structurally up. `GET https://duftz.de/` returned `200 OK` with `X-Proxy: ycookies`, and the public YCookies surfaces `/api/proxy-config`, `/api/config`, `/api/script`, and the static asset URL from live proxy-config were all `200` during the final check window.
- Manifest mode is genuinely active for `duftz.de`. Live `GET https://cookies.ypsilon.dev/api/proxy-config/duftz.de` returned `manifest.enabled=true`, `revision_number=1`, and `published_at="2026-03-28T03:23:36+00:00"`. Live `GET /api/config/{site_id}` also returned `X-Manifest-Revision: 1`.
- The implementation is only partially cut over to the locked design. The proxy still receives a legacy DB-assembled host config and then overlays manifest-derived fields on top of it (`app/Http/Controllers/Api/ProxyConfigController.php:73-172`, `services/proxy/manifest-consumer.js:136-152`, `services/proxy/server.js:212-219`).
- Route overlays are not in play yet. The compiler explicitly publishes base-only revisions with `routeIndex = null` and `overlays = []`, so route-specific behavior is still unproven (`app/Runtime/Compiler/DomainCompiler.php:62-68`).
- Manifest verification is not fail-closed. The proxy verifies HMAC on `/api/proxy-config`, but manifest verification itself only proves the artifact can be canonicalized and hashed, then silently falls back to legacy on failure (`services/proxy/laravel-client.js:442-467`, `services/proxy/manifest-consumer.js:9-12`, `services/proxy/manifest-consumer.js:62-85`).
- The canary hot path is still the dynamic `/api/script/{site}.js` bundle, not the static loader flow described in the locked request lifecycle. The live HTML injects `/api/script/...js`, and the live proxy-config payload shows that the legacy block has a static loader URL while the manifest block overwrites it with `static_loader_url: null` (`services/proxy/html-injector.js:34-67`, `app/Runtime/Compiler/DomainCompiler.php:233-241`, `app/Http/Controllers/Api/ProxyConfigController.php:131-139`).
- There is already visible legacy-vs-manifest drift in the live payload. In the same live `/api/proxy-config/duftz.de` response, legacy `proxy.status` was `active` while manifest `base_artifact.proxy.status` was `inactive`; that comes directly from different defaults in the two code paths (`app/Http/Controllers/Api/ProxyConfigController.php:119-123`, `app/Runtime/Compiler/DomainCompiler.php:263-267`).
- The current canary does not prove enforcement correctness on a real blocked site. The live manifest for `duftz.de` has empty `script_blockers` and `content_blockers`, `/api/boot/{site}.js` returned `/* YCookies: No script blockers configured */`, the homepage still carries Borlabs assets, and the returned HTML contained no YCookies block markers.

## 2. Architecture conformance review

- Single runtime truth: Partially compliant.
  The published revision is a real runtime artifact set, but `ProxyConfigController` still builds `origin`, `proxy`, `consent`, `bootstrapper`, blockers, `cookie_policy`, and `features` directly from live DB relations before appending `manifest`; Node then merges manifest values over that legacy payload. This is not a pure single-truth read path (`app/Http/Controllers/Api/ProxyConfigController.php:73-172`, `services/proxy/manifest-consumer.js:136-152`, `services/proxy/server.js:212-219`).
- Compiler/publisher model: Compliant.
  Compilation and publication are explicit, separate stages. `CompileAndPublishRevision` compiles, skips no-op publishes via `compile_inputs_hash`, then publishes through `RevisionPublisher` (`app/Jobs/CompileAndPublishRevision.php:44-98`, `app/Runtime/Compiler/DomainCompiler.php:97-114`, `app/Runtime/Publisher/RevisionPublisher.php:48-108`).
- Immutable revision/pointer model: Compliant.
  `runtime_revisions` stores immutable manifest and artifact blobs; `domains.active_revision_id` is the active pointer; publish and rollback move that pointer transactionally (`database/migrations/2026_03_28_003126_create_runtime_revisions_table.php:11-65`, `app/Runtime/Publisher/RevisionPublisher.php:67-99`, `app/Runtime/Publisher/RevisionPublisher.php:162-194`, `app/Runtime/Consumer/RevisionResolver.php:41-62`).
- Proxy as enforcement plane: Partially compliant.
  The proxy does the actual HTML mutation, cookie filtering, CSP merge, injection, and blocker streaming (`services/proxy/server.js:318-456`, `services/proxy/html-injector.js:33-72`, `services/proxy/html-blocker-stream.js:99-138`). But the current canary does not exercise real proxy blocker enforcement because the manifest has no blockers, and the SDK still performs substantial interception/restoration logic on the client (`resources/js/manager.js:97-99`, `resources/js/manager.js:1813-1867`).
- SDK as thin runtime: Non-compliant.
  The client runtime is still heavy. It does geolocation, config fetch fallback, consent storage, Google Consent Mode, DOM monkey patching, `sendBeacon` interception, embed restoration, and service worker registration (`resources/js/manager.js:68-99`, `resources/js/manager.js:277-333`, `resources/js/manager.js:1813-1867`, `resources/js/manager.js:2450-2453`).
- Compatibility endpoints as projections only: Partially compliant.
  A shared projection service exists, but `/api/config`, `/api/script`, and `/api/boot` all retain live legacy fallback paths instead of being manifest-only projections (`app/Runtime/Consumer/ManifestConfigService.php:13-23`, `app/Http/Controllers/Api/ManifestProjectionController.php:46-75`, `app/Http/Controllers/Api/ScriptDeliveryController.php:30-83`, `app/Http/Controllers/Api/BootstrapperController.php:36-88`).
- No second source of truth: Non-compliant.
  There are still two live runtime truths: legacy DB assembly and manifest projection. The proxy host-config path, the config projection path, the script delivery path, and the bootstrapper path can all fall back to legacy DB reads (`app/Http/Controllers/Api/ProxyConfigController.php:73-172`, `app/Http/Controllers/Api/ManifestProjectionController.php:46-75`, `app/Http/Controllers/Api/ScriptDeliveryController.php:49-83`, `app/Http/Controllers/Api/BootstrapperController.php:64-88`).
- Fail-closed verification: Non-compliant.
  The locked schema expects consumers to verify the signed manifest first and then artifact hashes (`app/Runtime/Schema/ManifestSchema.php:95-103`, `app/Runtime/Schema/ManifestSchema.php:225-237`). The current proxy does not do that. It receives `manifest_hash`, `signature`, and `base_artifact`, but it does not verify the signature or the artifact against a manifest reference, and on failure it returns `null` and reuses legacy config (`app/Http/Controllers/Api/ProxyConfigController.php:267-275`, `services/proxy/manifest-consumer.js:62-85`).
- Redis as acceleration only: Compliant.
  The proxy cache client explicitly documents MySQL/Laravel HTTP as authoritative, with Redis/RAM/disk as derived layers (`services/proxy/laravel-client.js:5-22`, `services/proxy/laravel-client.js:326-493`). Manifest publish acceleration is also best-effort and non-fatal (`app/Runtime/Publisher/RevisionPublisher.php:117-151`).

## 3. Code-path review of the current canary

1. Compile
   `DomainObserver` and `RuntimeModelObserver` dispatch `CompileAndPublishRevision` for manifest-enabled domains when domain policy or related runtime models change (`app/Observers/DomainObserver.php:35-41`, `app/Observers/RuntimeModelObserver.php:32-57`, `app/Runtime/Publisher/PublishTrigger.php:30-118`).
   The job compiles via `DomainCompiler`, then skips publish if `compile_inputs_hash` matches the last published revision (`app/Jobs/CompileAndPublishRevision.php:59-79`).
   The current compiler output for canary traffic is base-only: no route index, no overlays (`app/Runtime/Compiler/DomainCompiler.php:62-68`).

2. Publish
   `RevisionPublisher::publish()` assigns the next monotonic revision, injects the real revision number into the manifest, signs it, writes the revision row, writes overlays if any, and moves `domains.active_revision_id` inside the same transaction (`app/Runtime/Publisher/RevisionPublisher.php:50-99`).
   Post-commit, it mirrors to Redis, publishes `domain-config-updated`, and clears some Laravel caches (`app/Runtime/Publisher/RevisionPublisher.php:117-151`).

3. Active pointer
   `RevisionResolver::resolveActive()` is the live pointer reader. It looks up a manifest-enabled domain, requires a non-null `active_revision_id`, then loads the published revision behind that pointer (`app/Runtime/Consumer/RevisionResolver.php:37-63`).
   Live evidence: `GET https://cookies.ypsilon.dev/api/proxy-config/duftz.de` at 2026-03-28 05:02 Europe/Berlin returned `manifest.revision_number = 1` and `published_at = "2026-03-28T03:23:36+00:00"`, which means the live pointer is currently on revision 1 for site `00d983b9-2c95-40f5-8ac9-7bbdf9b7d605`.

4. Proxy consume
   Node fetches `GET /api/proxy-config/{host}` through `getDomainConfig()`, verifies the HMAC `X-Signature`, caches the result, and then applies manifest overrides if present (`services/proxy/laravel-client.js:383-493`, `services/proxy/server.js:193-219`).
   Live evidence: `GET https://cookies.ypsilon.dev/api/proxy-config/duftz.de` returned `200` with `X-Signature`, and `GET https://duftz.de/` returned `200` with `X-Proxy: ycookies`.

5. Manifest verification
   There are two distinct verification layers right now.
   Host-config verification is real and fail-closed: `laravel-client.js` throws on missing or bad HMAC signatures for `/api/proxy-config` (`services/proxy/laravel-client.js:442-467`).
   Manifest verification is not real fail-closed yet: `resolveManifestConfig()` only canonicalizes and hashes `base_artifact`, treats that as `signatureOk`, and falls back to legacy on error (`services/proxy/manifest-consumer.js:62-85`).

6. Projection path
   The actual current canary path is the dynamic script path, not the static loader path.
   `createHtmlInjector()` prefers `static_loader_url`, but it falls back to `script_url` when `static_loader_url` is absent (`services/proxy/html-injector.js:34-67`).
   `ProxyConfigController` includes a live static loader URL in the legacy host-config (`app/Http/Controllers/Api/ProxyConfigController.php:131-139`), but the manifest compiler hardcodes `static_loader_url => null` in the base artifact (`app/Runtime/Compiler/DomainCompiler.php:233-241`), and `applyManifestOverrides()` then overwrites bootstrapper fields with the manifest version (`services/proxy/manifest-consumer.js:145-149`).
   Live evidence matches that code path exactly:
   - `GET https://duftz.de/` injected `<script src="https://cookies.ypsilon.dev/api/script/00d983b9-2c95-40f5-8ac9-7bbdf9b7d605.js" id="ycookies-manager" ...>`.
   - Live `/api/proxy-config/duftz.de` exposed legacy `bootstrapper.static_loader_url = "https://cookies.ypsilon.dev/build/assets/manager-ByBGLVqn.js"` but manifest `base_artifact.bootstrapper.static_loader_url = null`.
   - `GET https://cookies.ypsilon.dev/api/script/00d983b9-2c95-40f5-8ac9-7bbdf9b7d605.js` returned a server-injected config object with `_manifest_revision: 1`, so `ScriptDeliveryController` is serving the manifest path (`app/Http/Controllers/Api/ScriptDeliveryController.php:30-45`, `app/Runtime/Consumer/ManifestConfigService.php:39-55`).
   - `GET https://cookies.ypsilon.dev/api/config/00d983b9-2c95-40f5-8ac9-7bbdf9b7d605` returned `200` with `X-Manifest-Revision: 1`, so the compatibility projection endpoint is live too (`app/Http/Controllers/Api/ManifestProjectionController.php:67-96`).

7. Drift validation
   Drift validation only exists on `/api/config/{site_id}`, not on the current `/api/script` hot path. The route is wrapped in `ManifestDiffValidator`, and the middleware only runs when `MANIFEST_DIFF_MODE=shadow` (`routes/api.php:29-32`, `config/runtime.php:29-32`, `app/Http/Middleware/ManifestDiffValidator.php:38-97`).

8. Fallback path
   In Node, `resolveManifestConfig()` returns `null` if the manifest block is missing, disabled, or fails local verification, and the proxy then keeps the legacy config (`services/proxy/manifest-consumer.js:40-60`, `services/proxy/manifest-consumer.js:136-152`).
   In Laravel, `/api/config`, `/api/script`, and `/api/boot` all fall back to legacy DB assembly when manifest mode is disabled or no revision is resolved (`app/Http/Controllers/Api/ManifestProjectionController.php:46-75`, `app/Http/Controllers/Api/ScriptDeliveryController.php:30-47`, `app/Http/Controllers/Api/BootstrapperController.php:36-62`).
   Live canary evidence: `GET https://cookies.ypsilon.dev/api/boot/00d983b9-2c95-40f5-8ac9-7bbdf9b7d605.js` returned `/* YCookies: No script blockers configured */`, so the blocker bootstrapper surface is live but currently inactive.

## 4. Implementation shortcuts or architectural drift

- The proxy host-config is still dual-source.
  `ProxyConfigController` assembles legacy truth from DB relations, then adds `manifest`; Node then merges manifest over that response. That is a migration shim, not the locked single-truth runtime (`app/Http/Controllers/Api/ProxyConfigController.php:73-172`, `services/proxy/manifest-consumer.js:136-152`).
- The static loader contract is implemented in the legacy host-config but not in the compiled manifest.
  Live proxy-config exposes a working static asset URL, and that asset currently returns `200`, but the manifest compiler still publishes `static_loader_url => null`, forcing the live canary down the dynamic `/api/script` path (`app/Runtime/Compiler/DomainCompiler.php:233-241`, `app/Http/Controllers/Api/ProxyConfigController.php:131-139`, `services/proxy/html-injector.js:34-67`).
- Manifest verification is still a shortcut, not the architecture.
  `ManifestSchema` says consumers verify the signed manifest and artifact hashes, but the proxy never receives or verifies enough material to do that, and `manifestMetrics.signatureOk` currently means "artifact hashed without throwing", not "signature verified" (`app/Runtime/Schema/ManifestSchema.php:95-103`, `app/Http/Controllers/Api/ProxyConfigController.php:267-275`, `services/proxy/manifest-consumer.js:62-85`).
- Manifest resolver cache invalidation is missing.
  `RevisionResolver::resolveActive()` caches `manifest_resolved:{domain}` for 300 seconds, but `RevisionPublisher` does not call `RevisionResolver::invalidate()`, and repository search only found the invalidate method itself. That means a newly published or rolled back pointer can remain invisible to all manifest consumers for up to 5 minutes (`app/Runtime/Consumer/RevisionResolver.php:37-63`, `app/Runtime/Consumer/RevisionResolver.php:95-98`, `app/Runtime/Publisher/RevisionPublisher.php:117-151`).
- Deploy/runtime values leak into immutable artifacts.
  `DomainCompiler` bakes `config('app.url')` into `script_url`, `boot_url`, and `api_base`, but `computeInputsHash()` does not include `app.url`. So deploy-time URL or asset-host changes can leave a manifest revision logically stale while the compiler still concludes "no changes" (`app/Runtime/Compiler/DomainCompiler.php:233-241`, `app/Runtime/Compiler/DomainCompiler.php:337-353`).
- Legacy and manifest defaults already disagree.
  The clearest case is `proxy.status`: legacy defaults to `active`, manifest defaults to `inactive`. The live `duftz.de` proxy-config response exposed both values at once (`app/Http/Controllers/Api/ProxyConfigController.php:119-123`, `app/Runtime/Compiler/DomainCompiler.php:263-267`).
- Compatibility projection is not yet field-for-field parity-locked.
  `ManifestConfigService` sets `theme` from `ui_config.theme`, returns `languages` straight from the compiled base, and passes `tcm_config` through as-is; legacy config builds theme from `cookieBar->theme_settings`, returns an active-language map, and injects defaults like `show_switcher`, `advanced_consent_mode`, and `mapping` (`app/Runtime/Consumer/ManifestConfigService.php:155-186`, `app/Runtime/Compiler/DomainCompiler.php:279-312`, `app/Http/Controllers/Api/ConsentConfigController.php:88-153`).
- The SDK still carries old data-shape assumptions.
  Current blocker payloads use `service` or `service_key`, but `manager.js` still references `blocker.service_id` in its script-blocker interception path (`app/Runtime/Compiler/DomainCompiler.php:155-165`, `app/Http/Controllers/Api/ConsentConfigController.php:263-272`, `resources/js/manager.js:2063-2068`).
- Old and new paths are still visibly coupled on the canary page.
  The live `duftz.de` HTML contains the injected YCookies manager script, but it also still contains Borlabs assets and DOM anchors such as `borlabs-cookie-core-js-module` and `BorlabsCookieBox`. That is not necessarily broken, but it is not a clean single-path runtime.

## 5. Review of the live canary readiness

- What is already proven
  The Node proxy is serving `duftz.de` live today.
  The public YCookies surfaces needed by the current hot path are presently healthy: `/api/proxy-config/duftz.de`, `/api/script/{site}.js`, `/api/config/{site}`, and the live static asset URL all returned `200` in the final review window.
  Manifest revision 1 is actually being consumed in production-facing code paths: live `/api/proxy-config` returned `manifest.revision_number = 1`, live `/api/config` returned `X-Manifest-Revision: 1`, and live `/api/script` returned `_manifest_revision: 1`.

- What is not yet proven
  Real fail-closed manifest verification is not proven because it is not implemented in the proxy.
  The static loader path is not proven on the live canary hot path because the page is still injected with `/api/script/...js`.
  Route overlays are not proven because the compiler is still base-only.
  Blocker enforcement is not proven because the live manifest has no blockers and the page showed no YCookies block markers.
  Full consumer cutover is not proven because the live page still carries Borlabs artifacts.

- What could still fail during wider rollout
  Domains with non-empty `script_blockers` or `content_blockers` may expose blocker-shape drift that `duftz.de` does not currently exercise.
  Multilingual or heavily themed domains may expose manifest-vs-legacy projection differences in `theme`, `languages`, `localization`, and `tcm_config`.
  Deploy-time URL or asset changes can stale compiled bootstrapper URLs without forcing a republish.
  The public API surface is currently healthy, but it was not stable for the full review session: repeated `504 Gateway Timeout` responses were observed from `cookies.ypsilon.dev` around 2026-03-28 04:53 Europe/Berlin before the later 04:57-05:02 recovery. I would not treat public API availability as fully proven stable yet.

- Whether any hidden rollback risk exists
  Yes.
  The biggest hidden risk is silent fallback: a broken or unverifiable manifest does not currently fail closed at the manifest layer; it quietly reuses legacy DB-composed config.
  There is also a delayed-state risk: because `manifest_resolved:{domain}` is cached for 300 seconds and not invalidated on publish or rollback, a rollback can be correct in the database while consumers continue serving the old revision for several minutes.

## 6. Top remaining technical risks

1. Manifest verification is still availability-leaning instead of fail-closed.
   A bad manifest can silently drop the system back onto legacy truth (`services/proxy/manifest-consumer.js:9-12`, `services/proxy/manifest-consumer.js:62-85`).
2. `RevisionResolver` cache invalidation is missing.
   New publishes and rollbacks can remain invisible for up to 5 minutes because `manifest_resolved:{domain}` is cached and never invalidated by the publish path (`app/Runtime/Consumer/RevisionResolver.php:37-63`, `app/Runtime/Consumer/RevisionResolver.php:95-98`, `app/Runtime/Publisher/RevisionPublisher.php:117-151`).
3. The runtime still has two truths.
   Proxy config, script delivery, config projection, and bootstrapper delivery can all fall back to legacy DB assembly, which makes drift easy to hide and hard to reason about (`app/Http/Controllers/Api/ProxyConfigController.php:73-172`, `app/Http/Controllers/Api/ScriptDeliveryController.php:49-83`, `app/Http/Controllers/Api/ManifestProjectionController.php:46-75`, `app/Http/Controllers/Api/BootstrapperController.php:64-88`).
4. The live canary hot path still depends on dynamic `/api/script` rather than an immutable static loader.
   That keeps the banner path coupled to dynamic API delivery and the projection controller path, and it leaves the locked static-loader design unproven on real traffic (`services/proxy/html-injector.js:34-67`, `app/Runtime/Compiler/DomainCompiler.php:233-241`).
5. Immutable artifacts can stale across deploy/env changes.
   `app.url` is compiled into the manifest bootstrapper contract but excluded from `compile_inputs_hash` (`app/Runtime/Compiler/DomainCompiler.php:233-241`, `app/Runtime/Compiler/DomainCompiler.php:337-353`).
6. Broader-domain parity risk remains in the projection layer and SDK.
   The current canary does not exercise the mismatched fields and blocker-shape assumptions that are still visible in `ManifestConfigService` and `manager.js` (`app/Runtime/Consumer/ManifestConfigService.php:155-186`, `resources/js/manager.js:2063-2068`).

## 7. What should be reviewed next before broader rollout

- `app/Runtime/Consumer/RevisionResolver.php` together with `app/Runtime/Publisher/RevisionPublisher.php` and `app/Jobs/CompileAndPublishRevision.php`.
  Review and fix post-publish and rollback invalidation of `manifest_resolved:{domain}` so pointer movement becomes immediately observable.
- `services/proxy/manifest-consumer.js`, `services/proxy/manifest-verifier.js`, `app/Http/Controllers/Api/ProxyConfigController.php`, and `app/Runtime/Publisher/RevisionSigner.php`.
  Review the exact manifest verification contract, pass the right verification material to Node, and remove the current silent legacy fallback once the canary gate is satisfied.
- `app/Runtime/Compiler/DomainCompiler.php` and `services/proxy/html-injector.js`.
  Review the bootstrapper contract specifically: the manifest needs to carry the static loader URL the proxy is supposed to inject, or the injector will continue forcing the canary down the old dynamic script path.
- `app/Runtime/Consumer/ManifestConfigService.php` versus `app/Http/Controllers/Api/ConsentConfigController.php`.
  Do a field-by-field parity review for `theme`, `languages`, `localization.show_switcher`, `tcm_config` defaults, and blocker/service field names.
- `resources/js/manager.js`.
  Review the blocker consumption paths, especially the `blocker.service_id` assumption, and decide what must remain in the client at all if the target architecture expects a thin runtime.
- Runtime surfaces, not just code.
  Review live `GET /api/proxy-config/{host}`, `GET /api/script/{site}.js`, `GET /api/config/{site}`, proxy logs, and `/statsz` immediately before and after a real publish and a real rollback. The current canary proves steady-state reads; it does not prove state transitions.
- A second canary domain with non-empty blockers and multilingual or themed data.
  `duftz.de` is a good structural canary, but it is a weak policy canary because the live manifest currently carries empty blocker arrays.

## 8. Final verdict

### Strong

* Compile, publish, signing, and revision-pointer mechanics are real and structurally correct.
* The live `duftz.de` canary is currently up on the Node proxy and is consuming manifest revision 1 in public-facing endpoints.
* Host-config HMAC verification and proxy fail-closed host lookup are implemented correctly.

### Acceptable but needs work

* Compatibility projection paths exist and are functioning, but they are still mixed with legacy fallback behavior.
* The live canary public API surface is healthy at the time of this review.
* The static loader asset is published and reachable, even though the current canary flow is not using it yet.

### Risky

* Manifest verification is not fail-closed and can silently fall back to legacy truth.
* `RevisionResolver` cache invalidation is missing, so publish and rollback visibility can lag by up to 5 minutes.
* Legacy and manifest contracts already disagree in live data, including bootstrapper and proxy-status fields.

### Not yet proven

* Static-loader-first delivery on live traffic.
* Real blocker enforcement on a domain with active blockers.
* Route overlays and route-specific policy consumption.
* Full old-path removal, including Borlabs coexistence risk and public API stability under sustained load.

### Recommendation

* Treat the current canary as structurally sound but not rollout-complete. Do not broaden rollout until manifest verification, resolver cache invalidation, and bootstrapper/static-loader contract drift are reviewed and corrected, then re-run the next review on a blocker-bearing canary domain.
