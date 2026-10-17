<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;
use Opis\JsonSchema\Validator;

final class Catalog
{
    /** @var array<string, Design> */
    private array $designs = [];

    public function load(string $directory): void
    {
        $root = realpath($directory);
        if ($root === false) {
            throw new InvalidArgumentException('Missing Stamp collection.');
        }
        if (! is_file($root.'/collection.json') || filesize($root.'/collection.json') > 1000000) {
            throw new InvalidArgumentException('Missing or oversized Stamp collection manifest.');
        }
        $json = json_decode(file_get_contents($root.'/collection.json'), false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(__DIR__.'/../resources/schema/collection-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        if (! (new Validator)->validate($json, $schema)->isValid()) {
            throw new InvalidArgumentException('Invalid Stamp collection manifest.');
        }
        $manifest = json_decode(json_encode($json, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $assets = [];
        foreach ($manifest['files'] as $name => $checksum) {
            $path = realpath($root.'/'.$name);
            if (! is_string($name) || str_contains($name, '..') || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > 10000000 || ! is_string($checksum) || ! hash_equals($checksum, hash_file('sha256', $path))) {
                throw new InvalidArgumentException('Invalid Stamp asset path or checksum.');
            }
            $assets[$name] = $path;
        }
        foreach ($manifest['thumbnails'] ?? [] as $name) {
            if (! isset($assets[$name])) {
                throw new InvalidArgumentException('Unregistered Stamp thumbnail.');
            }
        }
        $pending = [];
        foreach ($manifest['designs'] as $name) {
            $path = $assets[$name] ?? throw new InvalidArgumentException('Unregistered Stamp document.');
            $design = new Design(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $assets);
            $key = $design->id().'@'.$design->revision();
            if (isset($this->designs[$key]) || isset($pending[$key])) {
                throw new InvalidArgumentException('Duplicate Stamp design revision.');
            }
            $pending[$key] = $design;
        }
        $this->designs += $pending;
    }

    public function resolve(string $id, int $revision): Design
    {
        return $this->designs[$id.'@'.$revision] ?? throw new InvalidArgumentException('Unavailable Stamp design revision.');
    }

    /** @return list<Design> */
    public function all(): array
    {
        return array_values($this->designs);
    }
}
