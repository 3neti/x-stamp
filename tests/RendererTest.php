<?php

use LBHurtado\XStamp\Appearance;
use LBHurtado\XStamp\Catalog;
use LBHurtado\XStamp\Design;
use LBHurtado\XStamp\IdentifierPresentation;
use LBHurtado\XStamp\QrPresentation;
use LBHurtado\XStamp\Renderer;

function stampCatalog(): Catalog
{
    $catalog = new Catalog;
    $catalog->load(__DIR__.'/Fixtures/collection');

    return $catalog;
}

it('renders every installed design as independent HTML SVG and PNG', function () {
    $renderer = new Renderer;
    foreach (stampCatalog()->all() as $design) {
        $slots = ['brand' => 'Pay Code', 'amount' => '₱1,234.56', 'recipient' => 'For José', 'purpose' => 'Happy day'];
        $html = $renderer->html($design, $slots);
        expect($html)->toContain('₱1,234.56', 'José', 'color-scheme:only light')->not->toContain('prefers-color-scheme', '<script');
        $image = imagecreatefromstring($renderer->png($design, $slots));
        expect(imagesx($image))->toBe(1200);
        expect(imagesy($image))->toBe(630);
        imagedestroy($image);
    }
});

it('escapes slot markup instead of executing it', function () {
    $html = (new Renderer)->html(stampCatalog()->resolve('steampunk', 1), ['brand' => '<script>alert(1)</script>']);
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>');
});

it('wraps long text within its box and truncates overflow consistently', function () {
    $design = stampCatalog()->resolve('cats', 1);
    $layers = (new Renderer)->layout($design, ['purpose' => str_repeat('Long recipient words ', 40)]);
    $text = array_values(array_filter($layers, fn ($layer) => ($layer['slot'] ?? null) === 'purpose'));
    expect($text)->toHaveCount(2);
    expect($text[1]['text'])->toEndWith('…');
    expect($text[1]['baseline'])->toBeLessThan(455);
});

it('rejects unknown revisions rather than switching designs', function () {
    stampCatalog()->resolve('cats', 2);
})->throws(InvalidArgumentException::class, 'Unavailable');

it('rejects unsupported executable layers and unexpected fields', function (string $field, mixed $value) {
    $design = stampCatalog()->resolve('cats', 1);
    $document = $design->document;
    $document[$field] = $value;
    new Design($document, []);
})->with([['script', 'alert(1)'], ['schema', 'x-stamp/99']])->throws(InvalidArgumentException::class);

it('rejects duplicate registrations atomically', function () {
    $catalog = stampCatalog();
    expect(fn () => $catalog->load(__DIR__.'/Fixtures/collection'))->toThrow(InvalidArgumentException::class, 'Duplicate');
    expect($catalog->all())->toHaveCount(3);
});

it('rejects asset corruption and paths outside the collection', function (string $path, string $hash) {
    $directory = sys_get_temp_dir().'/stamp-test-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/collection.json', json_encode(['schema' => 'x-stamp-collection/1', 'id' => 'test', 'files' => [$path => $hash], 'designs' => ['stamp.json']]));
    try {
        (new Catalog)->load($directory);
    } finally {
        unlink($directory.'/collection.json');
        rmdir($directory);
    }
})->with([['../outside.ttf', str_repeat('a', 64)], ['missing.ttf', str_repeat('0', 64)]])->throws(InvalidArgumentException::class);

it('rejects changed bytes even when an asset exists', function () {
    $directory = sys_get_temp_dir().'/stamp-corrupt-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/asset.ttf', 'changed bytes');
    file_put_contents($directory.'/collection.json', json_encode(['schema' => 'x-stamp-collection/1', 'id' => 'test', 'files' => ['asset.ttf' => str_repeat('0', 64)], 'designs' => ['asset.ttf']]));
    try {
        expect(fn () => (new Catalog)->load($directory))->toThrow(InvalidArgumentException::class, 'checksum');
    } finally {
        unlink($directory.'/collection.json');
        unlink($directory.'/asset.ttf');
        rmdir($directory);
    }
});

