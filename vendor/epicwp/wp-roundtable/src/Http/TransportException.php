<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

// phpcs:disable SlevomatCodingStandard.Classes.SuperfluousExceptionNaming.SuperfluousSuffix -- the `*Exception` suffix is the conventional, discoverable name external devs expect for a public exception type.
/** A transport-level failure (no HTTP response was received). */
final class TransportException extends \RuntimeException {
}
