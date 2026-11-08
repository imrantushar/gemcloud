<?php
namespace GemCloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps GemCloud\Foo\BarBaz to includes/foo/bar-baz.php, the same
 * convention Zaplane uses.
 */
class Autoload {

	private static ?self $instance = null;

	/** @var array<string, string[]> */
	private array $autoload_directories = [];

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function add_namespace_directory( string $namespace, string $directory ): void {
		$ns  = rtrim( $namespace, '\\' );
		$dir = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		if ( ! isset( $this->autoload_directories[ $ns ] ) ) {
			$this->autoload_directories[ $ns ] = [];
		}

		$this->autoload_directories[ $ns ][] = $dir;
	}

	public function register(): void {
		spl_autoload_register( [ $this, 'autoload' ] );
	}

	public function autoload( string $class ): void {
		foreach ( $this->autoload_directories as $namespace => $directories ) {
			if ( 0 !== strpos( $class, $namespace . '\\' ) ) {
				continue;
			}

			$relative_class = substr( $class, strlen( $namespace ) + 1 );
			$relative_path  = strtolower(
				preg_replace(
					[ '/([a-z0-9])([A-Z])/', '/_/', '/\\\\/' ],
					[ '$1-$2', '-', DIRECTORY_SEPARATOR ],
					$relative_class
				)
			) . '.php';

			foreach ( $directories as $directory ) {
				$file = $directory . $relative_path;
				if ( is_readable( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}
}