it('keeps the registered document unchanged when composing a layout variant', function () {
    $design = stampCatalog()->resolve('steampunk', 1);
    $layers = $design->document['layers'];
    $layers[0]['color'] = '#000000';
    $variant = $design->withLayers($layers);
    expect($variant->document['layers'][0]['color'])->toBe('#000000');
    expect($design->document['layers'][0]['color'])->toBe('#A16207');
});

it('wraps at word boundaries instead of splitting a word across lines', function () {
    $original = stampCatalog()->resolve('steampunk', 1);
    $layers = $original->document['layers'];
    foreach ($layers as &$layer) {
        if (($layer['slot'] ?? null) === 'purpose') {
            $layer['width'] = 120;
        }
    }
    unset($layer);
    $layout = (new Renderer)->layout($original->withLayers($layers), ['purpose' => 'Words go around']);
    $text = array_values(array_filter($layout, fn ($layer) => ($layer['slot'] ?? null) === 'purpose'));
    expect(array_column($text, 'text'))->toBe(['Words go', 'around']);
});

it('preserves explicit sentence line breaks in every rendered format', function () {
    $renderer = new Renderer;
    $design = stampCatalog()->resolve('steampunk', 1);
    $slots = ['purpose' => "Money should adapt to people.\nNot the other way around."];
    $text = array_values(array_filter($renderer->layout($design, $slots), fn ($layer) => ($layer['slot'] ?? null) === 'purpose'));
    expect(array_column($text, 'text'))->toBe(['Money should adapt to people.', 'Not the other way around.']);
    expect($text[1]['baseline'])->toBeGreaterThan($text[0]['baseline']);
    expect($renderer->svg($design, $slots))->toContain('>Money should adapt to people.</text>', '>Not the other way around.</text>');
    expect(getimagesizefromstring($renderer->png($design, $slots))[0])->toBe(1200);
});

it('bounds an unbreakable token with an ellipsis instead of overflowing its text box', function () {
    $design = stampCatalog()->resolve('steampunk', 1);
    $layout = (new Renderer)->layout($design, ['purpose' => str_repeat('W', 200)]);
    $text = array_values(array_filter($layout, fn ($layer) => ($layer['slot'] ?? null) === 'purpose'));
    expect($text)->toHaveCount(1);
    expect($text[0]['text'])->toEndWith('…');
    $box = imagettfbbox($text[0]['size'] * .75, 0, $design->asset($design->document['font']), $text[0]['text']);
    expect(abs($box[2] - $box[0]))->toBeLessThanOrEqual($text[0]['width']);
});

it('fills an image box with a centered crop while keeping legacy stretch behavior', function (bool $portrait) {
    $path = tempnam(sys_get_temp_dir(), 'stamp-cover-');
    $artwork = imagecreatetruecolor($portrait ? 100 : 300, $portrait ? 300 : 100);
    $red = imagecolorallocate($artwork, 255, 0, 0);
    $green = imagecolorallocate($artwork, 0, 255, 0);
    $blue = imagecolorallocate($artwork, 0, 0, 255);
    imagefill($artwork, 0, 0, $red);
    imagefilledrectangle($artwork, $portrait ? 0 : 100, $portrait ? 100 : 0, $portrait ? 99 : 199, $portrait ? 199 : 99, $green);
    imagefilledrectangle($artwork, $portrait ? 0 : 200, $portrait ? 200 : 0, $portrait ? 99 : 299, $portrait ? 299 : 99, $blue);
    imagepng($artwork, $path);
    imagedestroy($artwork);
    try {
        $base = stampCatalog()->resolve('steampunk', 1);
        $layer = ['type' => 'image', 'x' => 0, 'y' => 0, 'width' => 120, 'height' => 120, 'color' => '#000000', 'asset' => 'photo.png'];
        $assets = [$base->document['font'] => $base->asset($base->document['font']), 'photo.png' => $path];
        $cover = new Design([...$base->document, 'layers' => [[...$layer, 'fit' => 'cover']]], $assets);
        $stretch = new Design([...$base->document, 'layers' => [$layer]], $assets);
        $renderer = new Renderer;
        $image = imagecreatefromstring($renderer->png($cover));
        foreach ([[5, 5], [60, 60], [115, 115]] as [$x, $y]) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            expect([$pixel['red'], $pixel['green'], $pixel['blue']])->toBe([0, 255, 0]);
        }
        imagedestroy($image);
        $image = imagecreatefromstring($renderer->png($stretch));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        expect([$pixel['red'], $pixel['green'], $pixel['blue']])->toBe([255, 0, 0]);
        imagedestroy($image);
        expect($renderer->svg($cover))->toContain('preserveAspectRatio="xMidYMid slice"');
        expect($renderer->svg($stretch))->toContain('preserveAspectRatio="none"');
    } finally {
        unlink($path);
    }
})->with([false, true]);

