<?php
/**
 * Run_State_Locked_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Database
 */

declare(strict_types=1);

namespace PLLAT\Common\Database;

\defined( 'ABSPATH' ) || exit;

class Run_State_Locked_Exception extends \RuntimeException {

	public function __construct( public readonly string $lock_name, public readonly int $timeout ) {
		parent::__construct( \sprintf(
			'Could not acquire advisory lock "%s" within %ds. Another operation may be in progress.',
			$lock_name,
			$timeout,
		) );
	}
}
