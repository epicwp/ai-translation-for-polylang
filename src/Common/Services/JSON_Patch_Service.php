<?php
declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Exceptions\Invalid_Patch_Path_Exception;
use PLLAT\Common\Exceptions\Invalid_Patch_Value_Exception;
use PLLAT\Common\Services\Field_Validators\Field_Validator_Interface;
use Rs\Json\Patch;

/**
 * Service for applying RFC 6902 JSON patches and finding translatable paths.
 */
class JSON_Patch_Service {
    /**
     * Apply RFC 6902 JSON patches to a JSON string.
     *
     * @param string                       $json JSON string to patch.
     * @param array<array<string, string>> $patches Array of RFC 6902 patch operations.
     * @return string Patched JSON string.
     * @throws \Exception If patches or JSON are invalid.
     */
    public static function apply_patches( string $json, array $patches ): string {
        $target = \json_decode( $json, true );
        if ( null === $target && \JSON_ERROR_NONE !== \json_last_error() ) {
            throw new \Exception( 'Invalid target document JSON: ' . \esc_html( \json_last_error_msg() ) );
        }

        foreach ( $patches as $patch_op ) {
            self::validate_patch( $target, $patch_op );
        }

        try {
            $patches_json = \wp_json_encode( $patches );
            if ( false === $patches_json ) {
                throw new \Exception( 'Failed to encode patches array to JSON' );
            }
            $patch = new Patch( $json, $patches_json );
            return $patch->apply();
        } catch ( \Rs\Json\Patch\InvalidPatchDocumentJsonException ) {
            throw new \Exception( 'Invalid patch document JSON' );
        } catch ( \Rs\Json\Patch\InvalidTargetDocumentJsonException ) {
            throw new \Exception( 'Invalid target document JSON' );
        } catch ( \Rs\Json\Patch\InvalidOperationException ) {
            throw new \Exception( 'Invalid JSON Pointer operation' );
        }
    }

    /**
     * Validate a single patch op against the decoded target document.
     *
     * Catches the common AI failure modes before the library panics with
     * a vague InvalidOperationException: path that doesn't resolve, or a
     * value that isn't a string (AI translations must always be strings).
     *
     * @param mixed               $target Decoded target document.
     * @param array<string,mixed> $patch  RFC 6902 patch op.
     * @throws Invalid_Patch_Path_Exception
     * @throws Invalid_Patch_Value_Exception
     */
    private static function validate_patch( mixed $target, array $patch ): void {
        $op   = (string) ( $patch['op'] ?? '' );
        $path = (string) ( $patch['path'] ?? '' );

        if (
            \in_array( $op, array( 'add', 'replace' ), true )
            && \array_key_exists( 'value', $patch )
            && ! \is_string( $patch['value'] )
        ) {
            throw new Invalid_Patch_Value_Exception( \esc_html( $path ), 'string' );
        }

        if (
            \in_array( $op, array( 'replace', 'remove', 'move', 'copy' ), true )
            && ! self::path_exists( $target, $path )
        ) {
            throw new Invalid_Patch_Path_Exception( \esc_html( $path ), 'path does not resolve in target' );
        }
    }

    /**
     * Check whether a JSON Pointer resolves against a decoded document.
     *
     * @param mixed  $data Decoded target.
     * @param string $path RFC 6901 JSON Pointer.
     */
    private static function path_exists( mixed $data, string $path ): bool {
        if ( '' === $path || '/' === $path ) {
            return true;
        }
        $parts  = \explode( '/', \ltrim( $path, '/' ) );
        $cursor = $data;
        foreach ( $parts as $part ) {
            $part = \str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
            if ( \is_array( $cursor ) && \array_key_exists( $part, $cursor ) ) {
                $cursor = $cursor[ $part ];
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Find translatable patches in a JSON structure using a field validator.
     *
     * Recursively walks through the data structure and identifies fields that should
     * be translated based on the validator's rules. Returns patch data (path + value)
     * that can be used for translation.
     *
     * @param mixed                     $data Data structure (decoded JSON).
     * @param Field_Validator_Interface $validator Field validator to determine translatability.
     * @param string                    $current_path Current RFC 6901 JSON Pointer path.
     * @return array<array{path: string, value: string}> Array of translatable patches (path + value).
     */
    public static function find_translatable_patches(
        mixed $data,
        Field_Validator_Interface $validator,
        string $current_path = '',
    ): array {
        $results = array();

        if ( null === $data ) {
            return $results;
        }

        // Handle arrays (numeric indices).
        if ( \is_array( $data ) && \array_is_list( $data ) ) {
            foreach ( $data as $index => $item ) {
                $new_path = "{$current_path}/{$index}";
                $results  = \array_merge(
                    $results,
                    self::find_translatable_patches( $item, $validator, $new_path ),
                );
            }
            return $results;
        }

        // Handle objects (associative arrays).
        if ( \is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $new_path = "{$current_path}/{$key}";

                // Check if this field should be translated.
                // Cast $key to string: PHP coerces numeric-string JSON object keys to int on decode,
                // and should_translate is typed string under strict_types. Elementor's image-carousel
                // and other widgets mix integer slide keys with string config keys.
                if ( $validator->should_translate( (string) $key, $value ) ) {
                    $results[] = array(
                        'path'  => $new_path,
                        'value' => $value,
                    );
                }

                // Recurse into nested structures.
                if ( ! \is_array( $value ) ) {
                    continue;
                }

                $results = \array_merge(
                    $results,
                    self::find_translatable_patches( $value, $validator, $new_path ),
                );
            }
        }

        return $results;
    }
}
