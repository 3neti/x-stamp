<?php

use LBHurtado\XStamp\Appearance;
use LBHurtado\XStamp\BrowserRenderer;
use LBHurtado\XStamp\Catalog;
use LBHurtado\XStamp\QrPresentation;
use LBHurtado\XStamp\Renderer;
use LBHurtado\XStamp\RenderingProfile;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

it('resolves bounded portable profiles', function () {
    expect(RenderingProfile::resolve('standard')->driver())->toBe('gd');
    expect(RenderingProfile::resolve('browser')->scale())->toBe(1);
    expect(RenderingProfile::resolve('browser-hd')->scale())->toBe(2);
    expect(fn () => RenderingProfile::resolve('arbitrary'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new BrowserRenderer(timeout: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new BrowserRenderer)->screenshot('', 1200, 630, RenderingProfile::Standard))->toThrow(InvalidArgumentException::class);
});

it('renders native browser pixels at the selected density', function (RenderingProfile $profile) {
    if (! getenv('STAMP_BROWSER_MODULE_PATH')) {
        $this->markTestSkipped('Set STAMP_BROWSER_MODULE_PATH to run Chromium integration coverage.');
    }
    $catalog = new Catalog;
    $catalog->load(__DIR__.'/Fixtures/collection');
    $renderer = new BrowserRenderer(nodeModulePath: getenv('STAMP_BROWSER_MODULE_PATH'), chromePath: getenv('STAMP_BROWSER_CHROME') ?: null);
    $bytes = $renderer->png($catalog->resolve('cats', 1), ['amount' => '₱500.00'], profile: $profile);
    $info = getimagesizefromstring($bytes);
    expect([$info[0], $info[1], $info[2]])->toBe([1200 * $profile->scale(), 630 * $profile->scale(), IMAGETYPE_PNG]);
})->with([RenderingProfile::Browser, RenderingProfile::BrowserHd]);

it('creates QR backing at native density and preserves logical bounds', function () {
    $source = imagecreatetruecolor(480, 480);
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);
    $catalog = new Catalog;
    $catalog->load(__DIR__.'/Fixtures/collection');
    $composition = (new QrPresentation)->compose($catalog->resolve('cats', 1), $bytes, 'AA317', 'qr', 'bottom_right', '#FFFFFF', '#000000', Appearance::Presets['transparent'], 2);
    expect(getimagesizefromstring($composition['images']['stamp_marker_qr'])[0])->toBe(380);
    expect($composition['bounds']['width'])->toBe(190);
});

it('layers the complete HTML artwork once below the system frame', function () {
    $catalog = new Catalog;
    $catalog->load(__DIR__.'/Fixtures/collection');
    $html = (new Renderer)->artworkHtml($catalog->resolve('cats', 1), '<h2>Unique artwork title</h2>', ['amount' => '₱500.00']);
    expect(substr_count($html, 'Unique artwork title'))->toBe(1);
    expect($html)->toContain('stamp-artwork', 'stamp-frame', '₱500.00', 'default-src');
});

it('fails closed when an artwork image cannot finish loading', function () {
    if (! getenv('STAMP_BROWSER_MODULE_PATH')) {
        $this->markTestSkipped('Set STAMP_BROWSER_MODULE_PATH to run Chromium integration coverage.');
    }
    $renderer = new BrowserRenderer(nodeModulePath: getenv('STAMP_BROWSER_MODULE_PATH'), chromePath: getenv('STAMP_BROWSER_CHROME') ?: null, timeout: 2);
    expect(fn () => $renderer->screenshot('<img src="https://example.invalid/never.png">', 1200, 630))
        ->toThrow(ProcessRuntimeException::class);
});
