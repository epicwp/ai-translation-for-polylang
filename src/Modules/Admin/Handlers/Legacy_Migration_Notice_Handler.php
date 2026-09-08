<?php
declare(strict_types=1);

namespace PLLAT\Admin\Handlers;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * One-time admin notice after the lean-pipeline upgrade.
 *
 * Read partner: Installer::capture_pre_lean_summary(), which writes the
 * pllat_legacy_migration_summary option just before Migrate_To_3_11_0
 * drops pllat_jobs / pllat_tasks / pllat_activity_log. Without this notice
 * the customer sees a silent zeroed dashboard and assumes the upgrade lost
 * their work — actually, the reconciliation walk re-discovers anything that
 * still genuinely needs translating; the dropped rows were stale queue.
 *
 * Dismissal sets a separate flag option so we don't have to mutate the
 * summary record itself.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN )]
class Legacy_Migration_Notice_Handler {

    private const SUMMARY_OPTION   = 'pllat_legacy_migration_summary';
    private const DISMISSED_OPTION = 'pllat_legacy_migration_notice_dismissed';
    private const DISMISS_ACTION   = 'pllat_dismiss_legacy_migration_notice';

    #[Action( tag: 'admin_notices' )]
    public function maybe_show(): void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( \get_option( self::DISMISSED_OPTION ) ) {
            return;
        }

        // The summary option's mere existence is the signal that a real
        // pre-lean → lean migration happened on this site (Installer only
        // writes it when crossing that boundary with non-empty legacy
        // state). We deliberately do NOT surface the captured counts: they
        // are a migration-time snapshot frozen in a static notice, while the
        // dashboard is live. Once the prime walk re-discovers the real
        // translation state, those numbers match nothing the customer sees —
        // and "N translations retired" reads as data loss when the pipeline
        // actually re-detects that content. The counts stay in the option
        // for support diagnostics only.
        $summary = \get_option( self::SUMMARY_OPTION );
        if ( ! \is_array( $summary ) ) {
            return;
        }

        $dismiss_url = \wp_nonce_url(
            \admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
            self::DISMISS_ACTION,
        );

        $headline = \__(
            'Polylang AI Translation: updated to the new translation pipeline.',
            'ai-translation-for-polylang',
        );

        // The dashboard is gated behind a valid license — sending an
        // unlicensed customer there dumps them on a "Sorry, you are not
        // allowed to access this page" wall. Re-target the CTA at the
        // license tab in that case so the flow always lands on something
        // the customer can act on. `license.valid` is the DI value defined
        // in App::configure() and re-used across Settings; reading it via
        // the container avoids duplicating the License_Service dependency
        // chain (and bypasses constructor injection for what is genuinely
        // a one-shot, optional UI hint).
        $has_license = (bool) \xwp_app( 'pllat' )->get( 'license.valid' );
        if ( $has_license ) {
            $cta_url   = \admin_url( 'admin.php?page=polylang-ai-translate-bulk' );
            $cta_label = \__( 'Open dashboard', 'ai-translation-for-polylang' );
            $body      = \__(
                'Your content has been automatically re-scanned. Open the Dashboard to review your translations and continue where needed.',
                'ai-translation-for-polylang',
            );
        } else {
            $cta_url   = \admin_url( 'admin.php?page=pllat-settings&tab=license' );
            $cta_label = \__( 'Activate license', 'ai-translation-for-polylang' );
            $body      = \__(
                'Your content has been automatically re-scanned. Activate your license to review your translations and continue where needed.',
                'ai-translation-for-polylang',
            );
        }

        \printf(
            '<div class="notice notice-info"><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a> <a href="%s">%s</a></p></div>',
            \esc_html( $headline ),
            \esc_html( $body ),
            \esc_url( $cta_url ),
            \esc_html( $cta_label ),
            \esc_url( $dismiss_url ),
            \esc_html__( 'Dismiss', 'ai-translation-for-polylang' ),
        );
    }

    #[Action( tag: 'admin_post_pllat_dismiss_legacy_migration_notice' )]
    public function dismiss(): void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( '', '', array( 'response' => 403 ) );
        }
        \check_admin_referer( self::DISMISS_ACTION );

        \update_option( self::DISMISSED_OPTION, 1, false );

        \wp_safe_redirect( \admin_url() );
        exit;
    }
}
