<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;
use RuntimeException;

final class IdentifierPresentation
{
    /** @return array{design: Design, images: array<string, string>, label: string, sample: bool, bounds: array{x: int, y: int, width: int, height: int}} */
    public function compose(Design $design, string $identifier, bool $sample = false, int $scale = 1): array
    {
        if (! in_array($scale, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported identifier pixel density.');
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $identifier)) {
            throw new InvalidArgumentException('Invalid Stamp identifier.');
        }
        $label = '|| '.$identifier.' ||';
        $font = $design->asset($design->document['font']);
        $size = 36;
        $maximumWidth = (int) floor($design->document['width'] * .44);
        do {
            $cell = 1;
            foreach (str_split($identifier) as $character) {
                $box = imagettfbbox($size * .75, 0, $font, $character);
                $cell = max($cell, abs($box[2] - $box[0]) + max(1, (int) floor($size / 12)));
            }
            $pipeWidth = max(5, (int) round($size / 3));
            $gap = max(3, (int) round($size / 3));
            $width = strlen($identifier) * $cell + 2 * ($pipeWidth + $gap) + 4;
            if ($width <= $maximumWidth) {
                break;
            }
            $size--;
        } while ($size >= 4);
        if ($width > $maximumWidth) {
            throw new InvalidArgumentException('The Stamp identifier cannot fit on one line.');
        }
        $height = 68;
        $image = imagecreatetruecolor($width * $scale, $height * $scale);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        $hex = $design->document['foreground'];
        $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $ink = imagecolorallocate($image, ...$rgb);
        $muted = imagecolorallocatealpha($image, ...[...$rgb, 45]);
        foreach (str_split($identifier) as $index => $character) {
            $box = imagettfbbox($size * .75, 0, $font, $character);
            $offset = (int) floor(($cell - abs($box[2] - $box[0])) / 2) - $box[0];
            imagettftext($image, $size * .75 * $scale, 0, (2 + $pipeWidth + $gap + $index * $cell + $offset) * $scale, 35 * $scale, $ink, $font, $character);
        }
        foreach ([2, 2 + $pipeWidth + 2 * $gap + strlen($identifier) * $cell] as $start) {
            foreach ([0, $pipeWidth - 2] as $offset) {
                imagefilledrectangle($image, ($start + $offset) * $scale, max(0, 35 - (int) ceil($size * .85)) * $scale, ($start + $offset + 2) * $scale - 1, (35 + (int) ceil($size * .12)) * $scale, $muted);
            }
        }
        if ($sample) {
            $caption = imagettfbbox(10, 0, $font, 'Sample');
            imagettftext($image, 10 * $scale, 0, max(0, $width - abs($caption[2] - $caption[0]) - 4) * $scale, 58 * $scale, $muted, $font, 'Sample');
        }
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);
        if (! is_string($contents)) {
            throw new RuntimeException('Cannot encode Stamp identifier.');
        }
        $bounds = ['x' => $design->document['width'] - $width - 54, 'y' => 62, 'width' => $width, 'height' => $height];
        $layer = ['type' => 'image', 'slot' => 'stamp_identifier', ...$bounds, 'color' => $hex];

        return ['design' => $design->withLayers([...$design->document['layers'], $layer]), 'images' => ['stamp_identifier' => $contents], 'label' => $label, 'sample' => $sample, 'bounds' => $bounds];
    }
}
