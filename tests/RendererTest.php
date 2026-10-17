<?php

use LBHurtado\XStamp\Catalog;
use LBHurtado\XStamp\Design;
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
