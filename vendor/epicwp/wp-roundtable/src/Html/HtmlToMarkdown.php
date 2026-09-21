<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Html;

/**
 * Converts the WYSIWYG editor's limited HTML to markdown, server-side, before anything
 * reaches the hub (the hub pipeline stays markdown-only).
 *
 * Allowed tags: `p`, `br`, `strong`/`b`, `em`/`i`, `ul`/`ol`/`li` (nested lists indented),
 * `a[href]` (only `http`/`https`/`mailto` schemes; any other scheme, or no `href`, keeps
 * the link text but drops the link), `code`, `pre`, `h3`, `h4`, `blockquote`. Every other
 * tag is unwrapped — its own markup is dropped but its text content is kept, recursively —
 * except `script`/`style`, whose content is not user-facing text and is dropped entirely.
 *
 * Pure PHP ({@see \DOMDocument}, ships with PHP; no Composer dependency). Deterministic and
 * never fatals: malformed input falls back to its plain-text content.
 *
 * **Escaping contract**: every literal text run — whether it arrives inside an allowed or
 * disallowed HTML tag, as the malformed-input fallback, or as bare input with no markup at
 * all — is escaped so markdown-significant characters render as the literal characters the
 * user typed, not as reinterpreted syntax. Turndown-style: a leading `#{1,6} ` (heading),
 * `-`/`+`/`>` (list/quote marker), or `digit.`/`digit)` (ordered-list marker) is escaped only
 * at the very start of a text node; a backtick, `*`, `_`, `[`, `]`, or `\` is escaped wherever
 * it appears. The one exception is text inside `code`/`pre` — already protected by their own
 * backticks/fence — which is never escaped. This makes `convert()`'s result consistent no
 * matter which of its internal paths produced it (DOM render, malformed-input fallback, or
 * the no-markup-to-parse fast path).
 */
final class HtmlToMarkdown {
    /** Link URL schemes kept as a markdown link; any other scheme keeps the text only. */
    private const ALLOWED_SCHEMES = array( 'http', 'https', 'mailto' );
    /** Tags whose content is code, not visible text — dropped entirely (tag + content). */
    private const OPAQUE_TAGS = array( 'script', 'style' );
    /** Block-level tags: whitespace-only text touching one of these is formatting, not content. */
    private const BLOCK_TAGS = array( 'p', 'ul', 'ol', 'li', 'h3', 'h4', 'pre', 'blockquote' );

    /**
     * Convert HTML to markdown.
     *
     * @param string $html The editor's HTML output.
     *
     * @return string The converted markdown, entity-decoded, whitespace/blank-lines
     *                 normalized, trimmed.
     */
    public static function convert( string $html ): string {
        $trimmed = \trim( $html );
        if ( '' === $trimmed ) {
            return '';
        }
        if ( false === \strpos( $trimmed, '<' ) && false === \strpos( $trimmed, '&' ) ) {
            // No tags and no entities: nothing to parse, but the text still needs the
            // same markdown-escaping the DOM path applies (see the class docblock).
            return self::escapeMarkdown( $trimmed );
        }

        try {
            return self::convertHtml( $trimmed );
        } catch ( \Throwable ) {
            return self::plainTextFallback( $trimmed );
        }
    }

    /**
     * Parses and renders the HTML via {@see \DOMDocument}.
     *
     * @param string $html The trimmed HTML, known to contain at least one `<`.
     *
     * @return string The rendered markdown.
     */
    private static function convertHtml( string $html ): string {
        $doc      = new \DOMDocument();
        $previous = \libxml_use_internal_errors( true );
        // The `<?xml encoding="UTF-8">` prefix forces UTF-8 decoding without injecting a
        // visible `<meta>` tag into the parsed body; libxml consumes it as a declaration.
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NOBLANKS,
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors( $previous );

        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( null === $body ) {
            return self::plainTextFallback( $html );
        }

        $markdown = self::renderChildren( $body );
        $markdown = (string) \preg_replace( "/\n{3,}/", "\n\n", $markdown );

        return \trim( $markdown );
    }

    /**
     * The safe, deterministic fallback: strip all tags, decode entities, and escape.
     *
     * @param string $html The raw (possibly malformed) HTML.
     *
     * @return string The plain-text content, whitespace-normalized, trimmed, and
     *                 markdown-escaped (see the class docblock's escaping contract).
     */
    private static function plainTextFallback( string $html ): string {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure-PHP core function, no WordPress dependency by design (this class is testable without WordPress booted).
        $text = \html_entity_decode( \strip_tags( $html ), \ENT_QUOTES, 'UTF-8' );
        $text = \trim( (string) \preg_replace( '/\s+/', ' ', $text ) );
        return self::escapeMarkdown( $text );
    }

