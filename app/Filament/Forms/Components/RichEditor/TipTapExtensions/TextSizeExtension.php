<?php

namespace App\Filament\Forms\Components\RichEditor\TipTapExtensions;

use App\Filament\Forms\Components\RichEditor\TextSizeOptions;
use Tiptap\Core\Mark;

class TextSizeExtension extends Mark
{
    /**
     * @var string
     */
    public static $name = 'textSize';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'span',
                'getAttrs' => function ($DOMNode): bool {
                    $dataSize = TextSizeOptions::normalize($DOMNode->getAttribute('data-size'));

                    if ($dataSize) {
                        return true;
                    }

                    $classAttr = (string) $DOMNode->getAttribute('class');

                    foreach (TextSizeOptions::classes() as $size) {
                        if (str_contains(" {$classAttr} ", " {$size} ")) {
                            return true;
                        }
                    }

                    return false;
                },
            ],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function addAttributes(): array
    {
        return [
            'data-size' => [
                'parseHTML' => fn ($DOMNode) => $this->getSizeFromDomNode($DOMNode),
                'renderHTML' => function ($attributes) {
                    $value = null;

                    if (is_array($attributes)) {
                        $value = $attributes['data-size'] ?? null;
                    } elseif (is_object($attributes)) {
                        $value = $attributes->{'data-size'} ?? ($attributes->dataSize ?? null);
                    }

                    $value = TextSizeOptions::normalize($value);

                    return $value ? ['data-size' => $value] : [];
                },
            ],
        ];
    }

    protected function getSizeFromDomNode($DOMNode): ?string
    {
        $dataSize = TextSizeOptions::normalize($DOMNode->getAttribute('data-size'));

        if ($dataSize) {
            return $dataSize;
        }

        $classAttr = (string) $DOMNode->getAttribute('class');

        foreach (TextSizeOptions::classes() as $size) {
            if (str_contains(" {$classAttr} ", " {$size} ")) {
                return $size;
            }
        }

        return null;
    }

    /**
     * @param  object  $mark
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array<mixed>
     */
    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        $existingClass = isset($HTMLAttributes['class']) ? (string) $HTMLAttributes['class'] : '';
        $size = TextSizeOptions::normalize($HTMLAttributes['data-size'] ?? null);

        if ($size) {
            $HTMLAttributes['class'] = trim(implode(' ', array_filter([$existingClass, $size])));
            $HTMLAttributes['data-size'] = $size;
        } else {
            if ($existingClass !== '') {
                $HTMLAttributes['class'] = $existingClass;
            }

            unset($HTMLAttributes['data-size']);
        }

        return [
            'span',
            $HTMLAttributes,
            0,
        ];
    }
}
