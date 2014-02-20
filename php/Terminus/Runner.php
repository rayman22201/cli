<?php

namespace Terminus;

use Terminus;
use Terminus\Utils;
use Terminus\Dispatcher;

class Runner {

	private $global_config_path, $project_config_path;

	private $config, $extra_config;

	private $arguments, $assoc_args;

	private $_early_invoke = array();

	public function __get( $key ) {
		if ( '_' === $key[0] )
			return null;

		return $this->$key;
	}

	public function register_early_invoke( $when, $command ) {
		$this->_early_invoke[ $when ][] = array_slice( Dispatcher\get_path( $command ), 1 );
	}

	private function do_early_invoke( $when ) {
		if ( !isset( $this->_early_invoke[ $when ] ) )
			return;

		foreach ( $this->_early_invoke[ $when ] as $path ) {
			if ( $this->cmd_starts_with( $path ) ) {
				$this->_run_command();
				exit;
			}
		}
	}

	private static function get_global_config_path() {
		$config_path = getenv( 'TERMINUS_CONFIG_PATH' );
		if ( isset( $runtime_config['config'] ) ) {
			$config_path = $runtime_config['config'];
		}

		if ( !$config_path ) {
			$config_path = getenv( 'HOME' ) . '/.terminus/config.yml';
		}

		if ( !is_readable( $config_path ) )
			return false;

		return $config_path;
	}

	private static function get_project_config_path() {
		$config_files = array(
			'terminus.local.yml',
			'terminus.yml'
		);

		// Stop looking upward when we find we have emerged from a subdirectory
		// install into a parent install
		return Utils\find_file_upward( $config_files, getcwd(), function ( $dir ) {
			static $wp_load_count = 0;
			$wp_load_path = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
			if ( file_exists( $wp_load_path ) ) {
				$wp_load_count += 1;
			}
			return $wp_load_count > 1;
		} );
	}

	/**
	 * Attempts to find the path to the WP install inside index.php
	 */
	private static function extract_subdir_path( $index_path ) {
		$index_code = file_get_contents( $index_path );

		if ( !preg_match( '|^\s*require\s*\(?\s*(.+?)/wp-blog-header\.php([\'"])|m', $index_code, $matches ) ) {
			return false;
		}

		$wp_path_src = $matches[1] . $matches[2];
		$wp_path_src = Utils\replace_path_consts( $wp_path_src, $index_path );
		$wp_path = eval( "return $wp_path_src;" );

		if ( !Utils\is_path_absolute( $wp_path ) ) {
			$wp_path = dirname( $index_path ) . "/$wp_path";
		}

		return $wp_path;
	}

	/**
	 * Find the directory that contains the WordPress files. Defaults to the current working dir.
	 *
	 * @return string An absolute path
	 */
	private function find_wp_root() {
		if ( !empty( $this->config['path'] ) ) {
			$path = $this->config['path'];
			if ( !Utils\is_path_absolute( $path ) )
				$path = getcwd() . '/' . $path;

			return $path;
		}

		if ( $this->cmd_starts_with( array( 'core', 'download' ) ) ) {
			return getcwd();
		}

		$dir = getcwd();

		while ( is_readable( $dir ) ) {
			if ( file_exists( "$dir/wp-load.php" ) ) {
				return $dir;
			}

			if ( file_exists( "$dir/index.php" ) ) {
				if ( $path = self::extract_subdir_path( "$dir/index.php" ) )
					return $path;
			}

			$parent_dir = dirname( $dir );
			if ( empty($parent_dir) || $parent_dir === $dir ) {
				break;
			}
			$dir = $parent_dir;
		}
	}

	private static function set_wp_root( $path ) {
		define( 'ABSPATH', rtrim( $path, '/' ) . '/' );

		$_SERVER['DOCUMENT_ROOT'] = realpath( $path );
	}

