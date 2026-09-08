<?php
/**
 * Token_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Service for generating and verifying encrypted access tokens.
 * Used for authenticating external API requests back to WordPress.
 */
class Token_Service {
    /**
     * Token expiration time in seconds (1 hour).
     */
    private const EXPIRATION_SECONDS = 3600;

    /**
     * Encryption cipher method.
     */
    private const CIPHER_METHOD = 'AES-256-CBC';

    /**
     * Generate an encrypted token for a run ID.
     * Token contains run_id + timestamp, expires after 1 hour.
     *
     * @param int $run_id The run ID to encode in the token.
     * @return string Base64-encoded encrypted token.
     * @throws \Exception If encryption fails.
     */
    public function generate_token( int $run_id ): string {
        $payload = array(
            'run_id'    => $run_id,
            'timestamp' => \time(),
        );

        $json = \wp_json_encode( $payload );
        if ( false === $json ) {
            throw new \Exception( 'Failed to encode token payload' );
        }

        $encrypted = $this->encrypt( $json );

        return \base64_encode( $encrypted );
    }

    /**
     * Verify and decode a token.
     * Returns the run_id if valid, false if invalid or expired.
     *
     * @param string $token Base64-encoded encrypted token.
     * @return int|false Run ID if valid, false otherwise.
     */
    public function verify_token( string $token ) {
        try {
            // Decode base64.
            $encrypted = \base64_decode( $token, true );
            if ( false === $encrypted ) {
                return false;
            }

            // Decrypt.
            $json = $this->decrypt( $encrypted );
            if ( false === $json ) {
                return false;
            }

            // Parse JSON.
            $payload = \json_decode( $json, true );
            if ( ! \is_array( $payload ) || ! isset( $payload['run_id'], $payload['timestamp'] ) ) {
                return false;
            }

            // Check expiration.
            $timestamp = (int) $payload['timestamp'];
            $age       = \time() - $timestamp;

            if ( $age > self::EXPIRATION_SECONDS || $age < 0 ) {
                return false; // Expired or future timestamp.
            }

            return (int) $payload['run_id'];
        } catch ( \Exception ) {
            return false;
        }
    }

    /**
     * Encrypt data using WordPress AUTH_KEY.
     *
     * @param string $data Data to encrypt.
     * @return string Encrypted data (IV + ciphertext).
     * @throws \Exception If encryption fails.
     */
    private function encrypt( string $data ): string {
        $key = $this->get_encryption_key();
        $iv  = \openssl_random_pseudo_bytes( \openssl_cipher_iv_length( self::CIPHER_METHOD ) );

        $encrypted = \openssl_encrypt( $data, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            throw new \Exception( 'Encryption failed' );
        }

        // Prepend IV to ciphertext.
        return $iv . $encrypted;
    }

    /**
     * Decrypt data using WordPress AUTH_KEY.
     *
     * @param string $encrypted_data Encrypted data (IV + ciphertext).
     * @return string|false Decrypted data, or false on failure.
     */
    private function decrypt( string $encrypted_data ) {
        $key     = $this->get_encryption_key();
        $iv_size = \openssl_cipher_iv_length( self::CIPHER_METHOD );

        // Guard: Ensure data is long enough to contain IV.
        if ( \strlen( $encrypted_data ) <= $iv_size ) {
            return false;
        }

        // Extract IV and ciphertext.
        $iv         = \substr( $encrypted_data, 0, $iv_size );
        $ciphertext = \substr( $encrypted_data, $iv_size );

        $decrypted = \openssl_decrypt( $ciphertext, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );

        return $decrypted;
    }

    /**
     * Get encryption key from WordPress constants.
     * Uses AUTH_KEY, falls back to SECURE_AUTH_KEY.
     *
     * @return string Encryption key.
     * @throws \Exception If no key is available.
     */
    private function get_encryption_key(): string {
        if ( \defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            return \hash( 'sha256', AUTH_KEY, true );
        }

        if ( \defined( 'SECURE_AUTH_KEY' ) && '' !== SECURE_AUTH_KEY ) {
            return \hash( 'sha256', SECURE_AUTH_KEY, true );
        }

        throw new \Exception( 'No WordPress authentication key available for encryption' );
    }
}
