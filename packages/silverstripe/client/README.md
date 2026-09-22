# Browser SDK (npm)

Silverstripe loads the published package **`@newtalaria/browser`** from the npm CDN (jsDelivr), not from a local monorepo checkout.

- Package: https://www.npmjs.com/package/@newtalaria/browser
- URL pattern: `https://cdn.jsdelivr.net/npm/@newtalaria/browser@{version}/+esm`
- Version pin: `Talaria\SilverStripe\Config.browserSdkVersion` (default `0.2.2`)

Init payload includes filterable tags (`platform=web`, `runtime=silverstripe-cms|silverstripe-frontend`, `runtime_version`, `ss_env`, `host`, optional `service` / `browserTags` / CMS `ss.section`) and `userId` when a Member is logged in. The browser SDK also auto-tags browser/OS/device/bot and failed-request `http.*` / `network.*`.

CMS injects `inlineStylesheet: true` (auth-gated admin CSS) and `failedRequestStatusCodes: [[400, 599]]`. Frontend defaults to `inlineStylesheet: false` and `[[500, 599]]`. Override with `browserInlineStylesheet`, `browserCaptureFailedRequests`, and `browserFailedRequestStatusCodes` in YAML.

After `Talaria.init`, the Silverstripe inject assigns `window.Talaria` so page scripts can call `logger` / level methods / `captureException` / `flush` / `analytics` on the shipped singleton. YAML `enableAnalytics: true` adds `enableAnalytics` on **public pages only** (not CMS), which opts the browser SDK in and autocaptures `$pageview`.

No vendored JS bundle is required in this Composer package. After publishing a new npm release, bump `browserSdkVersion` and flush.
