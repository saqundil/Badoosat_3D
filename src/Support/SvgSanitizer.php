<?php

declare(strict_types=1);

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * An SVG is an XML document the browser executes, so an uploaded one is a
 * script upload unless it is stripped first.
 *
 * The original is kept outside the web root and never served; this produces the
 * copy that is safe to put under public/.
 */
final class SvgSanitizer
{
    private const FORBIDDEN_TAGS = [
        'script', 'foreignObject', 'iframe', 'embed', 'object', 'audio', 'video',
        'animate', 'animateTransform', 'animateMotion', 'set', 'handler', 'listener',
    ];

    private const ALLOWED_LINK_PREFIXES = ['#', 'data:image/png', 'data:image/jpeg', 'data:image/webp'];

    /** @return string sanitised SVG markup */
    public static function clean(string $svg): string
    {
        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        // LIBXML_NONET blocks network fetches. Nothing enables DTD loading or
        // entity substitution, which on PHP 8 leaves both off: no XXE, no
        // billion laughs. LIBXML_NOENT would switch substitution back ON.
        $document->loadXML($svg, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document->documentElement === null
            || strtolower($document->documentElement->localName) !== 'svg') {
            throw new UploadException('هذا الملف ليس SVG صالحًا.');
        }

        // Drop any doctype: it is the entry point for entity attacks.
        while ($document->doctype !== null) {
            $document->removeChild($document->doctype);
        }

        $xpath = new DOMXPath($document);

        foreach (self::FORBIDDEN_TAGS as $tag) {
            $nodes = $xpath->query('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'
                . strtolower($tag) . '"]');

            foreach (iterator_to_array($nodes ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
            self::filterAttribute($attribute);
        }

        // Inline CSS can pull in remote resources of its own.
        foreach (iterator_to_array($xpath->query('//*[local-name()="style"]') ?: []) as $style) {
            if (preg_match('/@import|url\s*\(\s*["\']?(?!#|data:image\/)/i', $style->textContent)) {
                $style->parentNode?->removeChild($style);
            }
        }

        $output = $document->saveXML();

        if ($output === false) {
            throw new UploadException('تعذّر تعقيم ملف SVG.');
        }

        return $output;
    }

    private static function filterAttribute(DOMAttr $attribute): void
    {
        $element = $attribute->ownerElement;

        if (!$element instanceof DOMElement) {
            return;
        }

        $name  = strtolower($attribute->localName);
        $value = $attribute->value;

        // Every event handler, whatever it is called.
        if (str_starts_with($name, 'on')) {
            $element->removeAttributeNode($attribute);

            return;
        }

        if (in_array($name, ['href', 'src', 'from', 'to', 'values', 'begin'], true)) {
            $trimmed = ltrim($value);

            foreach (self::ALLOWED_LINK_PREFIXES as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    return;
                }
            }

            $element->removeAttributeNode($attribute);

            return;
        }

        if ($name === 'style' && preg_match('/@import|expression\s*\(|url\s*\(\s*["\']?(?!#|data:image\/)/i', $value)) {
            $element->removeAttributeNode($attribute);
        }
    }
}
