<?php
/**
 * Inline_Tag_Replacer class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Replaces HTML inline tags with numbered placeholders.
 *
 * Converts `<strong>bold</strong>` to `⟨1⟩bold⟨/1⟩` for translation,
 * then restores original tags after translation.
 *
 * This reduces token usage by 40-60% while preserving markup structure.
 */
class Inline_Tag_Replacer {
    /**
     * Placeholder opening delimiter.
     *
     * Using Unicode angle brackets to avoid conflicts with HTML.
     */
    private const PLACEHOLDER_OPEN = '⟨';

    /**
     * Placeholder closing delimiter.
     */
    private const PLACEHOLDER_CLOSE = '⟩';

    /**
     * Inline tags to replace with placeholders.
     *
     * @var array<string>
     */
    private const INLINE_TAGS = array(
        'a',
        'abbr',
        'acronym',
        'b',
        'bdo',
        'big',
        'br',
        'cite',
        'code',
        'del',
        'dfn',
        'em',
        'i',
        'ins',
        'kbd',
        'mark',
        'q',
        's',
        'samp',
        'small',
        'span',
        'strike',
        'strong',
        'sub',
        'sup',
        'time',
        'tt',
        'u',
        'var',
    );

    /**
     * Inline tags that are purely presentational: losing one only loses
     * styling, never content or meaning. A broken placeholder of these tags
     * is dropped instead of failing the field (see placeholder_state()).
     *
     * Every other tag in INLINE_TAGS is load-bearing and stays strict:
     * a (href), br (line break), abbr/acronym/dfn (title), bdo (dir),
     * time (datetime), q (adds quotes), cite/code/kbd/samp/var (semantic
     * content markers). When unsure, a tag belongs there, not here.
     * The class is derived from the stored 'tag', so persisted tag_maps
     * in batch_state keep their shape.
     *
     * @var array<string>
     */
    private const FORMATTING_TAGS = array(
        'b',
        'big',
        'del',
        'em',
        'i',
        'ins',
        'mark',
        's',
        'small',
        'span',
        'strike',
        'strong',
        'sub',
        'sup',
        'tt',
        'u',
    );

    /**
     * Get the placeholder format instruction for LLM system prompt.
     *
     * @return string Instruction text.
     */
    public static function get_prompt_instruction(): string {
        return 'The text contains numbered placeholders in the format ⟨N⟩text⟨/N⟩ (opening/closing) or ⟨N/⟩ (self-closing). ' .
            'These represent HTML formatting tags. Use every placeholder exactly once and unchanged, and keep each pair ' .
            'correctly nested: ⟨1⟩⟨2⟩text⟨/2⟩⟨/1⟩ must remain properly nested after translation. ' .
            'A placeholder may move to follow the word order of the translation, as long as it wraps the same words.';
    }

