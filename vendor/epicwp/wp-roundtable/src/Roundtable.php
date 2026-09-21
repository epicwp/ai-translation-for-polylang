<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Http\WpHttpTransport;

/**
 * The SDK's single entry point.
 *
 * A consuming plugin calls {@see Roundtable::mount()} once with its {@see Config} and the
 * parent admin menu slug the "Community" submenu attaches to. This wires the admin page
 * and the REST routes; the chat/message routes register only when {@see Config::$enableChat}
 * is true.
 */
final class Roundtable {
    /**
     * Bootstrap the SDK.
     *
     * @param Config $config   The SDK configuration.
     * @param string $menuSlug The parent admin menu slug the "Community" submenu attaches to.
     */
    public static function mount( Config $config, string $menuSlug ): void {
        $hub  = new HubClient( $config, new WpHttpTransport() );
        $page = new Admin\Page( $config, $menuSlug, $hub );
        \add_action( 'admin_menu', array( $page, 'registerMenu' ) );
        \add_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) );
        \add_action( 'in_admin_header', array( $page, 'suppressAdminNotices' ) );

        $cases         = new CasesController( $config, $hub );
        $myCases       = new MyCasesController( $config, $hub );
        $participating = new ParticipatingCasesController( $config, $hub );
        $comments      = new CommentsController( $config, $hub );
        $votes         = new VotesController( $config, $hub );
        $publish       = new PublishController( $config, $hub );
        $topics        = new TopicController( $config, $hub );
        \add_action( 'rest_api_init', array( $cases, 'register' ) );
        \add_action( 'rest_api_init', array( $myCases, 'register' ) );
        \add_action( 'rest_api_init', array( $participating, 'register' ) );
        \add_action( 'rest_api_init', array( $comments, 'register' ) );
        \add_action( 'rest_api_init', array( $votes, 'register' ) );
        \add_action( 'rest_api_init', array( $publish, 'register' ) );
        \add_action( 'rest_api_init', array( $topics, 'register' ) );

        if ( ! $config->enableChat ) {
            return;
        }

        $message = new MessageController( $config, $hub );
        $history = new HistoryController( $config, $hub );
        $stream  = new StreamController( $config, $hub, new Http\CurlStreamingTransport() );
        $session = new SessionController( $config );
        $draft   = new CaseDraftController( $config, $hub );
        \add_action( 'rest_api_init', array( $message, 'register' ) );
        \add_action( 'rest_api_init', array( $history, 'register' ) );
        \add_action( 'rest_api_init', array( $stream, 'register' ) );
        \add_action( 'rest_api_init', array( $session, 'register' ) );
        \add_action( 'rest_api_init', array( $draft, 'register' ) );
    }
}