function runtimeImageDesign(): Design
{
    $base = stampCatalog()->resolve('cats', 1);

    return $base->withLayers([[
        'type' => 'image', 'slot' => 'provider_logo', 'fit' => 'contain',
        'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'color' => '#000000',
    ]]);
}

it('renders optional runtime images without stretching or filling their transparent pixels', function () {
    $design = runtimeImageDesign();
    $source = imagecreatetruecolor(80, 40);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
    imagefilledrectangle($source, 20, 0, 59, 39, imagecolorallocate($source, 0, 255, 0));
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);
    $renderer = new Renderer;

    $image = imagecreatefromstring($renderer->png($design, [], ['provider_logo' => $bytes]));
    $background = imagecolorsforindex($image, imagecolorat($image, 5, 50));
    expect([$background['red'], $background['green'], $background['blue']])->toBe([245, 239, 230]);
    $center = imagecolorsforindex($image, imagecolorat($image, 50, 50));
    expect([$center['red'], $center['green'], $center['blue']])->toBe([0, 255, 0]);
    expect(imagecolorat($image, 50, 5))->toBe(imagecolorat($image, 5, 50));
    imagedestroy($image);
    expect($renderer->svg($design, [], ['provider_logo' => $bytes]))->toContain('xMidYMid meet', 'data:image/png;base64,');
    expect($renderer->html($design, [], ['provider_logo' => $bytes]))->toContain(base64_encode($bytes));
    expect($renderer->svg($design))->not->toContain('<image');
    expect($renderer->layout($design))->toBeEmpty();
});

it('rejects unsafe runtime image input without reading files or URLs', function (string $input) {
    (new Renderer)->png(runtimeImageDesign(), [], ['provider_logo' => $input]);
})->with(['https://example.com/logo.png', '/etc/passwd', '<svg><script/></svg>', str_repeat('x', 2000001)])
    ->throws(InvalidArgumentException::class);

it('rejects ambiguous bundled and runtime image sources', function () {
    $base = runtimeImageDesign();
    $layers = $base->document['layers'];
    $layers[0]['asset'] = 'logo.png';
    $base->withLayers($layers);
})->throws(InvalidArgumentException::class);

function editableStampDesign(): Design
{
    $base = stampCatalog()->resolve('cats', 1);
    $document = [...$base->document, 'customization' => [
        'controls' => ['crop', 'darkening', 'tagline', 'qr'], 'artwork_layer' => 0,
        'defaults' => ['preset' => 'transparent', 'darkening' => 0, 'crop' => 'center', 'show_tagline' => true],
    ]];
    $document['layers'] = [['type' => 'image', 'slot' => 'photo', 'fit' => 'cover', 'x' => 0, 'y' => 0, 'width' => 1200, 'height' => 630, 'color' => '#000000']];

    return new Design($document, [$base->document['font'] => $base->asset($base->document['font'])]);
}

it('resolves portable presets and preserves frozen values after configuration changes', function () {
    $appearance = new Appearance;
    $design = editableStampDesign();
    $transparent = $appearance->resolve($design);
    expect($transparent['qr'])->toBe(['paper' => .5, 'logo_backing' => .3, 'ink' => .9, 'frame' => .3]);
    expect($appearance->resolve($design, ['preset' => 'clear'])['qr']['paper'])->toBe(1.0);
    expect($appearance->resolve($design, [], ['presets' => ['transparent' => ['paper' => .8]]], $transparent['qr']))->toBe($transparent);
    $changed = $appearance->apply($design, $appearance->resolve($design, ['crop' => 'bottom', 'darkening' => 40]));
    expect($changed->document['layers'][0]['anchor'])->toBe('bottom');
    expect($changed->document['layers'][1]['opacity'])->toBe(.4);
    expect($design->document['layers'])->toHaveCount(1);
});