    /**
     * Replace inline tags with numbered placeholders.
     *
     * Uses a single-pass approach to maintain proper position-based matching
     * between opening and closing tags.
     *
     * @param string $html HTML content with inline tags.
     * @return array{text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}
     */
    public function replace( string $html ): array {
        $tag_map = array();
        $counter = 1;

        // Stack to track unclosed opening tags (for matching closing tags).
        $unclosed_stack = array();

        // Pattern to match both opening tags (with optional attributes) and closing tags.
        // Group 1: optional slash for closing tag.
        // Group 2: tag name.
        // Group 3: optional attributes (only for opening tags).
        // Group 4: optional self-closing slash.
        $tags_pattern = '(' . \implode( '|', self::INLINE_TAGS ) . ')';
        $pattern      = '/<(\/)?' . $tags_pattern . '(\s[^>]*)?(\/)?>/i';

        $result = \preg_replace_callback(
            $pattern,
            static function ( array $matches ) use ( &$tag_map, &$counter, &$unclosed_stack ): string {
                $is_closing   = isset( $matches[1] ) && '/' === $matches[1];
                $tag          = \strtolower( $matches[2] );
                $attrs        = $matches[3] ?? '';
                $self_closing = isset( $matches[4] ) && '/' === $matches[4];

                // Handle closing tags.
                if ( $is_closing ) {
                    // Find the most recent unclosed opening tag with this tag name.
                    for ( $i = \count( $unclosed_stack ) - 1; $i >= 0; $i-- ) {
                        if ( $unclosed_stack[ $i ]['tag'] === $tag ) {
                            $id = $unclosed_stack[ $i ]['id'];
                            // Remove from stack (mark as closed).
                            \array_splice( $unclosed_stack, $i, 1 );
                            return self::PLACEHOLDER_OPEN . '/' . $id . self::PLACEHOLDER_CLOSE;
                        }
                    }
                    // Fallback: return as-is if no matching opening tag found.
                    return $matches[0];
                }

                // Handle self-closing tags (e.g., <br />, <br>).
                if ( $self_closing || 'br' === $tag ) {
                    $id             = $counter++;
                    $tag_map[ $id ] = array(
                        'attrs'        => $attrs,
                        'self_closing' => true,
                        'tag'          => $tag,
                    );
                    return self::PLACEHOLDER_OPEN . $id . '/' . self::PLACEHOLDER_CLOSE;
                }

                // Handle opening tags.
                $id             = $counter++;
                $tag_map[ $id ] = array(
                    'attrs'        => $attrs,
                    'self_closing' => false,
                    'tag'          => $tag,
                );

                // Push to stack for later matching with closing tag.
                $unclosed_stack[] = array(
                    'id'  => $id,
                    'tag' => $tag,
                );

                return self::PLACEHOLDER_OPEN . $id . self::PLACEHOLDER_CLOSE;
            },
            $html,
        );

        // Symmetric to the unmatched-closing-tag fallback inside the
        // callback: any opening tag still on the stack was never closed
        // within this string (common when a link/span spans inner blocks
        // or innerContent segments). An opening-only placeholder makes
        // validate() unsatisfiable — a faithful AI echo would still fail
        // "Invalid placeholder structure". Revert it to the raw tag and
        // drop it from the tag map so it round-trips literally and
        // validate() has no orphan demanding a closing placeholder.
        if ( \count( $unclosed_stack ) > 0 && \is_string( $result ) ) {
            foreach ( $unclosed_stack as $unclosed ) {
                $id = $unclosed['id'];
                if ( ! isset( $tag_map[ $id ] ) ) {
                    continue;
                }
                $raw_tag = '<' . $tag_map[ $id ]['tag'] . $tag_map[ $id ]['attrs'] . '>';
                $result  = \str_replace(
                    self::PLACEHOLDER_OPEN . $id . self::PLACEHOLDER_CLOSE,
                    $raw_tag,
                    $result,
                );
                unset( $tag_map[ $id ] );
            }
        }

        return array(
            'tag_map' => $tag_map,
            'text'    => $result,
        );
    }

    /**
     * Restore original HTML tags from placeholders.
     *
     * @param string                                                            $text    Text with placeholders.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Tag map from replace().
     * @return string HTML with restored tags.
     */
    public function restore( string $text, array $tag_map ): string {
        $text = $this->strip_dropped( $text, $tag_map );

        // Restore self-closing tags.
        $result = \preg_replace_callback(
            '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '(\d+)\/' . \preg_quote(
                self::PLACEHOLDER_CLOSE,
                '/',
            ) . '/',
            static function ( array $matches ) use ( $tag_map ): string {
                $id = (int) $matches[1];
                if ( ! isset( $tag_map[ $id ] ) ) {
                    return $matches[0]; // Return placeholder if not found.
                }

                $info = $tag_map[ $id ];
                return '<' . $info['tag'] . $info['attrs'] . ' />';
            },
            $text,
        );