	private static function set_user( $assoc_args ) {
		if ( !isset( $assoc_args['user'] ) )
			return;

		$fetcher = new \Terminus\Fetchers\User;
		$user = $fetcher->get_check( $assoc_args['user'] );
		wp_set_current_user( $user->ID );

	}

	private static function guess_url( $assoc_args ) {
		if ( isset( $assoc_args['blog'] ) ) {
			$assoc_args['url'] = $assoc_args['blog'];
		}

		if ( isset( $assoc_args['url'] ) ) {
			$url = $assoc_args['url'];
			if ( true === $url ) {
				Terminus::warning( 'The --url parameter expects a value.' );
			}
		} elseif ( $wp_config_path = Utils\locate_wp_config() ) {
			// Try to find the blog parameter in the wp-config file
			$wp_config_file = file_get_contents( $wp_config_path );
			$hit = array();

			$re_define = "#.*define\s*\(\s*(['|\"]{1})(.+)(['|\"]{1})\s*,\s*(['|\"]{1})(.+)(['|\"]{1})\s*\)\s*;#iU";

			if ( preg_match_all( $re_define, $wp_config_file, $matches ) ) {
				foreach ( $matches[2] as $def_key => $def_name ) {
					if ( 'DOMAIN_CURRENT_SITE' == $def_name )
						$hit['domain'] = $matches[5][$def_key];
					if ( 'PATH_CURRENT_SITE' == $def_name )
						$hit['path'] = $matches[5][$def_key];
				}
			}

			if ( !empty( $hit ) && isset( $hit['domain'] ) ) {
				$url = $hit['domain'];
				if ( isset( $hit['path'] ) )
					$url .= $hit['path'];
			}
		}

		if ( isset( $url ) ) {
			return $url;
		}

		return false;
	}

	private function cmd_starts_with( $prefix ) {
		return $prefix == array_slice( $this->arguments, 0, count( $prefix ) );
	}

	public function find_command_to_run( $args ) {
		$command = \Terminus::get_root_command();

		$cmd_path = array();

		$disabled_commands = $this->config['disabled_commands'];

		while ( !empty( $args ) && $command->can_have_subcommands() ) {
			$cmd_path[] = $args[0];
			$full_name = implode( ' ', $cmd_path );

			$subcommand = $command->find_subcommand( $args );

			if ( !$subcommand ) {
				return sprintf(
					"'%s' is not a registered command. See 'terminus help'.",
					$full_name
				);
			}

			if ( in_array( $full_name, $disabled_commands ) ) {
				return sprintf(
					"The '%s' command has been disabled from the config file.",
					$full_name
				);
			}

			$command = $subcommand;
		}

		return array( $command, $args, $cmd_path );
	}

	public function run_command( $args, $assoc_args = array() ) {
		$r = $this->find_command_to_run( $args );
		if ( is_string( $r ) ) {
			Terminus::error( $r );
		}

		list( $command, $final_args, $cmd_path ) = $r;

		$name = implode( ' ', $cmd_path );

		if ( isset( $this->extra_config[ $name ] ) ) {
			$extra_args = $this->extra_config[ $name ];
		} else {
			$extra_args = array();
		}

		try {
			$command->invoke( $final_args, $assoc_args, $extra_args );
		} catch ( Terminus\Iterators\Exception $e ) {
			Terminus::error( $e->getMessage() );
		}
	}

	private function _run_command() {
		$this->run_command( $this->arguments, $this->assoc_args );
	}

