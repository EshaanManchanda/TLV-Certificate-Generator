<?php
/**
 * Certificate Background Processor
 *
 * Handles asynchronous processing of certificate generation and ZIP creation
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CertificateGenerator_Background_Processor class
 */
class CertificateGenerator_Background_Processor {
	/**
	 * Action hook for background processing
	 */
	const CRON_HOOK = 'certificate_background_processing';

	/**
	 * Batch size for processing certificates
	 */
	const BATCH_SIZE = 10;

	/**
	 * Memory limit for batch processing (MB)
	 */
	const MEMORY_LIMIT = 64;

	/**
	 * Maximum execution time per batch (seconds)
	 */
	const MAX_EXECUTION_TIME = 30;

	/**
	 * Initialize the background processor
	 */
	public function __construct() {
		// Register the cron hook
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ), 10, 2 );

		// Register AJAX handlers (admin-only — response contains zip_url to bulk certificate archive)
		add_action( 'wp_ajax_certificate_generator_check_certificate_progress', array( $this, 'check_progress' ) );
	}

	/**
	 * Schedule a background job to process certificates
	 *
	 * @param array  $post_ids Array of post IDs to process
	 * @param string $email_hash Hash of the user's email for identification
	 * @return string Job ID
	 */
	public function schedule_certificate_processing( $post_ids, $email_hash ) {
		// Generate a unique job ID
		$job_id = 'cert_job_' . $email_hash . '_' . time();

		// Split post IDs into batches
		$batches       = array_chunk( $post_ids, self::BATCH_SIZE );
		$total_batches = count( $batches );

		// Initialize job data
		$job_data = array(
			'job_id'            => $job_id,
			'email_hash'        => $email_hash,
			'total_posts'       => count( $post_ids ),
			'processed_posts'   => 0,
			'total_batches'     => $total_batches,
			'processed_batches' => 0,
			'status'            => 'pending',
			'start_time'        => time(),
			'certificates'      => array(),
			'errors'            => array(),
			'zip_path'          => '',
			'zip_url'           => '',
		);

		// Store job data
		update_option( 'certificate_generator_job_' . $job_id, $job_data );

		// Schedule the first batch immediately
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $job_id, $batches[0] ) );

		// Schedule remaining batches with a slight delay to prevent server overload
		for ( $i = 1; $i < $total_batches; $i++ ) {
			wp_schedule_single_event( time() + ( 60 * $i ), self::CRON_HOOK, array( $job_id, $batches[ $i ] ) );
		}

		return $job_id;
	}

	/**
	 * Process a batch of certificates
	 *
	 * @param string $job_id Job ID
	 * @param array  $batch Batch of post IDs to process
	 */
	public function process_batch( $job_id, $batch ) {
		// Get job data
		$job_data = get_option( 'certificate_generator_job_' . $job_id );

		if ( ! $job_data ) {
			certificate_generator_debug_log( 'Certificate job not found: ' . $job_id );
			return;
		}

		// Update job status to processing if it's the first batch
		if ( $job_data['status'] === 'pending' ) {
			$job_data['status'] = 'processing';
			update_option( 'certificate_generator_job_' . $job_id, $job_data );

			// Invalidate HTML cache but keep certificate data cache
			$this->invalidate_html_cache( $job_data['email_hash'] );
		}

		// Process each post in the batch
		$processed_in_batch = 0;

		foreach ( $batch as $post_id ) {
			// Resolve fields dynamically per student (extra fields may vary by cert type)
			$cert_type_bg = get_post_meta( $post_id, 'certificate_type', true );
			$fields       = class_exists( 'CertificateGenerator_Field_Schema' )
				? CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type_bg )
				: array( 'student_name', 'school_name', 'issue_date' );
			// Check if certificate already exists
			$existing_file_path = get_post_meta( $post_id, 'certificate_file_path', true );
			$existing_file_url  = get_post_meta( $post_id, 'certificate_file_url', true );

			if ( $existing_file_path && file_exists( $existing_file_path ) ) {
				// Certificate already exists, use it
				$job_data['certificates'][ $post_id ] = array(
					'url'      => $existing_file_url,
					'path'     => $existing_file_path,
					'filename' => basename( $existing_file_path ),
				);
			} else {
				// Generate certificate
				$file_url = $this->generate_certificate( $post_id, $fields );

				if ( $file_url ) {
					// Get the actual file path from post meta or convert URL to path
					$file_path = get_post_meta( $post_id, 'certificate_file_path', true );
					if ( ! $file_path ) {
						// If file path is not stored in meta, try to derive it from URL
						$upload_dir = wp_upload_dir();
						$file_path  = str_replace( $upload_dir['url'], $upload_dir['path'], $file_url );

						// Store the file path for future use
						update_post_meta( $post_id, 'certificate_file_path', $file_path );
						update_post_meta( $post_id, 'certificate_file_url', $file_url );
					}

					// Only add to certificates array if file exists
					if ( file_exists( $file_path ) ) {
						$job_data['certificates'][ $post_id ] = array(
							'url'      => $file_url,
							'path'     => $file_path,
							'filename' => basename( $file_path ),
						);
					} else {
						$job_data['errors'][] = "Certificate file does not exist at path: $file_path";
						certificate_generator_debug_log( "Certificate file does not exist at path: $file_path" );
					}
				} else {
					$job_data['errors'][] = "Failed to generate certificate for post ID: $post_id";
					certificate_generator_debug_log( "Failed to generate certificate for post ID: $post_id" );
				}
			}

			++$processed_in_batch;
		}

		// Update job progress
		$job_data['processed_posts'] += $processed_in_batch;
		++$job_data['processed_batches'];

		// Check if all batches are processed
		if ( $job_data['processed_batches'] >= $job_data['total_batches'] ) {
			// All batches processed, create ZIP file
			$this->create_zip_file( $job_data );
			$job_data['status'] = 'completed';
		}

		// Update job data
		update_option( 'certificate_generator_job_' . $job_id, $job_data );
	}

	/**
	 * Generate a certificate PDF
	 *
	 * @param int   $post_id Post ID
	 * @param array $fields Fields to include in the certificate
	 * @return string|bool URL of the generated certificate or false on failure
	 */
	private function generate_certificate( $post_id, $fields ) {
		// This is a wrapper for the existing certificate_generator_generate_certificate_pdf function
		if ( function_exists( 'certificate_generator_generate_certificate_pdf' ) ) {
			return certificate_generator_generate_certificate_pdf( $post_id, $fields );
		}
		return false;
	}

	/**
	 * Create a ZIP file containing all certificates
	 *
	 * @param array $job_data Job data
	 * @return bool Success or failure
	 */
	private function create_zip_file( &$job_data ) {
		// Check if ZipArchive class exists
		if ( ! class_exists( 'ZipArchive' ) ) {
			$job_data['errors'][] = 'ZipArchive extension is not installed on the server.';
			certificate_generator_debug_log( 'ZipArchive extension is not installed on the server.' );
			return false;
		}

		// Create a new ZIP file
		$upload_dir   = wp_upload_dir();
		$timestamp    = time();
		$zip_filename = function_exists( 'certificate_generator_certificate_zip_filename' )
			? certificate_generator_certificate_zip_filename( $job_data['email'] ?? $job_data['email_hash'], $timestamp )
			: 'certificates_' . $job_data['email_hash'] . '_' . $timestamp . '.zip';
		$zip_path     = certificate_generator_certificates_dir() . '/' . $zip_filename;
		$zip_url      = ( function_exists( 'certificate_generator_certificates_url' ) ? certificate_generator_certificates_url() : $upload_dir['url'] ) . '/' . $zip_filename;

		// Clean up old ZIP files for this email
		$existing_zip_meta_key = 'certificates_zip_' . $job_data['email_hash'];
		$existing_zip_info     = get_option( $existing_zip_meta_key );

		if ( $existing_zip_info && isset( $existing_zip_info['path'] ) && file_exists( $existing_zip_info['path'] ) ) {
			wp_delete_file( $existing_zip_info['path'] );
			certificate_generator_debug_log( "Deleted old ZIP file: {$existing_zip_info['path']}" );
		}

		// Build SQL-first name + cg_id lookup for each post_id so filenames are correct
		// even for records that were never in CPT post meta (new SQL-only entries).
		global $wpdb;
		$cg_table  = $wpdb->prefix . 'certificate_generator';
		$post_ids  = array_keys( $job_data['certificates'] );
		$cert_meta = array();
		foreach ( $post_ids as $post_id ) {
			$sql_row = function_exists( 'certificate_generator_get_sql_row_for_post_cached' )
				? certificate_generator_get_sql_row_for_post_cached( (int) $post_id, 'students' )
				: null;
			if ( ! $sql_row || empty( $sql_row['student_name'] ) ) {
				$sql_row = function_exists( 'certificate_generator_get_sql_row_for_post_cached' )
					? certificate_generator_get_sql_row_for_post_cached( (int) $post_id, 'teachers' )
					: null;
			}
			$name  = ( $sql_row && ! empty( $sql_row['student_name'] ) ) ? $sql_row['student_name']
					: ( ( $sql_row && ! empty( $sql_row['teacher_name'] ) ) ? $sql_row['teacher_name']
					: (string) get_post_meta( $post_id, 'student_name', true ) );
			$type  = ( $sql_row && ! empty( $sql_row['certificate_type'] ) ) ? $sql_row['certificate_type']
					: (string) get_post_meta( $post_id, 'certificate_type', true );
			$email = ( $sql_row && ! empty( $sql_row['email'] ) ) ? $sql_row['email']
					: (string) get_post_meta( $post_id, 'email', true );
			$cg_id = 0;
			if ( $email ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$cg_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $cg_table WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) );
			}
			$cert_meta[ $post_id ] = array(
				'name'  => $name,
				'type'  => $type,
				'cg_id' => $cg_id ?: (int) $post_id,
			);
		}

		// Build normalized file list for the canonical ZIP builder.
		$certificates_data  = array();
		$certificate_chunks = array_chunk( $job_data['certificates'], self::BATCH_SIZE, true );
		foreach ( $certificate_chunks as $chunk ) {
			foreach ( $chunk as $cert_id => $certificate ) {
				if ( ! file_exists( $certificate['path'] ) ) {
					$job_data['errors'][] = "File not found: {$certificate['path']}";
					continue;
				}
				$meta                = $cert_meta[ $cert_id ] ?? array(
					'name'  => (string) $cert_id,
					'type'  => '',
					'cg_id' => (int) $cert_id,
				);
				$clean_name          = function_exists( 'certificate_generator_certificate_pdf_filename' )
					? certificate_generator_certificate_pdf_filename( $meta['name'], $meta['type'], $meta['cg_id'] )
					: sanitize_file_name( $meta['name'] . '_' . $meta['type'] . '_' . $meta['cg_id'] . '.pdf' );
				$certificates_data[] = array(
					'path'     => $certificate['path'],
					'filename' => $clean_name,
				);
			}
		}

		if ( ! empty( $certificates_data ) && function_exists( 'certificate_generator_create_zip_for_email' ) ) {
			$zip_result = certificate_generator_create_zip_for_email(
				$certificates_data,
				$job_data['email'] ?? $job_data['email_hash']
			);
			if ( $zip_result && $zip_result['certificate_count'] > 0 ) {
				$job_data['zip_path']       = $zip_result['zip_path'];
				$job_data['zip_url']        = $zip_result['zip_url'];
				$job_data['zip_file_count'] = $zip_result['certificate_count'];
				update_option(
					$existing_zip_meta_key,
					array(
						'path'      => $zip_result['zip_path'],
						'url'       => $zip_result['zip_url'],
						'timestamp' => $timestamp,
						'count'     => $zip_result['certificate_count'],
					)
				);
				$this->invalidate_html_cache( $job_data['email_hash'] );
				return true;
			}
			$job_data['errors'][] = 'No valid certificates could be added to ZIP.';
		} else {
			$job_data['errors'][] = 'Failed to create ZIP: no files or ZIP builder unavailable.';
			certificate_generator_debug_log( 'Certificate Generator: ZIP builder unavailable for job ' . ( $job_data['email_hash'] ?? '' ) );
		}
		return false;
	}

	/**
	 * Check the progress of a certificate generation job
	 */
	public function check_progress() {
		// Verify nonce
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'certificate_generation_progress_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		// Get job ID
		if ( ! isset( $_POST['job_id'] ) ) {
			wp_send_json_error( 'Missing job ID' );
		}

		$job_id   = sanitize_text_field( wp_unslash( $_POST['job_id'] ) );
		$job_data = get_option( 'certificate_generator_job_' . $job_id );

		if ( ! $job_data ) {
			wp_send_json_error( 'Job not found' );
		}

		// Calculate progress percentage
		$progress = 0;
		if ( $job_data['total_posts'] > 0 ) {
			$progress = round( ( $job_data['processed_posts'] / $job_data['total_posts'] ) * 100 );
		}

		// Prepare response
		$response = array(
			'status'         => $job_data['status'],
			'progress'       => $progress,
			'processed'      => $job_data['processed_posts'],
			'total'          => $job_data['total_posts'],
			'zip_url'        => isset( $job_data['zip_url'] ) ? $job_data['zip_url'] : '',
			'zip_file_count' => isset( $job_data['zip_file_count'] ) ? $job_data['zip_file_count'] : 0,
			'errors'         => $job_data['errors'],
		);

		wp_send_json_success( $response );
	}

	/**
	 * Get job data by email hash
	 *
	 * @param string $email_hash Email hash
	 * @return array|bool Job data or false if not found
	 */
	public function get_job_by_email_hash( $email_hash ) {
		global $wpdb;

		// Get all options that might be job data for this email hash
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1",
				'certificate_generator_job_cert_job_' . $email_hash . '_%'
			)
		);

		if ( empty( $option_names ) ) {
			return false;
		}

		// Get the most recent job (should be the first one due to ORDER BY)
		$job_data = get_option( $option_names[0] );

		return $job_data;
	}

	/**
	 * Invalidate HTML cache but keep certificate data cache
	 *
	 * @param string $email_hash Email hash
	 */
	private function invalidate_html_cache( $email_hash ) {
		// Get all cache keys for this email
		$email_cache_keys = get_option( 'certificate_generator_cache_keys_' . $email_hash, array() );

		if ( ! empty( $email_cache_keys ) ) {
			foreach ( $email_cache_keys as $key => $timestamp ) {
				// Only delete HTML output cache, keep certificate data cache
				if ( strpos( $key, 'certificate_data_' ) === false ) {
					delete_transient( $key );
					certificate_generator_debug_log( "Invalidated cache key: {$key}" );
				}
			}
		}
	}

	/**
	 * Invalidate all caches for an email hash
	 *
	 * @param string $email_hash Email hash
	 */
	public function invalidate_all_caches( $email_hash ) {
		// Get all cache keys for this email
		$email_cache_keys = get_option( 'certificate_generator_cache_keys_' . $email_hash, array() );

		if ( ! empty( $email_cache_keys ) ) {
			foreach ( $email_cache_keys as $key => $timestamp ) {
				delete_transient( $key );
				certificate_generator_debug_log( "Invalidated all cache for key: {$key}" );
			}

			// Clear the cache keys list
			delete_option( 'certificate_generator_cache_keys_' . $email_hash );
		}
	}
}

// Initialize the background processor
$certificate_generator_background_processor = new CertificateGenerator_Background_Processor();