    /**
     * Renders every child of a node and concatenates the results.
     *
     * @param \DOMNode $node The parent node.
     *
     * @return string The concatenated markdown.
     */
    private static function renderChildren( \DOMNode $node ): string {
        $out = '';
        foreach ( $node->childNodes as $child ) {
            $out .= self::renderNode( $child );
        }
        return $out;
    }

    /**
     * Renders a single DOM node to markdown.
     *
     * @param \DOMNode $node The node to render.
     *
     * @return string The node's markdown (block elements include their own trailing
     *                 blank-line separator; inline elements do not).
     */
    private static function renderNode( \DOMNode $node ): string {
        if ( $node instanceof \DOMText ) {
            return self::renderText( $node );
        }
        if ( ! $node instanceof \DOMElement ) {
            // Comments, doctypes, processing instructions: not text, contribute nothing.
            return '';
        }

        $tag = \strtolower( $node->tagName );
        if ( \in_array( $tag, self::OPAQUE_TAGS, true ) ) {
            return '';
        }

        return match ( $tag ) {
            'p' => self::renderBlock( $node ),
            'br' => "  \n",
            'strong', 'b' => self::renderInlineWrap( $node, '**' ),
            'em', 'i' => self::renderInlineWrap( $node, '*' ),
            'code' => self::renderInlineWrap( $node, '`' ),
            'pre' => self::renderPre( $node ),
            'h3' => self::renderHeading( $node, '###' ),
            'h4' => self::renderHeading( $node, '####' ),
            'blockquote' => self::renderBlockquote( $node ),
            'ul' => self::renderList( $node, 0, false ) . "\n",
            'ol' => self::renderList( $node, 0, true ) . "\n",
            'a' => self::renderLink( $node ),
            // Any other tag (div, span, table, img, iframe, …): unwrap, keep its text.
            default => self::renderChildren( $node ),
        };
    }

    /**
     * Renders a block-level element's children as one trimmed paragraph.
     *
     * @param \DOMElement $node The block element (e.g. `p`).
     *
     * @return string The paragraph plus its trailing blank line, or '' if empty.
     */
    private static function renderBlock( \DOMElement $node ): string {
        $inner = \trim( self::renderChildren( $node ) );
        return '' === $inner ? '' : $inner . "\n\n";
    }

    /**
     * Wraps an inline element's children with a marker on both sides (bold/italic/code).
     *
     * @param \DOMElement $node   The inline element.
     * @param string      $marker The marker to wrap with (`**`, `*`, or `` ` ``).
     *
     * @return string The wrapped inline markdown, or '' if the element has no text.
     */
    private static function renderInlineWrap( \DOMElement $node, string $marker ): string {
        $inner = \trim( self::renderChildren( $node ) );
        return '' === $inner ? '' : $marker . $inner . $marker;
    }

    /**
     * Renders a heading as an ATX-style markdown heading.
     *
     * @param \DOMElement $node   The heading element (`h3`/`h4`).
     * @param string      $prefix The markdown heading prefix (`###`/`####`).
     *
     * @return string The heading plus its trailing blank line, or '' if empty.
     */
    private static function renderHeading( \DOMElement $node, string $prefix ): string {
        $inner = \trim( self::renderChildren( $node ) );
        return '' === $inner ? '' : $prefix . ' ' . $inner . "\n\n";
    }

    /**
     * Renders a `pre` block as a fenced code block. Its content is taken as literal text
     * (not re-processed as markdown), matching how a code block is expected to behave.
     *
     * @param \DOMElement $node The `pre` element.
     *
     * @return string The fenced block plus its trailing blank line, or '' if empty.
     */
    private static function renderPre( \DOMElement $node ): string {
        $inner = \trim( $node->textContent );
        return '' === $inner ? '' : "```\n" . $inner . "\n```\n\n";
    }

    /**
     * Renders a `blockquote` by prefixing every rendered line with `> `.
     *
     * @param \DOMElement $node The `blockquote` element.
     *
     * @return string The quoted block plus its trailing blank line, or '' if empty.
     */
    private static function renderBlockquote( \DOMElement $node ): string {
        $inner = \trim( self::renderChildren( $node ) );
        if ( '' === $inner ) {
            return '';
        }
        $quoted = \implode(
            "\n",
            \array_map(
                static fn( string $line ): string => '' === $line ? '>' : '> ' . $line,
                \explode( "\n", $inner ),
            ),
        );
        return $quoted . "\n\n";
    }

