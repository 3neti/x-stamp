<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;

final readonly class Design
{
    /** @param array<string, mixed> $document @param array<string, string> $assets */
    public function __construct(public array $document, private array $assets)
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../resources/schema/stamp-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        $result = (new Validator)->validate(json_decode(json_encode($document, JSON_THROW_ON_ERROR)), $schema);
        if (! $result->isValid()) {
            throw new InvalidArgumentException('Invalid x-stamp/1 design document.');
        }
        $this->asset($document['font']);
        foreach ($document['layers'] as $layer) {
            if ($layer['width'] <= 0 || ($layer['type'] !== 'line' && $layer['height'] <= 0)) {
                throw new InvalidArgumentException('Stamp layers require positive dimensions.');
            }
            if ($document['width'] < $layer['x'] + $layer['width'] || $document['height'] < $layer['y'] + $layer['height']) {
                throw new InvalidArgumentException('A Stamp layer exceeds the canvas.');
            }
            if ($layer['type'] === 'image') {
                $path = $this->asset($layer['asset'] ?? '');
                $info = @getimagesize($path);
                if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 16000000) {
                    throw new InvalidArgumentException('Stamp artwork must be a bounded PNG, JPEG, or WebP image.');
                }
            }
        }
        if (@imagettfbbox(12, 0, $this->asset($document['font']), 'Stamp') === false) {
            throw new InvalidArgumentException('Invalid Stamp font.');
        }
    }

    /** @param list<array<string, mixed>> $layers */
    public function withLayers(array $layers): self
    {
        return new self([...$this->document, 'layers' => $layers], $this->assets);
    }

    public function asset(string $name): string
    {
        return $this->assets[$name] ?? throw new InvalidArgumentException('Unregistered Stamp asset.');
    }

    public function id(): string
    {
        return $this->document['id'];
    }

    public function revision(): int
    {
        return $this->document['revision'];
    }

    /** @return list<string> */
    public function slots(): array
    {
        return array_values(array_unique(array_filter(array_column($this->document['layers'], 'slot'))));
    }
}
