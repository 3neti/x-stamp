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
