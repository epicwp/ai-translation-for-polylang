<?php
/**
 * Polylang_Source_Language_Check file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight\Checks
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight\Checks;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Status\Preflight\Preflight_Check;
use PLLAT\Status\Preflight\Preflight_Check_Result;
use PLLAT\Status\Preflight\Preflight_Level;
use PLLAT\Status\Preflight\Preflight_Scope;

/**
 * For single-translation scope: verifies the source content (post or term)
 * has a Polylang language assigned. Without it, translation would fail
 * with a cryptic error.
 *
 * Accepts either context['post_id'] or context['term_id']. Controller
 * decides which one to pass based on the request URL segment ('post' vs
 * 'term'); we do not infer kind ourselves.
 */
class Polylang_Source_Language_Check implements Preflight_Check {

    public function __construct( private Language_Manager $language_manager ) {}

    public function get_name(): string {
        return 'polylang_source_language';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return Preflight_Scope::Single === $scope;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $post_id = (int) ( $context['post_id'] ?? 0 );
        $term_id = (int) ( $context['term_id'] ?? 0 );

        if ( 0 !== $post_id ) {
            $language = $this->language_manager->get_post_language( $post_id );
            if ( '' === $language ) {
                return new Preflight_Check_Result(
                    $this->get_name(),
                    Preflight_Level::Fail,
                    \sprintf( 'Source post %d has no Polylang language assigned.', $post_id ),
                    'Set a language on the post in the editor sidebar before translating.',
                );
            }
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                \sprintf( 'Source post %d is in language %s.', $post_id, $language ),
                null,
            );
        }

        if ( 0 !== $term_id ) {
            $language = $this->language_manager->get_term_language( $term_id );
            if ( '' === $language ) {
                return new Preflight_Check_Result(
                    $this->get_name(),
                    Preflight_Level::Fail,
                    \sprintf( 'Source term %d has no Polylang language assigned.', $term_id ),
                    'Set a language on the term in the taxonomy editor before translating.',
                );
            }
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                \sprintf( 'Source term %d is in language %s.', $term_id, $language ),
                null,
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Fail,
            'No post_id or term_id provided in single-run context.',
            'This is a code bug. Report to plugin author.',
        );
    }
}
