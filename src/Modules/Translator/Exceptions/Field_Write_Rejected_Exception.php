<?php
/**
 * Field_Write_Rejected_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Signals that a translated field write did not persist on the target.
 *
 * Thrown by integrations that read a write-back result back and find the
 * stored value does not match what was written (e.g. Bricks, whose theme
 * rejects update_post_metadata for builder content when no builder-capable
 * user is logged in — always the case for userless AS workers).
 *
 * Caught by Lean_Job_Worker::run() to fail the claim loudly: failed
 * activity row, attempts++, and no field-state hash. Recording the field
 * as 'completed' would hide the gap from compute_mini_gaps() forever
 * (issue #459).
 */
class Field_Write_Rejected_Exception extends \Exception {
}