    /**
     * Renders a list's `li` children (top-level or nested).
     *
     * @param \DOMElement $node    The `ul`/`ol` element.
     * @param int         $depth   The nesting depth (0 = top-level), used for indentation.
     * @param bool        $ordered Whether this is an `ol` (numbered) or `ul` (bulleted).
     *
     * @return string The list's rendered lines (no trailing blank line — the caller adds one
     *                 only for a top-level list; a nested list continues its parent item).
     */
    private static function renderList( \DOMElement $node, int $depth, bool $ordered ): string {
        $out   = '';
        $index = 1;
        foreach ( $node->childNodes as $child ) {
            if ( ! $child instanceof \DOMElement || 'li' !== \strtolower( $child->tagName ) ) {
                continue;
            }
            $out .= self::renderListItem( $child, $depth, $ordered, $index );
            ++$index;
        }
        return $out;
    }

    /**
     * Renders one `li`: its own inline content as a marker line, then any nested
     * `ul`/`ol` indented one level deeper.
     *
     * @param \DOMElement $li      The `li` element.
     * @param int         $depth   The current nesting depth.
     * @param bool        $ordered Whether the enclosing list is ordered.
     * @param int         $index   This item's 1-based position (used for `N.` markers).
     *
     * @return string The item's line(s), each newline-terminated.
     */
    private static function renderListItem( \DOMElement $li, int $depth, bool $ordered, int $index ): string {
        $indent = \str_repeat( '  ', $depth );
        $marker = $ordered ? $index . '. ' : '- ';
        $inline = \trim( self::renderListItemInline( $li ) );
        $nested = self::renderNestedLists( $li, $depth + 1 );

        return $indent . $marker . $inline . "\n" . $nested;
    }

    /**
     * Renders an `li`'s own content, skipping any nested `ul`/`ol` (rendered separately
     * by {@see self::renderNestedLists()}).
     *
     * @param \DOMElement $li The `li` element.
     *
     * @return string The item's inline markdown (untrimmed).
     */
    private static function renderListItemInline( \DOMElement $li ): string {
        $out = '';
        foreach ( $li->childNodes as $child ) {
            if ( self::isNestedList( $child ) ) {
                continue;
            }
            $out .= self::renderNode( $child );
        }
        return $out;
    }

    /**
     * Renders an `li`'s nested `ul`/`ol` children, one level deeper.
     *
     * @param \DOMElement $li    The `li` element.
     * @param int         $depth The nesting depth to render the nested list(s) at.
     *
     * @return string The nested list(s)' rendered lines.
     */
    private static function renderNestedLists( \DOMElement $li, int $depth ): string {
        $out = '';
        foreach ( $li->childNodes as $child ) {
            if ( ! $child instanceof \DOMElement || ! self::isNestedList( $child ) ) {
                continue;
            }
            $ordered = 'ol' === \strtolower( $child->tagName );
            $out    .= self::renderList( $child, $depth, $ordered );
        }
        return $out;
    }

    /**
     * Whether a node is a nested `ul`/`ol` list element.
     *
     * @param \DOMNode $node The node to check.
     *
     * @return bool True if the node is a `ul` or `ol` element.
     */
    private static function isNestedList( \DOMNode $node ): bool {
        if ( ! $node instanceof \DOMElement ) {
            return false;
        }
        return \in_array(
            \strtolower( $node->tagName ),
            array( 'ul', 'ol' ),
            true,
        );
    }

    /**
     * Renders an `a[href]` as a markdown link, or its bare text when the link is dropped.
     *
     * @param \DOMElement $node The `a` element.
     *
     * @return string The markdown link (or plain text), or '' if the link has no text.
     */
    private static function renderLink( \DOMElement $node ): string {
        $text = \trim( self::renderChildren( $node ) );
        if ( '' === $text ) {
            return '';
        }
        $href = $node->getAttribute( 'href' );
        return self::isAllowedHref( $href ) ? '[' . $text . '](' . $href . ')' : $text;
    }

