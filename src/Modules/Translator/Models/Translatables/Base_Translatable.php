<?php
namespace PLLAT\Translator\Models\Translatables;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translator\Enums\TranslatableMetaKey as MetaKey;

/**
 * Abstract class for translatable entities (terms and posts).
 *
 * Lean: no Job/Task collection. The lean pipeline derives candidates and
 * fields directly via Job_Claim_Service and Run_Reconciliation_Service;
 * Translatable is a thin source-side helper for ID/language lookups and
 * translatable-field discovery.
 */
abstract class Base_Translatable {
    public static function get_instance( int $id ): static {
        return new static(
            $id,
            \xwp_app( 'pllat' )->get( Language_Manager::class ),
        );
    }

    public function __construct(
        protected int $id,
        protected Language_Manager $language_manager,
    ) {
    }

    abstract public function set_excluded_from_translation( bool $excluded ): void;

    /** @return array<int, string> */
    abstract public function get_available_fields(): array;

    /** @return array<int, string> */
    abstract public function get_available_meta_fields(): array;

    abstract public function get_language(): string;

    abstract public function get_type(): string;

    /** @return array<string, int> Polylang translations map: lang => target_id */
    abstract public function get_translations(): array;

    /**
     * Get a meta value. Override in subclasses.
     *
     * @return mixed
     */
    abstract public function get_meta( string $key, bool $single = false );

    public function get_id(): int {
        return $this->id;
    }

    /**
     * Languages still to translate to (excludes source + already-translated).
     *
     * @return array<int, string>
     */
    public function get_missing_languages(): array {
        return \array_values( \array_diff(
            $this->get_available_languages(),
            \array_keys( $this->get_translations() ),
            array( $this->get_language() ),
        ) );
    }

    public function get_last_processed(): ?int {
        $value = $this->get_meta( MetaKey::Processed->value, true );
        return null === $value ? null : (int) $value;
    }

    public function is_excluded_from_translation(): bool {
        return true === $this->get_meta( MetaKey::Exclude->value, true );
    }

    /**
     * Available target languages (all minus this entity's source language).
     *
     * @return array<int, string>
     */
    public function get_available_languages(): array {
        return \array_values( \array_diff(
            $this->language_manager->get_available_languages( false ),
            array( $this->get_language() ),
        ) );
    }

    public function get_translation_by_language( string $language ): ?int {
        $translations = $this->get_translations();
        return $translations[ $language ] ?? null;
    }

    public function get_translation_id( string $lang_to ): int {
        $translations = $this->get_translations();
        return (int) ( $translations[ $lang_to ] ?? 0 );
    }
}
