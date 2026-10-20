
## Optional Chromium profiles

`RenderingProfile` defines `standard` (GD, 1×), `browser` (Chromium, 1×), and
`browser-hd` (Chromium, 2×). A 1200×630 design remains a 1200×630 logical canvas;
HD produces 2400×1260 pixels from the HTML/SVG document, not an enlarged PNG.
GD remains independent of Chromium.

Install `spatie/browsershot:^5.4` in the consuming application, with a compatible
Node/Puppeteer/Chrome runtime. The package uses Browsershot as a development
dependency and suggests it for consumers; executable paths are deployment
settings and never design fields.

```php
use LBHurtado\XStamp\BrowserRenderer;
use LBHurtado\XStamp\RenderingProfile;

$renderer = new BrowserRenderer(
    nodeBinary: '/path/to/node',
    nodeModulePath: '/path/to/node_modules',
    chromePath: '/path/to/chrome',
    timeout: 20,
);
$png = $renderer->png($design, $slots, $images, RenderingProfile::BrowserHd);
```

The adapter disables page JavaScript, redirects and external HTTP/network
requests, waits for fonts and images, validates output dimensions and propagates
runtime errors. It never silently substitutes GD. Keep browser sandboxing enabled.
`Renderer::artworkHtml()` accepts **application-sanitized, self-contained HTML**;
it does not sanitize arbitrary input. It replaces static template artwork beneath
the retained frame. Inline approved raster assets before rendering. The fixed
artwork viewport uses cover sizing for its image. Utility styles are deliberately
bounded; this is not a full Tailwind runtime or an arbitrary website screenshot.

`QrPresentation` and `IdentifierPresentation` accept an optional final scale of
1 or 2. Supply a natively dense source QR; increasing presentation density does
not create detail missing from its input. Logical marker/header bounds stay fixed.

Run `composer test` for portable coverage. To include real Chromium integration:

```bash
STAMP_BROWSER_MODULE_PATH=/path/to/node_modules \
STAMP_BROWSER_CHROME=/path/to/chrome composer test
```

Browser integration tests skip when the module-path variable is absent. Missing
runtime dependencies, timeouts and failed assets must be resolved before enabling
these profiles in an application. Pin your Node/Puppeteer/Chrome versions when
reproducible pixels matter; browser upgrades can change rasterization and emoji.
