<?php
declare(strict_types=1);

namespace PLLAT\Admin\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\Asset_Service;

/**
 * Service for providing admin interface data.
 *
 * Handles collection and formatting of data needed by the frontend JavaScript
 * components, including languages, post types, and taxonomies.
 */
class Admin_Data_Service {
    /**
     * Constructor.
     *
     * @param Language_Manager $language_manager The language manager service.
     * @param Asset_Service    $asset_service    The asset service.
     */
    public function __construct(
        protected Language_Manager $language_manager,
        protected Asset_Service $asset_service,
    ) {
    }

    /**
     * Get all data needed for admin interface.
     *
     * @return array The complete data array.
     */
    public function get_all_data(): array {
        return array(
            'assets'          => $this->asset_service->get_shared_assets(),
            'defaultLanguage' => $this->language_manager->get_default_language(),
            'languages'       => $this->get_languages_data(),
            'licenseValid'    => \xwp_app( 'pllat' )->get( 'license.valid' ),
            'postTypes'       => $this->get_post_types_data(),
            'taxonomies'      => $this->get_taxonomies_data(),
        );
    }

    /**
     * Get formatted languages data for JavaScript.
     *
     * @return array Array of language objects with slug, name, and flag.
     */
    public function get_languages_data(): array {
        return $this->language_manager->get_languages_data();
    }

    /**
     * Get formatted post types data for JavaScript.
     *
     * @return array Array of post type objects with slug and label.
     */
    public function get_post_types_data(): array {
        $post_type_slugs      = Helpers::get_available_post_types();
        $formatted_post_types = array();

        foreach ( $post_type_slugs as $slug ) {
            $post_type_obj = \get_post_type_object( $slug );

            if ( ! $post_type_obj ) {
                continue;
            }

            $formatted_post_types[] = array(
                'label' => $post_type_obj->labels->name ?? $post_type_obj->label ?? $slug,
                'slug'  => $slug,
            );
        }

        return $formatted_post_types;
    }

    /**
     * Get formatted taxonomies data for JavaScript.
     *
     * @return array Array of taxonomy objects with slug and label.
     */
    public function get_taxonomies_data(): array {
        $taxonomy_slugs       = Helpers::get_available_taxonomies();
        $formatted_taxonomies = array();

        foreach ( $taxonomy_slugs as $slug ) {
            $taxonomy_obj = \get_taxonomy( $slug );

            if ( ! $taxonomy_obj ) {
                continue;
            }

            $formatted_taxonomies[] = array(
                'label' => $taxonomy_obj->labels->name ?? $taxonomy_obj->label ?? $slug,
                'slug'  => $slug,
            );
        }

        return $formatted_taxonomies;
    }
}
