<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;

final class Appearance
{
    public const array Presets = [
        'clear' => ['paper' => 1.0, 'logo_backing' => 1.0, 'ink' => 1.0, 'frame' => 1.0],
        'soft' => ['paper' => .9, 'logo_backing' => .9, 'ink' => .9, 'frame' => .3],
        'transparent' => ['paper' => .5, 'logo_backing' => .3, 'ink' => .9, 'frame' => .3],
    ];

    /** @param array<string, mixed> $options @param array<string, mixed> $configuration @param array<string, mixed>|null $frozen @return array{options: array<string, mixed>, qr: array<string, float|int>} */
    public function resolve(Design $design, array $options = [], array $configuration = [], ?array $frozen = null): array
    {
        $capabilities = $design->document['customization'] ?? null;
        if ($capabilities === null && $options !== []) {
            throw new InvalidArgumentException('This saved design does not support appearance overrides.');
        }
        $allowed = ['preset', 'crop', 'darkening', 'show_tagline'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported Stamp appearance option.');
        }
        $defaults = ['preset' => 'transparent', 'crop' => 'center', 'darkening' => 0, 'show_tagline' => true];
        $controls = $capabilities['controls'] ?? [];
        $configuredDefaults = $configuration['defaults'] ?? [];
        foreach (['preset' => 'qr', 'crop' => 'crop', 'darkening' => 'darkening', 'show_tagline' => 'tagline'] as $option => $control) {
            if (! in_array($control, $controls, true)) {
                unset($configuredDefaults[$option]);
            }
        }
        $result = array_replace($defaults, $capabilities['defaults'] ?? [], $configuredDefaults, $options);
        if (! is_string($result['preset']) || ! array_key_exists($result['preset'], self::Presets)
            || ! in_array($result['crop'], ['top', 'center', 'bottom'], true)
            || ! is_int($result['darkening']) || $result['darkening'] < 0 || $result['darkening'] > 40
            || ! is_bool($result['show_tagline'])) {
            throw new InvalidArgumentException('Invalid Stamp appearance values.');
        }
        foreach (['preset' => 'qr', 'crop' => 'crop', 'darkening' => 'darkening', 'show_tagline' => 'tagline'] as $option => $control) {
            if (array_key_exists($option, $options) && ! in_array($control, $controls, true) && $options[$option] !== $defaults[$option]) {
                throw new InvalidArgumentException("This design does not support {$control} customization.");
            }
        }
        $qr = $frozen ?? array_replace(self::Presets[$result['preset']], $configuration['presets'][$result['preset']] ?? []);
        $this->validateQr($qr);

        return ['options' => $result, 'qr' => $qr];
    }

    /** @param array<string, mixed> $qr */
    public function validateQr(array $qr): void
    {
        if (array_diff(array_keys($qr), ['paper', 'logo_backing', 'ink', 'frame']) !== [] || count($qr) !== 4) {
            throw new InvalidArgumentException('Invalid QR appearance settings.');
        }
        foreach (['paper' => .5, 'logo_backing' => .3, 'ink' => .9, 'frame' => 0] as $key => $minimum) {
            if (! isset($qr[$key]) || ! is_numeric($qr[$key]) || $qr[$key] < $minimum || $qr[$key] > 1) {
                throw new InvalidArgumentException('QR appearance is outside its supported range.');
            }
        }
    }

    /** @param array{options: array<string, mixed>, qr: array<string, float|int>} $appearance */
    public function apply(Design $design, array $appearance): Design
    {
        $metadata = $design->document['customization'] ?? null;
        if ($metadata === null) {
            return $design;
        }
        $layers = $design->document['layers'];
        $index = $metadata['artwork_layer'];
        if (! isset($layers[$index]) || $layers[$index]['type'] !== 'image') {
            throw new InvalidArgumentException('The editable artwork layer is unavailable.');
        }
        if (in_array('crop', $metadata['controls'], true)) {
            $layers[$index]['anchor'] = $appearance['options']['crop'];
        }
        if ($appearance['options']['darkening'] > 0) {
            array_splice($layers, $index + 1, 0, [[
                'type' => 'rectangle', 'x' => 0, 'y' => 0,
                'width' => $design->document['width'], 'height' => $design->document['height'],
                'color' => '#000000', 'opacity' => $appearance['options']['darkening'] / 100,
            ]]);
        }

        return $design->withLayers($layers);
    }
}