it('rejects unsupported or invalid appearance options', function (array $options) {
    (new Appearance)->resolve(editableStampDesign(), $options);
})->with([[['preset' => 'unsafe']], [['preset' => []]], [['darkening' => 41]], [['crop' => 'sideways']], [['show_tagline' => 'false']], [['arbitrary_css' => 'bad']]])->throws(InvalidArgumentException::class);

it('keeps unsupported controls out of historical templates', function () {
    (new Appearance)->resolve(stampCatalog()->resolve('cats', 1), ['darkening' => 10]);
})->throws(InvalidArgumentException::class);

it('renders opacity equally in portable PNG and SVG layers', function () {
    $base = stampCatalog()->resolve('cats', 1);
    $design = $base->withLayers([['type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'color' => '#000000', 'opacity' => .4]]);
    $renderer = new Renderer;
    $image = imagecreatefromstring($renderer->png($design));
    $pixel = imagecolorsforindex($image, imagecolorat($image, 10, 10));
    expect($pixel['red'])->toBe(147);
    expect($renderer->svg($design))->toContain('opacity="0.4"');
    imagedestroy($image);
});

it('composes portable QR opacity and bounds without changing the original design', function () {
    $base = stampCatalog()->resolve('cats', 1);
    $source = imagecreatetruecolor(256, 256);
    imagefill($source, 0, 0, imagecolorallocate($source, 255, 255, 255));
    imagefilledrectangle($source, 10, 10, 30, 30, imagecolorallocate($source, 0, 0, 0));
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);
    $renderer = new QrPresentation;
    $png = $renderer->png($bytes, '#F8EDD4', '#DDBB84', Appearance::Presets['transparent']);
    $image = imagecreatefromstring($png);
    $frame = imagecolorsforindex($image, imagecolorat($image, 0, 0));
    expect($frame['alpha'])->toBe(89);
    $paper = imagecolorsforindex($image, imagecolorat($image, 12, 12));
    expect($paper['alpha'])->toBeLessThan(89);
    $composed = $renderer->compose($base, $bytes, 'ABCD', 'both', 'top_left', '#F8EDD4', '#DDBB84', Appearance::Presets['clear']);
    expect($composed['bounds'])->toBe(['x' => 54, 'y' => 54, 'width' => 190, 'height' => 226]);
    expect($composed['images'])->toHaveKey('stamp_marker_qr');
    expect($base->slots())->not->toContain('stamp_marker_qr');
    imagedestroy($image);
});

it('rejects non-PNG or oversized QR inputs without external reads', function (string $bytes) {
    (new QrPresentation)->png($bytes, '#FFFFFF', '#FFFFFF', Appearance::Presets['clear']);
})->with(['https://example.test/qr.png', '<svg/>', str_repeat('x', 2000001)])->throws(InvalidArgumentException::class);

it('anchors cover artwork at the selected top or bottom edge in PNG and SVG', function () {
    $source = imagecreatetruecolor(100, 300);
    imagefill($source, 0, 0, imagecolorallocate($source, 255, 0, 0));
    imagefilledrectangle($source, 0, 200, 99, 299, imagecolorallocate($source, 0, 0, 255));
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);
    $appearance = new Appearance;
    $renderer = new Renderer;
    foreach (['top' => [255, 0, 0], 'bottom' => [0, 0, 255]] as $anchor => $rgb) {
        $design = $appearance->apply(editableStampDesign(), $appearance->resolve(editableStampDesign(), ['crop' => $anchor]));
        $image = imagecreatefromstring($renderer->png($design, [], ['photo' => $bytes]));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 600, 315));
        expect([$pixel['red'], $pixel['green'], $pixel['blue']])->toBe($rgb);
        expect($renderer->svg($design, [], ['photo' => $bytes]))->toContain($anchor === 'top' ? 'xMidYMin slice' : 'xMidYMax slice');
        imagedestroy($image);
    }
});