        // Restore opening tags.
        $result = \preg_replace_callback(
            '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '(\d+)' . \preg_quote(
                self::PLACEHOLDER_CLOSE,
                '/',
            ) . '/',
            static function ( array $matches ) use ( $tag_map ): string {
                $id = (int) $matches[1];
                if ( ! isset( $tag_map[ $id ] ) ) {
                    return $matches[0]; // Return placeholder if not found.
                }

                $info = $tag_map[ $id ];
                return '<' . $info['tag'] . $info['attrs'] . '>';
            },
            $result,
        );

        // Restore closing tags.
        $result = \preg_replace_callback(
            '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '\/(\d+)' . \preg_quote(
                self::PLACEHOLDER_CLOSE,
                '/',
            ) . '/',
            static function ( array $matches ) use ( $tag_map ): string {
                $id = (int) $matches[1];
                if ( ! isset( $tag_map[ $id ] ) ) {
                    return $matches[0]; // Return placeholder if not found.
                }

                $info = $tag_map[ $id ];
                return '</' . $info['tag'] . '>';
            },
            $result,
        );

        return $result;
    }

    /**
     * Validate that placeholders in translated text match the tag map.
     *
     * @param string                                                            $text    Translated text with placeholders.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Original tag map.
     * @return bool True if all placeholders are valid.
     */
    public function validate( string $text, array $tag_map ): bool {
        // Extract all placeholder IDs from text.
        $pattern = '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '\/?(\d+)\/?' . \preg_quote(
            self::PLACEHOLDER_CLOSE,
            '/',
        ) . '/';
        \preg_match_all( $pattern, $text, $matches );

        $found_ids = \array_map( 'intval', $matches[1] );

        // Check that all found IDs exist in tag map.
        foreach ( $found_ids as $id ) {
            if ( ! isset( $tag_map[ $id ] ) ) {
                return false;
            }
        }

        // Check that all non-self-closing tags have both opening and closing.
        foreach ( $tag_map as $id => $info ) {
            if ( $info['self_closing'] ) {
                // Self-closing: should have ⟨N/⟩.
                $self_close_pattern = '/' . \preg_quote(
                    self::PLACEHOLDER_OPEN,
                    '/',
                ) . $id . '\/' . \preg_quote( self::PLACEHOLDER_CLOSE, '/' ) . '/';
                if ( ! \preg_match( $self_close_pattern, $text ) ) {
                    return false;
                }
            } else {
                // Regular tag: should have both ⟨N⟩ and ⟨/N⟩.
                $open_pattern  = '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . $id . \preg_quote(
                    self::PLACEHOLDER_CLOSE,
                    '/',
                ) . '/';
                $close_pattern = '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '\/' . $id . \preg_quote(
                    self::PLACEHOLDER_CLOSE,
                    '/',
                ) . '/';

                if ( ! \preg_match( $open_pattern, $text ) || ! \preg_match( $close_pattern, $text ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a translation that fails validate() is still safely restorable.
     *
     * True ONLY when every defect is droppable (see placeholder_state()):
     * - a non-self-closing id that is ENTIRELY absent (the LLM rephrased the
     *   wrapped phrase away, observed deterministically on real content), or
     * - any broken placeholder of a FORMATTING_TAGS tag (open-only,
     *   close-only, duplicated, reversed): losing it only loses styling.
     * restore() then omits those wrappers, so the field stays translated
     * instead of hard-failing and burning retries. Genuine corruption, an id
     * the map does not know or a partially present / absent self-closing
     * load-bearing tag (a link that lost its close, a missing <br/>), is NOT
     * recoverable and must still fail.
     *
     * @param string                                                            $text    Translated text with placeholders.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Original tag map.
     * @return bool True if the only defects are droppable wrappers.
     */
    public function is_recoverable( string $text, array $tag_map ): bool {
        $found_pattern = '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '\/?(\d+)\/?' . \preg_quote(
            self::PLACEHOLDER_CLOSE,
            '/',
        ) . '/';
        \preg_match_all( $found_pattern, $text, $matches );

        // A placeholder id in the text the map does not know is corruption.
        foreach ( \array_map( 'intval', $matches[1] ) as $found_id ) {
            if ( ! isset( $tag_map[ $found_id ] ) ) {
                return false;
            }
        }

        $has_droppable = false;
        foreach ( $tag_map as $id => $info ) {
            $state = $this->placeholder_state( (int) $id, $info, $text );
            if ( 'corrupt' === $state ) {
                return false;
            }
            $has_droppable = $has_droppable || 'drop' === $state;
        }

        return $has_droppable;
    }

    /**
     * Remove every placeholder token (open, close, self-close) of each id
     * placeholder_state() classifies as 'drop', so a dropped wrapper leaves
     * neither a stray placeholder nor a half tag behind.
     *
     * @param string                                                            $text    Translated text with placeholders.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Original tag map.
     * @return string Text without the dropped ids' placeholders.
     */
    private function strip_dropped( string $text, array $tag_map ): string {
        foreach ( $tag_map as $id => $info ) {
            if ( 'drop' !== $this->placeholder_state( (int) $id, $info, $text ) ) {
                continue;
            }
            $text = (string) \preg_replace(
                '/' . \preg_quote( self::PLACEHOLDER_OPEN, '/' ) . '\/?' . (int) $id . '\/?' . \preg_quote(
                    self::PLACEHOLDER_CLOSE,
                    '/',
                ) . '/',
                '',
                $text,
            );
        }

        return $text;
    }

    /**
     * Classify one tag_map id against the translated text.
     *
     * @param int                                                   $id   Placeholder id.
     * @param array{tag: string, attrs: string, self_closing: bool} $info Tag map entry.
     * @param string                                                $text Translated text with placeholders.
     * @return string 'ok' (intact), 'drop' (safe to omit), or 'corrupt' (broken structure).
     */
    private function placeholder_state( int $id, array $info, string $text ): string {
        return \in_array( $info['tag'], self::FORMATTING_TAGS, true )
            ? $this->formatting_state( $id, (bool) $info['self_closing'], $text )
            : $this->load_bearing_state( $id, (bool) $info['self_closing'], $text );
    }

    /**
     * Classify a load-bearing id (strict): a non-self-closing id needs both
     * open and close ('ok') and is 'drop' only when entirely absent; a
     * self-closing id must be present. Anything else is 'corrupt'.
     *
     * @param int    $id           Placeholder id.
     * @param bool   $self_closing Whether the original tag was self-closing.
     * @param string $text         Translated text with placeholders.
     * @return string 'ok', 'drop', or 'corrupt'.
     */
    private function load_bearing_state( int $id, bool $self_closing, string $text ): string {
        $open_q  = \preg_quote( self::PLACEHOLDER_OPEN, '/' );
        $close_q = \preg_quote( self::PLACEHOLDER_CLOSE, '/' );

        if ( $self_closing ) {
            $present = 1 === \preg_match( '/' . $open_q . $id . '\/' . $close_q . '/', $text );
            return $present ? 'ok' : 'corrupt';
        }

        $has_open  = 1 === \preg_match( '/' . $open_q . $id . $close_q . '/', $text );
        $has_close = 1 === \preg_match( '/' . $open_q . '\/' . $id . $close_q . '/', $text );

        if ( $has_open && $has_close ) {
            return 'ok';
        }
        if ( ! $has_open && ! $has_close ) {
            return 'drop';
        }
        return 'corrupt';
    }

    /**
     * Classify a FORMATTING_TAGS id: 'ok' when well-formed (exactly one open
     * before exactly one close, or exactly one self-close), otherwise 'drop'
     * (absent, open-only, close-only, duplicated or reversed). Never
     * 'corrupt': losing the wrapper only loses styling.
     *
     * @param int    $id           Placeholder id.
     * @param bool   $self_closing Whether the original tag was self-closing.
     * @param string $text         Translated text with placeholders.
     * @return string 'ok' or 'drop'.
     */
    private function formatting_state( int $id, bool $self_closing, string $text ): string {
        $self  = self::PLACEHOLDER_OPEN . $id . '/' . self::PLACEHOLDER_CLOSE;
        $open  = self::PLACEHOLDER_OPEN . $id . self::PLACEHOLDER_CLOSE;
        $close = self::PLACEHOLDER_OPEN . '/' . $id . self::PLACEHOLDER_CLOSE;

        $well_formed = $self_closing
            ? 1 === \substr_count( $text, $self )
            : 1 === \substr_count( $text, $open )
                && 1 === \substr_count( $text, $close )
                && \strpos( $text, $open ) < \strpos( $text, $close );

        return $well_formed ? 'ok' : 'drop';
    }
}
