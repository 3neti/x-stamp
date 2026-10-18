<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use GdImage;
use InvalidArgumentException;

final class QrPresentation
{
    /** @param array<string, float|int> $settings @return string PNG bytes */
    public function png(string $bytes, string $paper, string $trim, array $settings): string
    {
        (new Appearance)->validateQr($settings);
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || strlen($bytes) > 2000000 || ! preg_match('/^#[0-9a-fA-F]{6}$/', $paper) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $trim)) {
            throw new InvalidArgumentException('Invalid QR artwork or palette.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] * $info[1] > 1000000) {
            throw new InvalidArgumentException('QR artwork must be a bounded PNG.');
        }
        $qr = @imagecreatefromstring($bytes);
        if (! $qr instanceof GdImage) {
            throw new InvalidArgumentException('QR artwork cannot be decoded.');
        }
        imagepalettetotruecolor($qr);
        imagealphablending($qr, false);
        imagesavealpha($qr, true);
        $tone = $this->rgb($paper);
        $width = imagesx($qr);
        $height = imagesy($qr);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorsforindex($qr, imagecolorat($qr, $x, $y));
                $rgb = [$pixel['red'], $pixel['green'], $pixel['blue']];
                $ink = min(1, max(0, 299 - 100 * array_sum($rgb) / 255)) * $settings['ink'] * (1 - $pixel['alpha'] / 127);
                $center = abs($x + .5 - $width / 2) < $width * 22 / 256
                    && abs($y + .5 - $height / 2) < $height * 21.5 / 256;
                $backing = $center ? $settings['logo_backing'] : $settings['paper'];
                $alpha = $ink + $backing * (1 - $ink);
                foreach ($rgb as $channel => &$value) {
                    $value = (int) round(($value * $ink + $tone[$channel] * $backing * (1 - $ink)) / $alpha);
                }
                unset($value);
                imagesetpixel($qr, $x, $y, imagecolorallocatealpha($qr, $rgb[0], $rgb[1], $rgb[2], (int) round((1 - $alpha) * 127)));
            }
        }
        $marker = imagecreatetruecolor(190, 190);
        imagealphablending($marker, false);
        imagesavealpha($marker, true);
        imagefill($marker, 0, 0, imagecolorallocatealpha($marker, ...[...$this->rgb($trim), (int) round(127 * (1 - $settings['frame']))]));
        imagefilledrectangle($marker, 2, 2, 187, 187, imagecolorallocatealpha($marker, ...[...$tone, (int) round(127 * (1 - $settings['frame']))]));
        imagealphablending($marker, true);
        imagecopyresampled($marker, $qr, 10, 10, 0, 0, 170, 170, $width, $height);
        ob_start();
        imagepng($marker);
        $contents = ob_get_clean();
        imagedestroy($marker);
        imagedestroy($qr);

        return $contents;
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** @param array<string, float|int> $settings @return array{design: Design, images: array<string, string>, bounds: array{x: int, y: int, width: int, height: int}|null} */
    public function compose(Design $design, ?string $qrBytes, string $code, string $kind, string $position, string $paper, string $trim, array $settings): array
    {
        if (! in_array($kind, ['none', 'code', 'qr', 'both'], true) || ! in_array($position, ['top_left', 'top_right', 'bottom_left', 'bottom_right'], true)) {
            throw new InvalidArgumentException('Invalid Stamp marker placement.');
        }
        if ($kind === 'none') {
            return ['design' => $design, 'images' => [], 'bounds' => null];
        }
        $hasQr = in_array($kind, ['qr', 'both'], true);
        $width = $hasQr ? 190 : 250;
        $height = $hasQr ? ($kind === 'both' ? 226 : 190) : 62;
        $x = str_ends_with($position, '_left') ? 54 : $design->document['width'] - $width - 54;
        $y = str_starts_with($position, 'top_') ? 54 : $design->document['height'] - $height - 54;
        $layers = $design->document['layers'];
        $images = [];
        if ($hasQr && $qrBytes !== null) {
            $images['stamp_marker_qr'] = $this->png($qrBytes, $paper, $trim, $settings);
            $layers[] = ['type' => 'image', 'slot' => 'stamp_marker_qr', 'x' => $x, 'y' => $y, 'width' => 190, 'height' => 190, 'color' => $paper];
        }
        if (in_array($kind, ['code', 'both'], true)) {
            $layers[] = ['type' => 'text', 'text' => $code, 'x' => $x, 'y' => $y + ($hasQr ? 194 : 12), 'width' => $width, 'height' => 30, 'size' => 20, 'max_lines' => 1, 'color' => $design->document['foreground']];
        }

        return ['design' => $design->withLayers($layers), 'images' => $images, 'bounds' => compact('x', 'y', 'width', 'height')];
    }
}
