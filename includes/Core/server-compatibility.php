<?php
/**
 * Server Compatibility Checker for Certificate Generator
 * Checks server requirements and provides recommendations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access not allowed' );
}

class CertificateGenerator_CompatibilityChecker {

	private $requirements    = array();
	private $recommendations = array();
	private $hosting_fixes   = array();

	public function __construct() {
		$this->init_requirements();
		$this->init_hosting_fixes();
	}

	private function init_requirements() {
		$this->requirements = array(
			'php_version'        => array(
				'minimum'     => '7.4',
				'recommended' => '8.0',
				'critical'    => true,
			),
			'memory_limit'       => array(
				'minimum'     => 128 * 1024 * 1024, // 128MB
				'recommended' => 256 * 1024 * 1024, // 256MB
				'critical'    => true,
			),
			'max_execution_time' => array(
				'minimum'     => 60,
				'recommended' => 300,
				'critical'    => false,
			),
			'file_uploads'       => array(
				'required' => true,
				'critical' => true,
			),
			'max_file_size'      => array(
				'minimum'     => 32 * 1024 * 1024, // 32MB
				'recommended' => 64 * 1024 * 1024, // 64MB
				'critical'    => false,
			),
			'mysql_version'      => array(
				'minimum'     => '5.6',
				'recommended' => '8.0',
				'critical'    => true,
			),
		);
	}

	private function init_hosting_fixes() {
		$this->hosting_fixes = array(
			'hostinger'      => array(
				'memory_limit'   => array(
					'fix'  => 'Add ini_set("memory_limit", "256M"); to wp-config.php',
					'note' => 'Hostinger allows memory limit increases via ini_set',
				),
				'execution_time' => array(
					'fix'  => 'Add ini_set("max_execution_time", 300); to wp-config.php',
					'note' => 'LiteSpeed servers may have different limits',
				),
				'plugin_size'    => array(
					'fix'  => 'Use FTP to upload the plugin directly to wp-content/plugins/',
					'note' => 'WordPress upload limits may be lower than FTP limits',
				),
			),
			'shared_hosting' => array(
				'memory_limit'   => array(
					'fix'  => 'Contact your hosting provider to increase PHP memory limit',
					'note' => 'Many shared hosts limit memory to 128MB or less',
				),
				'execution_time' => array(
					'fix'  => 'Activate in "minimal mode" or contact hosting support',
					'note' => 'Shared hosting often has strict execution time limits',
				),
				'font_loading'   => array(
					'fix'  => 'Use "essential fonts only" mode to reduce memory usage',
					'note' => 'Large font collections may exceed shared hosting limits',
				),
			),
			'wordpress_com'  => array(
				'not_supported' => true,
				'reason'        => 'WordPress.com does not allow custom plugins on free plans',
			),
		);
	}

	/**
	 * Run complete compatibility check
	 */
	public function run_compatibility_check() {
		$results = array(
			'compatible'      => true,
			'warnings'        => array(),
			'errors'          => array(),
			'recommendations' => array(),
			'hosting_type'    => $this->detect_hosting_type(),
			'checks'          => array(),
		);

		// Run individual checks
		$results['checks']['php_version']       = $this->check_php_version();
		$results['checks']['memory_limit']      = $this->check_memory_limit();
		$results['checks']['execution_time']    = $this->check_execution_time();
		$results['checks']['file_uploads']      = $this->check_file_uploads();
		$results['checks']['max_file_size']     = $this->check_max_file_size();
		$results['checks']['mysql_version']     = $this->check_mysql_version();
		$results['checks']['disk_space']        = $this->check_disk_space();
		$results['checks']['wordpress_version'] = $this->check_wordpress_version();

		// Compile results
		foreach ( $results['checks'] as $check_name => $check_result ) {
			if ( ! $check_result['passed'] ) {
				if ( $check_result['critical'] ) {
					$results['errors'][]   = $check_result['message'];
					$results['compatible'] = false;
				} else {
					$results['warnings'][] = $check_result['message'];
				}
			}

			if ( ! empty( $check_result['recommendation'] ) ) {
				$results['recommendations'][] = $check_result['recommendation'];
			}
		}

		// Add hosting-specific recommendations
		$hosting_recommendations    = $this->get_hosting_recommendations( $results['hosting_type'] );
		$results['recommendations'] = array_merge( $results['recommendations'], $hosting_recommendations );

		return $results;
	}

	private function check_php_version() {
		$current     = PHP_VERSION;
		$minimum     = $this->requirements['php_version']['minimum'];
		$recommended = $this->requirements['php_version']['recommended'];

		$passed         = version_compare( $current, $minimum, '>=' );
		$is_recommended = version_compare( $current, $recommended, '>=' );

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['php_version']['critical'],
			'current'        => $current,
			'minimum'        => $minimum,
			'recommended'    => $recommended,
			'message'        => $passed ?
				( $is_recommended ? "PHP version $current is excellent" : "PHP version $current meets minimum requirements" ) :
				"PHP version $current is too old (minimum: $minimum required)",
			'recommendation' => ! $is_recommended ? "Consider upgrading to PHP $recommended or newer for better performance" : '',
		);
	}

	private function check_memory_limit() {
		$current_str = ini_get( 'memory_limit' );
		$current     = certificate_generator_convert_to_bytes( $current_str );
		$minimum     = $this->requirements['memory_limit']['minimum'];
		$recommended = $this->requirements['memory_limit']['recommended'];

		$passed         = ( $current == -1 ) || ( $current >= $minimum );
		$is_recommended = ( $current == -1 ) || ( $current >= $recommended );

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['memory_limit']['critical'],
			'current'        => $current_str,
			'current_bytes'  => $current,
			'minimum'        => certificate_generator_format_bytes( $minimum ),
			'recommended'    => certificate_generator_format_bytes( $recommended ),
			'message'        => $passed ?
				( $is_recommended ? "Memory limit $current_str is excellent" : "Memory limit $current_str meets minimum requirements" ) :
				"Memory limit $current_str is too low (minimum: " . certificate_generator_format_bytes( $minimum ) . ' required)',
			'recommendation' => ! $is_recommended ?
				'Increase memory limit to ' . certificate_generator_format_bytes( $recommended ) . ' for optimal performance' : '',
		);
	}

	private function check_execution_time() {
		$current     = ini_get( 'max_execution_time' );
		$minimum     = $this->requirements['max_execution_time']['minimum'];
		$recommended = $this->requirements['max_execution_time']['recommended'];

		$passed         = ( $current == 0 ) || ( $current >= $minimum );
		$is_recommended = ( $current == 0 ) || ( $current >= $recommended );

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['max_execution_time']['critical'],
			'current'        => $current,
			'minimum'        => $minimum,
			'recommended'    => $recommended,
			'message'        => $passed ?
				( $is_recommended ? 'Execution time limit is excellent' : 'Execution time limit meets minimum requirements' ) :
				"Execution time limit {$current}s is too low (minimum: {$minimum}s recommended)",
			'recommendation' => ! $is_recommended ?
				"Increase max_execution_time to {$recommended}s for large certificate generations" : '',
		);
	}

	private function check_file_uploads() {
		$enabled = ini_get( 'file_uploads' );
		$passed  = ! empty( $enabled );

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['file_uploads']['critical'],
			'current'        => $enabled ? 'Enabled' : 'Disabled',
			'message'        => $passed ?
				'File uploads are enabled' :
				'File uploads are disabled - required for CSV imports and image uploads',
			'recommendation' => ! $passed ? 'Enable file uploads in PHP configuration' : '',
		);
	}

	private function check_max_file_size() {
		$upload_max  = certificate_generator_convert_to_bytes( ini_get( 'upload_max_filesize' ) );
		$post_max    = certificate_generator_convert_to_bytes( ini_get( 'post_max_size' ) );
		$current     = min( $upload_max, $post_max );
		$minimum     = $this->requirements['max_file_size']['minimum'];
		$recommended = $this->requirements['max_file_size']['recommended'];

		$passed         = $current >= $minimum;
		$is_recommended = $current >= $recommended;

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['max_file_size']['critical'],
			'current'        => certificate_generator_format_bytes( $current ),
			'minimum'        => certificate_generator_format_bytes( $minimum ),
			'recommended'    => certificate_generator_format_bytes( $recommended ),
			'message'        => $passed ?
				( $is_recommended ? 'File upload size limit is excellent' : 'File upload size meets minimum requirements' ) :
				'File upload size ' . certificate_generator_format_bytes( $current ) . ' is too small (minimum: ' . certificate_generator_format_bytes( $minimum ) . ')',
			'recommendation' => ! $is_recommended ?
				'Increase upload limits to ' . certificate_generator_format_bytes( $recommended ) . ' for large file uploads' : '',
		);
	}

	private function check_mysql_version() {
		global $wpdb;
		$version     = $wpdb->get_var( 'SELECT VERSION()' );
		$minimum     = $this->requirements['mysql_version']['minimum'];
		$recommended = $this->requirements['mysql_version']['recommended'];

		$passed         = version_compare( $version, $minimum, '>=' );
		$is_recommended = version_compare( $version, $recommended, '>=' );

		return array(
			'passed'         => $passed,
			'critical'       => $this->requirements['mysql_version']['critical'],
			'current'        => $version,
			'minimum'        => $minimum,
			'recommended'    => $recommended,
			'message'        => $passed ?
				( $is_recommended ? "MySQL version $version is excellent" : "MySQL version $version meets minimum requirements" ) :
				"MySQL version $version is too old (minimum: $minimum required)",
			'recommendation' => ! $is_recommended ? "Consider upgrading to MySQL $recommended or newer" : '',
		);
	}

	private function check_disk_space() {
		$plugin_path     = CERTIFICATE_GENERATOR_PATH;
		$available_space = disk_free_space( $plugin_path );
		$required_space  = 300 * 1024 * 1024; // 300MB for full plugin + working space

		$passed = $available_space === false || $available_space >= $required_space;

		return array(
			'passed'         => $passed,
			'critical'       => false,
			'current'        => $available_space ? certificate_generator_format_bytes( $available_space ) : 'Unknown',
			'required'       => certificate_generator_format_bytes( $required_space ),
			'message'        => $passed ?
				'Sufficient disk space available' :
				'Low disk space: ' . certificate_generator_format_bytes( $available_space ) . ' available (need ' . certificate_generator_format_bytes( $required_space ) . ')',
			'recommendation' => ! $passed ? 'Free up disk space or use minimal installation mode' : '',
		);
	}

	private function check_wordpress_version() {
		global $wp_version;
		$minimum     = '5.0';
		$recommended = '6.0';

		$passed         = version_compare( $wp_version, $minimum, '>=' );
		$is_recommended = version_compare( $wp_version, $recommended, '>=' );

		return array(
			'passed'         => $passed,
			'critical'       => true,
			'current'        => $wp_version,
			'minimum'        => $minimum,
			'recommended'    => $recommended,
			'message'        => $passed ?
				( $is_recommended ? "WordPress version $wp_version is excellent" : "WordPress version $wp_version meets minimum requirements" ) :
				"WordPress version $wp_version is too old (minimum: $minimum required)",
			'recommendation' => ! $is_recommended ? "Consider upgrading to WordPress $recommended or newer" : '',
		);
	}

	private function detect_hosting_type() {
		$host            = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		if ( strpos( $host, 'hostinger' ) !== false || strpos( $server_software, 'LiteSpeed' ) !== false ) {
			return 'hostinger';
		}

		if ( strpos( $host, 'wordpress.com' ) !== false ) {
			return 'wordpress_com';
		}

		if ( strpos( $host, 'localhost' ) !== false || strpos( $host, '.local' ) !== false ) {
			return 'local';
		}

		// Check for common shared hosting indicators
		$shared_indicators = array( 'shared', 'cpanel', 'plesk', 'bluehost', 'godaddy', 'namecheap' );
		foreach ( $shared_indicators as $indicator ) {
			if ( strpos( strtolower( $host . $server_software ), $indicator ) !== false ) {
				return 'shared_hosting';
			}
		}

		return 'unknown';
	}

	private function get_hosting_recommendations( $hosting_type ) {
		$recommendations = array();

		if ( isset( $this->hosting_fixes[ $hosting_type ] ) ) {
			$fixes = $this->hosting_fixes[ $hosting_type ];

			if ( isset( $fixes['not_supported'] ) && $fixes['not_supported'] ) {
				$recommendations[] = '❌ This hosting platform is not supported: ' . $fixes['reason'];
				return $recommendations;
			}

			foreach ( $fixes as $issue => $fix_info ) {
				$recommendations[] = "🔧 {$issue}: {$fix_info['fix']} ({$fix_info['note']})";
			}
		}

		return $recommendations;
	}



	/**
	 * Generate installation recommendations based on compatibility check
	 */
	public function get_installation_mode_recommendation( $compatibility_results ) {
		if ( ! $compatibility_results['compatible'] ) {
			return array(
				'mode'    => 'not_compatible',
				'message' => 'Plugin cannot be installed due to critical compatibility issues',
				'action'  => 'Please resolve the following critical issues before installation: ' . implode( ', ', $compatibility_results['errors'] ),
			);
		}

		$memory_check = $compatibility_results['checks']['memory_limit'];
		$time_check   = $compatibility_results['checks']['execution_time'];
		$disk_check   = $compatibility_results['checks']['disk_space'];

		// Determine best installation mode
		if ( $memory_check['current_bytes'] < ( 128 * 1024 * 1024 ) ||
			! $time_check['passed'] ||
			! $disk_check['passed'] ) {

			return array(
				'mode'    => 'minimal',
				'message' => 'Minimal installation recommended due to server limitations',
				'action'  => 'Install with essential fonts only and basic features to avoid resource limits',
			);
		} elseif ( $memory_check['current_bytes'] < ( 256 * 1024 * 1024 ) ||
					$time_check['current'] < 300 ) {

			return array(
				'mode'    => 'standard',
				'message' => 'Standard installation recommended',
				'action'  => 'Install with essential fonts and core features',
			);
		} else {
			return array(
				'mode'    => 'full',
				'message' => 'Full installation supported',
				'action'  => 'Install with all features and complete font collection',
			);
		}
	}
}

/**
 * Quick compatibility check function
 */
function certificate_generator_quick_compatibility_check() {
	$checker = new CertificateGenerator_CompatibilityChecker();
	return $checker->run_compatibility_check();
}

/**
 * Get installation recommendation
 */
function certificate_generator_get_installation_recommendation() {
	$checker       = new CertificateGenerator_CompatibilityChecker();
	$compatibility = $checker->run_compatibility_check();
	return $checker->get_installation_mode_recommendation( $compatibility );
}
