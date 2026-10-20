<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;

enum RenderingProfile: string
{
    case Standard = 'standard';
    case Browser = 'browser';
    case BrowserHd = 'browser-hd';

    public static function resolve(string $name): self
    {
        return self::tryFrom($name) ?? throw new InvalidArgumentException('Unknown Stamp rendering profile.');
    }

    public function scale(): int
    {
        return $this === self::BrowserHd ? 2 : 1;
    }

    public function driver(): string
    {
        return $this === self::Standard ? 'gd' : 'browsershot';
    }
}
