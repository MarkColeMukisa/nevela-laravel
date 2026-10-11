<?php

namespace Nevela\Laravel\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Rich text, made safe to keep and to show.
 *
 * What a rich text field receives is whatever was sent: the dashboard's editor, a script,
 * a CSV file, a visitor's form. None of it is trusted. The HTML is parsed, and written
 * out again from what was understood, keeping only the tags and attributes on a list
 * (config: nevela.rich_text). A tag that isn't on it goes and its words stay; a tag whose
 * contents are not words, such as a script, goes with them.
 *
 * The same is done when a record is read, so that HTML which reached the table some other
 * way (a column adopted from an older app, a row written by hand) is never shown as it is.
 */
final class Html
{
    /** What the dashboard's editor can make. */
    public const TAGS = ['p', 'br', 'strong', 'em', 's', 'a', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'];

    /** Attributes kept, by tag. A link keeps where it goes and nothing else. */
    public const ATTRIBUTES = ['a' => ['href']];

    /** Where a link may go. One with no scheme, such as /about, is the site's own and is kept. */
    public const SCHEMES = ['http', 'https', 'mailto'];

    /** Other spellings of the same thing, as browsers and word processors write them. */
    private const SAME_AS = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's'];

    /** Removed with everything inside them: what they hold was never text. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title', 'textarea', 'select', 'option', 'button', 'audio', 'video', 'canvas', 'applet', 'frame', 'frameset', 'link', 'meta', 'base'];

    /** Tags that sit inside a line of text. Anything else stands on lines of its own. */
    private const INLINE = ['a', 'strong', 'em', 's', 'b', 'i', 'u', 'strike', 'del', 'ins', 'span', 'code', 'sub', 'sup', 'small', 'mark', 'font', 'br', 'abbr', 'cite', 'q', 'label', 'time', 'img', 'kbd', 'var', 'samp', 'big', 'tt', 'wbr'];

    private const EMPTY = ['br', 'hr', 'img'];

    /**
     * The HTML with only what the list allows, or an empty string when there is nothing
     * in it to read: an editor that was opened and left sends `<p><br></p>`.
     *
     * @param  array{tags?: list<string>, attributes?: array<string, list<string>>, schemes?: list<string>}|null  $allow  Null for config('nevela.rich_text')
     */
    public static function clean(?string $html, ?array $allow = null): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }
        $allow ??= function_exists('config') && function_exists('app') && app()->bound('config') ? (array) config('nevela.rich_text', []) : [];
        $tags = array_map('strtolower', (array) ($allow['tags'] ?? self::TAGS));
        $attributes = (array) ($allow['attributes'] ?? self::ATTRIBUTES);
        $schemes = array_map('strtolower', (array) ($allow['schemes'] ?? self::SCHEMES));

        $document = new DOMDocument;
        $before = libxml_use_internal_errors(true);
        // Characters outside ASCII go in as numbered entities: the parser would otherwise
        // have to guess the encoding, and guesses Latin-1.
        $document->loadHTML('<html><body>'.mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8').'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($before);

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $out = self::top($body, $tags, $attributes, $schemes);
        $words = trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{A0}");

        return $words === '' && ! str_contains($out, '<img') ? '' : $out;
    }

    /**
     * The top of the document, where everything is a paragraph, a heading, a list or a
     * quote. Text and inline tags found loose there are gathered into paragraphs, so that
     * plain text from a CSV file or a script is stored as what the editor would have made.
     * A wrapper that isn't kept, such as a div, is looked into the same way.
     *
     * @param  list<string>  $tags
     * @param  array<string, list<string>>  $attributes
     * @param  list<string>  $schemes
     */
    private static function top(DOMNode $parent, array $tags, array $attributes, array $schemes): string
    {
        $paragraph = fn (string $inline) => trim($inline) === '' ? '' : (in_array('p', $tags, true) ? '<p>'.trim($inline).'</p>' : $inline);
        $out = '';
        $loose = '';
        foreach ($parent->childNodes as $node) {
            $name = $node instanceof DOMElement ? strtolower($node->nodeName) : '';
            $name = self::SAME_AS[$name] ?? $name;
            if ($name === '' || in_array($name, self::INLINE, true)) {
                $loose .= self::write($node, $tags, $attributes, $schemes);

                continue;
            }
            $out .= $paragraph($loose);
            $loose = '';
            $out .= in_array($name, $tags, true) || in_array($name, self::DROPPED, true)
                ? self::write($node, $tags, $attributes, $schemes)
                : self::top($node, $tags, $attributes, $schemes);
        }

        return $out.$paragraph($loose);
    }

    /** The words alone, on one line: what a table cell or a search result shows. */
    public static function text(?string $html, int $limit = 0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/blockquote)\b[^>]*>/i', ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $limit > 0 && mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }


    /**
     * @param  list<string>  $tags
     * @param  array<string, list<string>>  $attributes
     * @param  list<string>  $schemes
     */
    private static function write(DOMNode $node, array $tags, array $attributes, array $schemes): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return ''; // a comment, a processing instruction
        }
        $name = strtolower($node->nodeName);
        $name = self::SAME_AS[$name] ?? $name;
        if (in_array($name, self::DROPPED, true)) {
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::write($child, $tags, $attributes, $schemes);
        }
        if (! in_array($name, $tags, true)) {
            // Not a tag to keep, but its words are. Where it stood on its own line, a space keeps them apart.
            return in_array($name, self::INLINE, true) && $name !== 'br' ? $inner : $inner.' ';
        }

        $kept = '';
        foreach ((array) ($attributes[$name] ?? []) as $attribute) {
            $value = $node->getAttribute($attribute);
            if ($value === '' || (in_array($attribute, ['href', 'src'], true) && ! self::safe($value, $schemes))) {
                continue;
            }
            $kept .= ' '.$attribute.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'"';
        }
        // A link that goes nowhere is its words.
        if ($name === 'a' && $kept === '') {
            return $inner;
        }
        // A tag with nothing in it is nothing. Browsers leave these behind: Chrome puts a
        // list inside the paragraph it was started in, which leaves an empty paragraph
        // before it once the two are told apart. (A blank line is <p><br></p>, and is kept.)
        if (! in_array($name, self::EMPTY, true) && trim($inner) === '') {
            return '';
        }

        return in_array($name, self::EMPTY, true) ? "<{$name}{$kept}>" : "<{$name}{$kept}>{$inner}</{$name}>";
    }

    /**
     * Whether an address is one a link may have. A browser ignores spaces and control
     * characters inside a scheme, so `java\tscript:` is read here the way it would be there.
     *
     * @param  list<string>  $schemes
     */
    private static function safe(string $address, array $schemes): bool
    {
        $address = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $address);
        if (! preg_match('/^([a-z][a-z0-9+.\-]*):/i', $address, $m)) {
            return true; // no scheme: /about, #top, //example.com
        }

        return in_array(strtolower($m[1]), $schemes, true);
    }
}
