<?php

declare(strict_types=1);

namespace LBHurtado\XStamp;

use InvalidArgumentException;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/** Optional Chromium renderer. Runtime executable paths belong to the deployment, never a design. */
final readonly class BrowserRenderer
{
    public function __construct(
        private ?string $nodeBinary = null,
        private ?string $nodeModulePath = null,
        private ?string $chromePath = null,
        private int $timeout = 20,
    ) {
        if ($timeout < 1 || $timeout > 60) {
            throw new InvalidArgumentException('Stamp browser timeout must be between 1 and 60 seconds.');
        }
    }

    /** @param array<string, string> $slots @param array<string, string> $images */
    public function png(Design $design, array $slots = [], array $images = [], RenderingProfile $profile = RenderingProfile::Browser): string
    {
        return $this->screenshot((new Renderer)->html($design, $slots, $images), $design->document['width'], $design->document['height'], $profile);
    }

    /** Render only a trusted, self-contained document prepared by the integrating application. */
    public function screenshot(string $html, int $width, int $height, RenderingProfile $profile = RenderingProfile::Browser): string
    {
        if ($profile === RenderingProfile::Standard || $width < 1 || $height < 1 || $width > 2400 || $height > 1600 || strlen($html) > 20000000) {
            throw new InvalidArgumentException('Invalid browser Stamp document or profile.');
        }
        if (! class_exists(Browsershot::class)) {
            throw new RuntimeException('Browser Stamp profiles require spatie/browsershot ^5.4 and Puppeteer.');
        }
        $shot = Browsershot::html($html)
            ->windowSize($width, $height)
            ->deviceScaleFactor($profile->scale())
            ->timeout($this->timeout)
            ->disableJavascript()
            ->disableRedirects()
            ->blockUrls(['http:', 'https:', 'ftp:', 'ws:', 'wss:'])
            ->waitForFunction('document.fonts.status === "loaded" && Array.from(document.images).every(image => image.complete && image.naturalWidth > 0)', timeout: $this->timeout * 1000);
        if ($this->nodeBinary !== null) {
            $shot->setNodeBinary($this->nodeBinary);
        }
        if ($this->nodeModulePath !== null) {
            $shot->setNodeModulePath($this->nodeModulePath);
        }
        if ($this->chromePath !== null) {
            $shot->setChromePath($this->chromePath);
        }
        $contents = $shot->screenshot();
        $size = @getimagesizefromstring($contents);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] !== $width * $profile->scale() || $size[1] !== $height * $profile->scale()) {
            throw new RuntimeException('Browser Stamp output does not match the selected profile.');
        }

        return $contents;
    }
}
