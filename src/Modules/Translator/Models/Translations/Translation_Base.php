<?php
namespace PLLAT\Translator\Models\Translations;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Models\Interfaces\Translation;

/**
 * Translation_Base class.
 *
 * @package PLLAT\Translator\Models\Translations
 */
abstract class Translation_Base implements Translation {
    /**
     * The translation data.
     *
     * @var array
     */
    protected $translation = array();

    /**
     * Get all the translation data.
     *
     * @return array
     */
    public function get_all() {
        return $this->translation;
    }

    /**
     * Get the translation for a specific key.
     *
     * @param string $key The key to get the translation for.
     * @return mixed
     */
    public function get_translation( string $key ) {
        return $this->translation[ $key ] ?? null;
    }

    /**
     * Set the translation for a specific key.
     *
     * @param string $key The key to set the translation for.
     * @param mixed  $value The value to set.
     */
    public function set_field( string $key, $value ) {
        $this->translation[ $key ] = $value;
    }
}
