<?php

declare(strict_types=1);

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitizer for user-authored rich text (property
 * descriptions, house rules, etc.).
 *
 * The property description surface is the only WYSIWYG in the app today,
 * so we deliberately keep the tag set tiny — bold / italic / underline
 * (+ their canonical strong/em variants), links, paragraphs, line
 * breaks, and unordered / ordered lists. Everything else is stripped.
 *
 * Attribute policy is even tighter: only `href` on `<a>` survives, and
 * we force `rel="noopener nofollow ugc"` + `target="_blank"` on every
 * anchor so a hostile paste (`javascript:`, `data:` URLs, on-event
 * handlers) can't fire in the guest's browser when the marketing
 * landing renders the description unescaped.
 *
 * Output is always UTF-8, never wrapped in <html><body>, and safe for
 * `{!! ... !!}` on Blade.
 */
class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br',
        'strong', 'b',
        'em', 'i',
        'u',
        'a',
        'ul', 'ol', 'li',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
    ];

    /** @var list<string> */
    private const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // DOMDocument requires a wrapper it can parse; the meta hint
        // forces UTF-8 so multibyte characters (naira sign, emoji)
        // survive the round trip.
        $wrapped = '<?xml encoding="utf-8"?><body>'.$html.'</body>';
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $this->walk($body);

        // Serialise the body's children and reassemble — we never want
        // the <body> wrapper itself in the output.
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private function walk(DOMNode $node): void
    {
        // Iterate a snapshot: mutations during iteration (replaceChild /
        // removeChild) invalidate the live childNodes NodeList. Walk
        // children FIRST so that nested disallowed elements (e.g.
        // `<div><span>text</span></div>`) are unwrapped bottom-up and
        // reduce to plain text by the time we reach the outer element.
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                $this->walk($child);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->unwrap($child);

                    continue;
                }
                $this->scrubAttributes($child, $tag);
            } elseif ($child->nodeType === XML_COMMENT_NODE
                || $child->nodeType === XML_PI_NODE
            ) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /**
     * Replace a disallowed element with its children, so pasted
     * `<div><span>text</span></div>` becomes plain `text` rather than
     * being wholesale dropped.
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private function scrubAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        // Copy names first — removeAttribute during iteration mutates
        // the attribute NamedNodeMap.
        $names = [];
        foreach ($element->attributes as $attr) {
            $names[] = $attr->nodeName;
        }
        foreach ($names as $name) {
            $lname = strtolower($name);
            if (! in_array($lname, $allowed, true)) {
                $element->removeAttribute($name);

                continue;
            }
            if ($tag === 'a' && $lname === 'href') {
                $href = trim((string) $element->getAttribute('href'));
                if (! $this->safeUrl($href)) {
                    $element->removeAttribute('href');
                }
            }
        }

        // Force safe defaults on every anchor. Overwrites whatever the
        // paste may have carried.
        if ($tag === 'a') {
            $element->setAttribute('rel', 'noopener nofollow ugc');
            $element->setAttribute('target', '_blank');
        }
    }

    private function safeUrl(string $url): bool
    {
        if ($url === '' || str_contains($url, "\0")) {
            return false;
        }
        // Relative paths and fragments are fine — anything without a
        // scheme can't inject javascript:.
        if (! str_contains($url, ':')) {
            return true;
        }
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');

        return in_array($scheme, self::SAFE_URL_SCHEMES, true);
    }
}
