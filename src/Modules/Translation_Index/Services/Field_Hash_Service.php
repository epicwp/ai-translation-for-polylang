<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Field hash service.
 *
 * Produces a stable 16-char hex digest for a source field value. Both the
 * worker (write-on-success) and reconciliation (read-as-short-circuit)
 * route through this helper so they always agree on what "the same source
 * value" means.
 *
 * Uses xxh3 — fast, well-distributed, non-cryptographic. Collision risk
 * at our row scale (millions, not billions) is negligible.
 */
class Field_Hash_Service {

    /**
     * Hash a string value to a 16-char lowercase hex digest.
     */
    public function hash( string $value ): string {
        return \hash( 'xxh3', $value );
    }

    /**
     * Hash any translatable field value — strings, arrays, scalars.
     *
     * Arrays are serialised canonically (recursive ksort + JSON) so the
     * same content with a different key insertion order produces the
     * same hash.
     */
    public function hash_value( mixed $value ): string {
        if ( \is_string( $value ) ) {
            return $this->hash( $value );
        }
        return $this->hash( (string) \json_encode( $this->canonical( $value ) ) );
    }

    private function canonical( mixed $value ): mixed {
        if ( ! \is_array( $value ) ) {
            return $value;
        }
        \ksort( $value );
        foreach ( $value as $k => $v ) {
            $value[ $k ] = $this->canonical( $v );
        }
        return $value;
    }
}
