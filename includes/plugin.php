<?php
namespace GemCloudStorage;

use GemCloudStorage\Admin\Assets;
use GemCloudStorage\Admin\Menu;
use GemCloudStorage\Admin\Notices;
use GemCloudStorage\Api\RestController;
use GemCloudStorage\Backups\Backups;
use GemCloudStorage\Integrations\Integrations;
use GemCloudStorage\Jobs\Queue;
use GemCloudStorage\Media\MediaHooks;
use GemCloudStorage\Media\PrivateDelivery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots every module once WordPress and Action Scheduler are loaded.
 */
final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	public function boot(): void {
		Installer::maybe_upgrade();

		// Context labels are translated, so they wait for init.
		add_action( 'init', [ $this, 'register_contexts' ], 1 );

		Queue::init();
		Integrations::init();
		Backups::init();
		MediaHooks::init();
		PrivateDelivery::init();
		RestController::init();

		// A context switched back on, or a bucket just connected.
		add_action( 'gemcloudstorage/settings_saved', [ Integrations::class, 'schedule_reconcile' ] );

		if ( is_admin() ) {
			( new Menu() )->register();
			( new Assets() )->register();
			Notices::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'gemcloudstorage', Cli\Command::class );
			\WP_CLI::add_command( 'gemcloudstorage backup', Cli\BackupCommand::class );
		}
	}

	public function register_contexts(): void {
		Contexts::register_defaults();

		/**
		 * Fires once the storage API is ready (init, priority 1). Products
		 * register their file contexts here with Manager::register_context().
		 */
		do_action( 'gemcloudstorage/loaded', GEMCLOUDSTORAGE_VERSION );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'gemcloudstorage', false, dirname( plugin_basename( GEMCLOUDSTORAGE_PLUGIN_FILE ) ) . '/languages' );
	}
}
