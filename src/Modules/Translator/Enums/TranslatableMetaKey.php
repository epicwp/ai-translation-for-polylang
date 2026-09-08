<?php //phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
namespace PLLAT\Translator\Enums;

\defined( 'ABSPATH' ) || exit;

enum TranslatableMetaKey: string {
    case QueueMetaField  = '_pllat_meta';
    case QueueCustomData = '_pllat_custom_data';
    case Queue           = '_pllat_translation_queue';
    case Processed       = '_pllat_last_processed';
    case Exclude         = '_pllat_exclude_from_translation';
    case Errors          = '_pllat_translation_errors';
}