	// Transparently convert old syntaxes
	private static function back_compat_conversions( $args, $assoc_args ) {
		$top_level_aliases = array(
			'sql' => 'db',
			'blog' => 'site'
		);
		if ( count( $args ) > 0 ) {
			foreach ( $top_level_aliases as $old => $new ) {
				if ( $old == $args[0] ) {
					$args[0] = $new;
					break;
				}
			}
		}

		// *-meta  ->  * meta
		if ( !empty( $args ) && preg_match( '/(post|comment|user|network)-meta/', $args[0], $matches ) ) {
			array_shift( $args );
			array_unshift( $args, 'meta' );
			array_unshift( $args, $matches[1] );
		}

		// core (multsite-)install --admin_name=  ->  --admin_user=
		if ( count( $args ) > 0 && 'core' == $args[0] && isset( $assoc_args['admin_name'] ) ) {
			$assoc_args['admin_user'] = $assoc_args['admin_name'];
			unset( $assoc_args['admin_name'] );
		}

		// site --site_id=  ->  site --network_id=
		if ( count( $args ) > 0 && 'site' == $args[0] && isset( $assoc_args['site_id'] ) ) {
			$assoc_args['network_id'] = $assoc_args['site_id'];
			unset( $assoc_args['site_id'] );
		}

		// {plugin|theme} update-all  ->  {plugin|theme} update --all
		if ( count( $args ) > 1 && in_array( $args[0], array( 'plugin', 'theme' ) )
			&& $args[1] == 'update-all'
		) {
			$args[1] = 'update';
			$assoc_args['all'] = true;
		}

		// plugin scaffold  ->  scaffold plugin
		if ( array( 'plugin', 'scaffold' ) == array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( $args[1], $args[0] );
		}

		// foo --help  ->  help foo
		if ( isset( $assoc_args['help'] ) ) {
			array_unshift( $args, 'help' );
			unset( $assoc_args['help'] );
		}

		// {post|user} list --ids  ->  {post|user} list --format=ids
		if ( count( $args ) > 1 && in_array( $args[0], array( 'post', 'user' ) )
			&& $args[1] == 'list'
			&& isset( $assoc_args['ids'] )
		) {
			$assoc_args['format'] = 'ids';
			unset( $assoc_args['ids'] );
		}

		// --json  ->  --format=json
		if ( isset( $assoc_args['json'] ) ) {
			$assoc_args['format'] = 'json';
			unset( $assoc_args['json'] );
		}

		// --{version|info}  ->  cli {version|info}
		if ( empty( $args ) ) {
			$special_flags = array( 'version', 'info' );
			foreach ( $special_flags as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$args = array( 'cli', $key );
					unset( $assoc_args[ $key ] );
					break;
				}
			}
		}