it('hides retired designs from choices while retaining immutable rendering and archival enumeration', function () {
    $directory = sys_get_temp_dir().'/stamp-retirement-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $original = stampCatalog()->resolve('valentine', 1);
    $document = $original->document;
    $document['font'] = 'font.ttf';
    $document['layers'] = array_values(array_filter($document['layers'], fn (array $layer): bool => $layer['type'] !== 'image'));
    file_put_contents($directory.'/stamp.json', json_encode($document));
    copy($original->asset($original->document['font']), $directory.'/font.ttf');
    $manifest = ['schema' => 'x-stamp-collection/1', 'id' => 'test', 'designs' => ['stamp.json'], 'files' => ['stamp.json' => hash_file('sha256', $directory.'/stamp.json'), 'font.ttf' => hash_file('sha256', $directory.'/font.ttf')], 'retired_designs' => ['valentine']];
    file_put_contents($directory.'/collection.json', json_encode($manifest));
    try {
        $catalog = new Catalog;
        $catalog->load($directory);
        expect($catalog->selectable())->toBeEmpty();
        expect($catalog->all())->toHaveCount(1);
        $saved = $catalog->resolve('valentine', 1);
        expect($saved->id())->toBe('valentine');
        expect(getimagesizefromstring((new Renderer)->png($saved, ['amount' => '₱500']))[0])->toBe(1200);
        $manifest['retired_designs'] = ['missing'];
        file_put_contents($directory.'/collection.json', json_encode($manifest));
        $invalid = new Catalog;
        expect(fn () => $invalid->load($directory))->toThrow(InvalidArgumentException::class, 'belong to its collection');
        expect($invalid->all())->toBeEmpty();
    } finally {
        unlink($directory.'/collection.json');
        unlink($directory.'/stamp.json');
        unlink($directory.'/font.ttf');
        rmdir($directory);
    }
});

it('renders branded one-line identifiers and labels only draft examples as samples', function () {
    $design = stampCatalog()->resolve('cats', 1);
    $presentation = new IdentifierPresentation;
    $sample = $presentation->compose($design, 'AA317', true);
    $issued = $presentation->compose($design, 'ABCD1234');
    expect($sample['label'])->toBe('|| AA317 ||')->and($sample['sample'])->toBeTrue();
    expect($issued['label'])->toBe('|| ABCD1234 ||')->and($issued['sample'])->toBeFalse();
    expect($sample['bounds']['x'] + $sample['bounds']['width'])->toBe(1146);
    expect($sample['bounds']['y'])->toBe(62);
    expect($presentation->compose($design, 'AA317')['images'])->not->toBe($sample['images']);
    $renderer = new Renderer;
    expect($renderer->svg($issued['design'], [], $issued['images']))->toContain('data:image/png;base64,');
    expect(getimagesizefromstring($renderer->png($issued['design'], [], $issued['images']))[0])->toBe(1200);
    $long = $presentation->compose($design, str_repeat('W', 80));
    expect($long['label'])->toBe('|| '.str_repeat('W', 80).' ||');
    expect($long['bounds']['width'])->toBeLessThanOrEqual(528);
});

it('keeps an upper-right QR below the identifier header without changing the registered design', function () {
    $original = stampCatalog()->resolve('cats', 1);
    $header = (new IdentifierPresentation)->compose($original, 'AA317', true);
    $composition = (new QrPresentation)->compose($header['design'], null, 'AA317', 'qr', 'top_right', '#FFFFFF', '#000000', Appearance::Presets['transparent']);
    expect($composition['bounds']['y'])->toBeGreaterThanOrEqual($header['bounds']['y'] + $header['bounds']['height']);
    expect($original->document['layers'])->not->toBe($header['design']->document['layers']);
});

it('rejects invalid or oversized Stamp identifiers', function (string $code) {
    (new IdentifierPresentation)->compose(stampCatalog()->resolve('cats', 1), $code);
})->with(['', '<script>', str_repeat('A', 81)])->throws(InvalidArgumentException::class);
