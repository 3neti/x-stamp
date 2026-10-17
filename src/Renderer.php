<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

final class Renderer
{
    /** @param array<string, string> $slots @return list<array<string, mixed>> */
    public function layout(Design $design, array $slots = []): array
    {
        $layers = [];
        foreach ($design->document['layers'] as $layer) {
            if ($layer['type'] !== 'text') {
                $layers[] = $layer;

                continue;
            }
            $text = isset($layer['slot']) ? ($slots[$layer['slot']] ?? '') : ($layer['text'] ?? '');
            if (! is_string($text) || mb_strlen($text) > 4096 || preg_match('//u', $text) !== 1) {
                throw new InvalidArgumentException('Stamp slot values must be bounded UTF-8 strings.');
            }
            $size = $layer['size'] ?? 24;
            $lineHeight = $size * 1.4;
            $limit = min($layer['max_lines'] ?? 2, max(1, (int) floor($layer['height'] / $lineHeight)));
            $lines = [];
            $line = '';
            foreach (preg_split('/\R/u', trim($text)) as $paragraph) {
                $line = '';
                foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $next = $line === '' ? $word : $line.' '.$word;
                    if ($this->textWidth($design, $next, $size) > $layer['width'] && $line !== '') {
                        $lines[] = $line;
                        $line = $word;
                    } else {
                        $line = $next;
                    }
                    if ($this->textWidth($design, $line, $size) > $layer['width']) {
                        while ($line !== '' && $this->textWidth($design, $line.'…', $size) > $layer['width']) {
                            $line = mb_substr($line, 0, -1);
                        }
                        $line .= '…';
                    }
                }
                if ($line !== '' || $paragraph !== '') {
                    $lines[] = $line;
                }
            }
            if (count($lines) > $limit) {
                $lines = array_slice($lines, 0, $limit);
                $last = array_pop($lines);
                while ($last !== '' && $this->textWidth($design, $last.'…', $size) > $layer['width']) {
                    $space = mb_strrpos($last, ' ');
                    $last = $space === false ? mb_substr($last, 0, -1) : mb_substr($last, 0, $space);
                }
                $lines[] = $last.'…';
            }
            foreach ($lines as $index => $value) {
                $layers[] = [...$layer, 'text' => $value, 'size' => $size, 'baseline' => $layer['y'] + $size + $index * $lineHeight];
            }
        }

        return $layers;
    }

    /** @param array<string, string> $slots */
    public function svg(Design $design, array $slots = []): string
    {
        $doc = $design->document;
        $font = base64_encode(file_get_contents($design->asset($doc['font'])));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$doc['width'].'" height="'.$doc['height'].'" viewBox="0 0 '.$doc['width'].' '.$doc['height'].'" role="img"><title>'.$this->escape($doc['name']).'</title><defs><style>@font-face{font-family:Stamp;src:url(data:font/ttf;base64,'.$font.')}text{font-family:Stamp}</style></defs><rect width="100%" height="100%" fill="'.$doc['background'].'"/>';
        foreach ($this->layout($design, $slots) as $layer) {
            $x = $layer['x'];
            $y = $layer['y'];
            $w = $layer['width'];
            $h = $layer['height'];
            $color = $layer['color'];
            $svg .= match ($layer['type']) {
                'rectangle' => '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="'.($layer['radius'] ?? 0).'" fill="'.$color.'"/>',
                'circle' => '<ellipse cx="'.($x + $w / 2).'" cy="'.($y + $h / 2).'" rx="'.($w / 2).'" ry="'.($h / 2).'" fill="'.$color.'"/>',
                'line' => '<line x1="'.$x.'" y1="'.$y.'" x2="'.($x + $w).'" y2="'.($y + $h).'" stroke="'.$color.'"/>',
                'text' => '<text x="'.$x.'" y="'.$layer['baseline'].'" font-size="'.$layer['size'].'" fill="'.$color.'">'.$this->escape($layer['text']).'</text>',
                'image' => '<image x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" preserveAspectRatio="none" href="'.$this->imageData($design, $layer['asset']).'"/>',
            };
        }

        return $svg.'</svg>';
    }

    /** @param array<string, string> $slots */
    public function html(Design $design, array $slots = []): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; img-src data:; font-src data:; style-src &apos;unsafe-inline&apos;; base-uri &apos;none&apos;; form-action &apos;none&apos;"><style>html,body{margin:0;background:'.$design->document['background'].';color-scheme:only light}svg{display:block;width:100%;height:auto}</style></head><body>'.$this->svg($design, $slots).'</body></html>';
    }

    /** @param array<string, string> $slots */
    public function png(Design $design, array $slots = []): string
    {
        $doc = $design->document;
        $image = imagecreatetruecolor($doc['width'], $doc['height']);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Cannot allocate Stamp canvas.');
        }
        imagefill($image, 0, 0, $this->color($image, $doc['background']));
        foreach ($this->layout($design, $slots) as $layer) {
            $x = (int) $layer['x'];
            $y = (int) $layer['y'];
            $w = (int) $layer['width'];
            $h = (int) $layer['height'];
            $color = $this->color($image, $layer['color']);
            switch ($layer['type']) {
                case 'rectangle':
                    $radius = (int) min($layer['radius'] ?? 0, $w / 2, $h / 2);
                    imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
                    imagefilledrectangle($image, $x, $y + $radius, $x + $w, $y + $h - $radius, $color);
                    if ($radius > 0) {
                        foreach ([[$x + $radius, $y + $radius], [$x + $w - $radius, $y + $radius], [$x + $radius, $y + $h - $radius], [$x + $w - $radius, $y + $h - $radius]] as [$cx,$cy]) {
                            imagefilledellipse($image, $cx, $cy, 2 * $radius, 2 * $radius, $color);
                        }
                    }
                    break;
                case 'circle': imagefilledellipse($image, (int) ($x + $w / 2), (int) ($y + $h / 2), $w, $h, $color);
                    break;
                case 'line': imageline($image, $x, $y, $x + $w, $y + $h, $color);
                    break;
                case 'text': imagettftext($image, $layer['size'] * 0.75, 0, $x, (int) $layer['baseline'], $color, $design->asset($doc['font']), $layer['text']);
                    break;
                case 'image':
                    $artwork = imagecreatefromstring(file_get_contents($design->asset($layer['asset'])));
                    if (! $artwork instanceof GdImage) {
                        throw new RuntimeException('Cannot decode Stamp artwork.');
                    }
                    imagecopyresampled($image, $artwork, $x, $y, 0, 0, $w, $h, imagesx($artwork), imagesy($artwork));
                    imagedestroy($artwork);
                    break;
            }
        }
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents ?: throw new RuntimeException('Cannot encode Stamp image.');
    }

    private function textWidth(Design $design, string $text, float $size): float
    {
        $box = imagettfbbox($size * 0.75, 0, $design->asset($design->document['font']), $text);

        return abs($box[2] - $box[0]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function imageData(Design $design, string $name): string
    {
        $path = $design->asset($name);

        return 'data:'.image_type_to_mime_type(getimagesize($path)[2]).';base64,'.base64_encode(file_get_contents($path));
    }

    private function color(GdImage $image, string $hex): int
    {
        return imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }
}
