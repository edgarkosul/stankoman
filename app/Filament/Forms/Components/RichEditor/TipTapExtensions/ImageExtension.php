<?php

namespace App\Filament\Forms\Components\RichEditor\TipTapExtensions;

use DOMElement;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension as BaseImageExtension;

class ImageExtension extends BaseImageExtension
{
    protected function getStyleValue(DOMElement $DOMNode, string $property): ?string
    {
        $style = $DOMNode->getAttribute('style');

        if (blank($style)) {
            return null;
        }

        $pattern = '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/i';

        preg_match($pattern, $style, $matches);

        if (! isset($matches[1])) {
            return null;
        }

        return trim($matches[1]);
    }
}
