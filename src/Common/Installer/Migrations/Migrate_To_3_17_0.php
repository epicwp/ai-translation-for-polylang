<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.17.0: move saved OpenAI models off the GPT-4o / GPT-4.1 families.
 *
 * OpenAI retires gpt-4o, gpt-4o-mini, gpt-4.1 and gpt-4.1-mini on 2027-01-14,
 * and they are no longer offered in the model list. A saved model outside that
 * list fails AI_Provider_Factory::get_validation_errors(), so without this remap
 * every site still on one of them stops translating on update. gpt-4.1 was the
 * default until gpt-5.4-nano, so many sites have it saved explicitly.
 *
 * Idempotent: only the four retired keys are rewritten; any other value stays.
 */
final class Migrate_To_3_17_0 {
	public const VERSION = '3.17.0';

	private const REPLACEMENTS = array(
		'gpt-4o-mini'  => 'gpt-6-luna',
		'gpt-4.1-mini' => 'gpt-6-luna',
		'gpt-4.1'      => 'gpt-6-sol',
		'gpt-4o'       => 'gpt-6-sol',
	);

	public static function run(): void {
		$model = \get_option( 'pllat_openai_translation_model' );
		if ( ! \is_string( $model ) || ! isset( self::REPLACEMENTS[ $model ] ) ) {
			return;
		}

		\update_option( 'pllat_openai_translation_model', self::REPLACEMENTS[ $model ] );
	}
}