    /**
     * Whether an `href` uses an allowed scheme (`http`, `https`, or `mailto`).
     *
     * @param string $href The raw `href` attribute value.
     *
     * @return bool True if the scheme is allowed.
     */
    private static function isAllowedHref( string $href ): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure-PHP core function, no WordPress dependency by design (this class is testable without WordPress booted).
        $scheme = \strtolower( (string) \parse_url( $href, \PHP_URL_SCHEME ) );
        return \in_array( $scheme, self::ALLOWED_SCHEMES, true );
    }

    /**
     * Renders a text node: whitespace-only text between block-level tags is pure HTML
     * source formatting and contributes nothing; whitespace-only text between inline
     * content (e.g. between two `<a>` tags) is a real word separator and becomes a single
     * space. Non-whitespace text has its internal whitespace runs collapsed, then is
     * markdown-escaped (see the class docblock) unless it sits inside `code`/`pre`.
     *
     * @param \DOMText $node The text node.
     *
     * @return string The rendered text.
     */
    private static function renderText( \DOMText $node ): string {
        $value = $node->nodeValue ?? '';
        if ( '' === \trim( $value ) ) {
            return self::isBetweenInlineContent( $node ) ? ' ' : '';
        }
        $normalized = self::normalizeText( $value );
        return self::hasCodeAncestor( $node ) ? $normalized : self::escapeMarkdown( $normalized );
    }

    /**
     * Whether a node has a `code` or `pre` ancestor — such text is already protected by
     * its own backticks/fence and must not be markdown-escaped on top of that.
     *
     * @param \DOMNode $node The node to check.
     *
     * @return bool True if any ancestor is a `code` or `pre` element.
     */
    private static function hasCodeAncestor( \DOMNode $node ): bool {
        for ( $parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode ) {
            if ( self::isCodeOrPre( $parent ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a node is a `code` or `pre` element.
     *
     * @param \DOMNode $node The node to check.
     *
     * @return bool True if the node is a `code` or `pre` element.
     */
    private static function isCodeOrPre( \DOMNode $node ): bool {
        if ( ! $node instanceof \DOMElement ) {
            return false;
        }
        return \in_array( \strtolower( $node->tagName ), array( 'code', 'pre' ), true );
    }

    /**
     * Escapes markdown-significant characters in a literal text run (Turndown-style), so
     * the hub's markdown renderer shows exactly what the user typed. See the class
     * docblock's escaping contract for which characters are escaped, and where.
     *
     * @param string $text The already whitespace-normalized text.
     *
     * @return string The escaped text.
     */
    private static function escapeMarkdown( string $text ): string {
        // Backslash first, so it is never re-escaped by the substitutions below.
        $text = \str_replace( '\\', '\\\\', $text );
        // Inline constructs: significant wherever they appear (this also covers a
        // leading `*`, so no separate leading-of-line rule for it is needed).
        $text = \str_replace(
            array( '`', '*', '_', '[', ']' ),
            array( '\\`', '\\*', '\\_', '\\[', '\\]' ),
            $text,
        );
        // Leading-of-line constructs: only significant as the very first character(s) of
        // the (already-trimmed) text run.
        $text = (string) \preg_replace_callback(
            '/^(#{1,6}) /',
            static fn( array $m ): string => '\\' . $m[1] . ' ',
            $text,
        );
        $text = (string) \preg_replace_callback(
            '/^([-+>])/',
            static fn( array $m ): string => '\\' . $m[1],
            $text,
        );
        return (string) \preg_replace_callback(
            '/^(\d+)([.)])/',
            static fn( array $m ): string => $m[1] . '\\' . $m[2],
            $text,
        );
    }

    /**
     * Whether a whitespace-only text node sits between two runs of inline content (as
     * opposed to touching a block-level element, or a document boundary).
     *
     * @param \DOMText $node The whitespace-only text node.
     *
     * @return bool True if both neighbors are inline (the whitespace is a word separator).
     */
    private static function isBetweenInlineContent( \DOMText $node ): bool {
        return ! self::touchesBlock( $node->previousSibling ) && ! self::touchesBlock( $node->nextSibling );
    }

    /**
     * Whether a sibling is a block-level element, or absent (a document boundary, which is
     * treated the same as touching a block — there is nothing inline to separate).
     *
     * @param \DOMNode|null $sibling The sibling node, or null.
     *
     * @return bool True if the sibling counts as "block" for whitespace-collapsing purposes.
     */
    private static function touchesBlock( ?\DOMNode $sibling ): bool {
        if ( null === $sibling ) {
            return true;
        }
        if ( ! $sibling instanceof \DOMElement ) {
            return false;
        }
        return \in_array( \strtolower( $sibling->tagName ), self::BLOCK_TAGS, true );
    }

    /**
     * Collapses any run of whitespace (including HTML source formatting/newlines, and
     * non-breaking spaces) in a text node to a single regular space — standard HTML
     * whitespace handling outside `pre`/`code` (both of which bypass this entirely).
     *
     * @param string $text The raw text node value.
     *
     * @return string The normalized text.
     */
    private static function normalizeText( string $text ): string {
        return (string) \preg_replace( '/[ \t\r\n\x{00A0}]+/u', ' ', $text );
    }
}