		return array( $args, $assoc_args );
	}

	public function in_color() {
		return $this->colorize;
	}

	private function init_colorization() {
		if ( 'auto' === $this->config['color'] ) {
			$this->colorize = !\cli\Shell::isPiped();
		} else {
			$this->colorize = $this->config['color'];
		}
	}

	private function init_logger() {
		if ( $this->config['quiet'] )
			$logger = new \Terminus\Loggers\Quiet;
		else
			$logger = new \Terminus\Loggers\Regular( $this->in_color() );

		Terminus::set_logger( $logger );
	}

	private function wp_exists() {
		return is_readable( ABSPATH . 'wp-includes/version.php' );
	}

	private function check_wp_version() {
		if ( !$this->wp_exists() ) {
			Terminus::error(
				"This does not seem to be a WordPress install.\n" .
				"Pass --path=`path/to/wordpress` or run `wp core download`." );
		}

		include ABSPATH . 'wp-includes/version.php';

		$minimum_version = '3.5.2';

		// @codingStandardsIgnoreStart
		if ( version_compare( $wp_version, $minimum_version, '<' ) ) {
			Terminus::error(
				"WP-CLI needs WordPress $minimum_version or later to work properly. " .
				"The version currently installed is $wp_version.\n" .
				"Try running `wp core download --force`."
			);
		}
		// @codingStandardsIgnoreEnd
	}

	private function init_config() {
		$configurator = \Terminus::get_configurator();

		// File config
		{
			$this->global_config_path = self::get_global_config_path();
			$this->project_config_path = self::get_project_config_path();

			$configurator->merge_yml( $this->global_config_path );
			$configurator->merge_yml( $this->project_config_path );
		}

		// Runtime config and args
		{
			list( $args, $assoc_args, $runtime_config ) = $configurator->parse_args(
				array_slice( $GLOBALS['argv'], 1 ) );

			list( $this->arguments, $this->assoc_args ) = self::back_compat_conversions(
				$args, $assoc_args );

			$configurator->merge_array( $runtime_config );
		}

		list( $this->config, $this->extra_config ) = $configurator->to_array();
	}

	private function check_root() {
		if ( $this->config['allow-root'] )
			return; # they're aware of the risks!
		if ( !function_exists( 'posix_geteuid') )
			return; # posix functions not available
		if ( posix_geteuid() !== 0 )
			return; # not root

		Terminus::error(
			"YIKES! It looks like you're running this as root. You probably meant to " .
			"run this as the user that your WordPress install exists under.\n" .
			"\n" .
			"If you REALLY mean to run this as root, we won't stop you, but just " .
			"bear in mind that any code on this site will then have full control of " .
			"your server, making it quite DANGEROUS.\n" .
			"\n" .
			"If you'd like to continue as root, please run this again, adding this " .
			"flag:  --allow-root\n" .
			"\n" .
			"If you'd like to run it as the user that this site is under, you can " .
			"run the following to become the respective user:\n" .
			"\n" .
			"    sudo -u USER -i -- wp ...\n" .
			"\n"
		);
	}

	public function before_wp_load() {
		$this->init_config();
		$this->init_colorization();
		$this->init_logger();

		$this->check_root();

		if ( empty( $this->arguments ) )
			$this->arguments[] = 'help';

		// Load bundled commands early, so that they're forced to use the same
		// APIs as non-bundled commands.
		Utils\load_command( $this->arguments[0] );

		if ( isset( $this->config['require'] ) ) {
			foreach ( $this->config['require'] as $path ) {
				Utils\load_file( $path );
			}
		}

		// Show synopsis if it's a composite command.
		$r = $this->find_command_to_run( $this->arguments );
		if ( is_array( $r ) ) {
			list( $command ) = $r;

			if ( $command->can_have_subcommands() ) {
				$command->show_usage();
				exit;
			}
		}

		// Handle --path parameter
		self::set_wp_root( $this->find_wp_root() );

		// First try at showing man page
		if ( 'help' === $this->arguments[0] && ( isset( $this->arguments[1] ) || !$this->wp_exists() ) ) {
			$this->_run_command();
		}

		// Handle --url parameter
		$url = self::guess_url( $this->config );
		if ( $url )
			\Terminus::set_url( $url );

		$this->do_early_invoke( 'before_wp_load' );

		if ( $this->cmd_starts_with( array( 'core', 'config' ) ) ) {
			$this->_run_command();
			exit;
		}

		if ( $this->cmd_starts_with( array( 'core', 'is-installed' ) ) ) {
			define( 'WP_INSTALLING', true );
		}

		if (
			count( $this->arguments ) >= 2 &&
			$this->arguments[0] == 'core' &&
			in_array( $this->arguments[1], array( 'install', 'multisite-install' ) )
		) {
			define( 'WP_INSTALLING', true );

			// We really need a URL here
			if ( !isset( $_SERVER['HTTP_HOST'] ) ) {
				$url = 'http://example.com';
				\Terminus::set_url( $url );
			}

			if ( 'multisite-install' == $this->arguments[1] ) {
				// need to fake some globals to skip the checks in wp-includes/ms-settings.php
				$url_parts = Utils\parse_url( $url );
				self::fake_current_site_blog( $url_parts );

				if ( !defined( 'COOKIEHASH' ) ) {
					define( 'COOKIEHASH', md5( $url_parts['host'] ) );
				}
			}
		}

		if ( $this->cmd_starts_with( array( 'import') ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
			define( 'WP_IMPORTING', true );
		}

		if ( $this->cmd_starts_with( array( 'plugin' ) ) ) {
			$GLOBALS['pagenow'] = 'plugins.php';
		}


		# Run the stinkin command!
		$this->_run_command();

	}

}

