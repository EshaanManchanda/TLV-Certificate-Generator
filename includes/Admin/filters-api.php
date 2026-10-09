<?php
/**
 * Admin Filters API
 * Helper functions for filtering and previewing recipients
 *
 * @package Certificate Generator
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get unique school names for a post type
 *
 * @param string|array $post_types Post type(s) to query
 * @return array Array of unique school names
 */
function certificate_generator_get_unique_schools( $post_types = array( 'students', 'teachers', 'schools' ) ) {
	global $wpdb;

	$cache_key = 'certificate_generator_unique_schools';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$results = array();

	// SQL-first: union across wp_cg_students, wp_cg_teachers, wp_cg_schools
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$parts  = array();
		foreach ( array( 'students', 'teachers', 'schools' ) as $entity ) {
			$tbl = $tables->get_table( $entity );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$name_col = $entity === 'schools' ? 'school_name' : 'school_name';
				$parts[]  = "SELECT DISTINCT $name_col AS school_name FROM $tbl WHERE $name_col != ''"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( ! empty( $parts ) ) {
			$union   = implode( ' UNION ', $parts ) . ' ORDER BY school_name ASC';
			$results = $wpdb->get_col( $union ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	// CPT fallback if SQL returned nothing
	if ( empty( $results ) ) {
		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the IN() list is %s placeholders, filled by prepare()
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$results      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'school_name' AND pm.meta_value != ''
               AND p.post_type IN ($placeholders) AND p.post_status = 'publish'
             ORDER BY pm.meta_value ASC",
				...$post_types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$results = array_values( array_filter( $results ) );
	set_transient( $cache_key, $results, HOUR_IN_SECONDS );
	return $results;
}

/**
 * Get unique certificate types for a post type
 *
 * @param string|array $post_types Post type(s) to query
 * @return array Array of unique certificate types
 */
function certificate_generator_get_unique_certificate_types( $post_types = array( 'students', 'teachers', 'schools' ) ) {
	global $wpdb;

	$cache_key = 'certificate_generator_unique_cert_types';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$results = array();

	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$parts  = array();
		foreach ( array( 'students', 'teachers', 'schools' ) as $entity ) {
			$tbl = $tables->get_table( $entity );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$parts[] = "SELECT DISTINCT certificate_type FROM $tbl WHERE certificate_type != ''"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( ! empty( $parts ) ) {
			$union   = implode( ' UNION ', $parts ) . ' ORDER BY certificate_type ASC';
			$results = $wpdb->get_col( $union ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	if ( empty( $results ) ) {
		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the IN() list is %s placeholders, filled by prepare()
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$results      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'certificate_type' AND pm.meta_value != ''
               AND p.post_type IN ($placeholders) AND p.post_status = 'publish'
             ORDER BY pm.meta_value ASC",
				...$post_types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$results = array_values( array_filter( $results ) );
	set_transient( $cache_key, $results, HOUR_IN_SECONDS );
	return $results;
}

/**
 * Get unique years (from the year column) across all recipient entity tables.
 *
 * @return array Sorted descending list of year integers as strings.
 */
function certificate_generator_get_unique_years() {
	global $wpdb;

	$cache_key = 'certificate_generator_unique_years';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$results = array();

	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$parts  = array();
		foreach ( array( 'students', 'teachers', 'schools' ) as $entity ) {
			$tbl = $tables->get_table( $entity );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$parts[] = "SELECT DISTINCT `year` FROM $tbl WHERE `year` IS NOT NULL AND `year` > 0"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( ! empty( $parts ) ) {
			$union   = implode( ' UNION ', $parts ) . ' ORDER BY `year` DESC';
			$results = $wpdb->get_col( $union ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	// CPT fallback: extract year from issue_date meta.
	if ( empty( $results ) ) {
		$results = $wpdb->get_col(
			"SELECT DISTINCT YEAR(STR_TO_DATE(pm.meta_value, '%Y-%m-%d')) AS yr
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = 'issue_date'
			   AND pm.meta_value != ''
			   AND p.post_status = 'publish'
			 HAVING yr > 1970
			 ORDER BY yr DESC"
		);
	}

	$results = array_values( array_filter( $results ) );
	set_transient( $cache_key, $results, HOUR_IN_SECONDS );
	return $results;
}

/**
 * Get unique import sources (CSV filename or LMS tag) across all recipient entity tables.
 *
 * @return array Array of unique import_source values
 */
function certificate_generator_get_unique_import_sources() {
	global $wpdb;

	$cache_key = 'certificate_generator_unique_import_sources';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$results = array();

	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$parts  = array();
		foreach ( array( 'students', 'teachers', 'schools' ) as $entity ) {
			$tbl = $tables->get_table( $entity );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$parts[] = "SELECT DISTINCT import_source FROM $tbl WHERE import_source IS NOT NULL AND import_source != ''"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( ! empty( $parts ) ) {
			$union   = implode( ' UNION ', $parts ) . ' ORDER BY import_source ASC';
			$results = $wpdb->get_col( $union ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	$results = array_values( array_filter( $results ) );
	set_transient( $cache_key, $results, HOUR_IN_SECONDS );
	return $results;
}

/**
 * Get events (id + display label) for the event filter dropdown.
 *
 * @return array Array of { value: int, label: string }
 */
function certificate_generator_get_unique_events() {
	global $wpdb;

	$cache_key = 'certificate_generator_unique_events';
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$results = array();
	$table   = $wpdb->prefix . 'cg_events';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$rows = $wpdb->get_results( "SELECT id, event_name, event_code FROM $table ORDER BY start_date DESC, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$results[] = array(
				'value' => (int) $row->id,
				'label' => $row->event_name . ' (' . $row->event_code . ')',
			);
		}
	}

	set_transient( $cache_key, $results, HOUR_IN_SECONDS );
	return $results;
}

/**
 * Build per-entity WHERE fragments and bound params for the shared filter fields.
 *
 * Covers: schools, certificate_types, year, date_from, date_to, email_search, emails.
 * Used by the UNION engine AND by bulk-export single-table handlers so logic stays in one place.
 *
 * @param array  $filters Filters array (same keys as get_filtered_recipients defaults).
 * @param string $alias   Table alias used in the query (default 't').
 * @return array { string[] $where_fragments, array $params }
 */
function certificate_generator_build_recipient_filter_sql( array $filters, string $alias = 't' ): array {
	global $wpdb;
	$where  = array();
	$params = array();
	$p      = $alias !== '' ? "{$alias}." : '';

	if ( ! empty( $filters['schools'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['schools'] ), '%s' ) );
		$where[] = "{$p}school_name IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['schools'] );
	}
	if ( ! empty( $filters['certificate_types'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['certificate_types'] ), '%s' ) );
		$where[] = "{$p}certificate_type IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['certificate_types'] );
	}
	if ( ! empty( $filters['sources'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['sources'] ), '%s' ) );
		$where[] = "{$p}import_source IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['sources'] );
	}
	if ( ! empty( $filters['events'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['events'] ), '%d' ) );
		$where[] = "{$p}event_id IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, array_map( 'intval', $filters['events'] ) );
	}
	if ( ! empty( $filters['year'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['year'] ), '%d' ) );
		$where[] = "{$p}year IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, array_map( 'intval', $filters['year'] ) );
	}
	if ( ! empty( $filters['date_from'] ) ) {
		$where[]  = "{$p}issue_date >= %s";
		$params[] = $filters['date_from'];
	}
	if ( ! empty( $filters['date_to'] ) ) {
		$where[]  = "{$p}issue_date <= %s";
		$params[] = $filters['date_to'];
	}
	if ( ! empty( $filters['email_search'] ) ) {
		$where[]  = "{$p}email LIKE %s";
		$params[] = '%' . $wpdb->esc_like( $filters['email_search'] ) . '%';
	}
	if ( ! empty( $filters['emails'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['emails'] ), '%s' ) );
		$where[] = "{$p}email IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['emails'] );
	}

	return array( $where, $params );
}

/**
 * Flush all filter dropdown transients. Call after any custom-table write.
 */
function certificate_generator_flush_filter_caches(): void {
	delete_transient( 'certificate_generator_unique_schools' );
	delete_transient( 'certificate_generator_unique_cert_types' );
	delete_transient( 'certificate_generator_unique_years' );
	delete_transient( 'certificate_generator_unique_import_sources' );
	delete_transient( 'certificate_generator_unique_events' );
}

// Also flush on CPT saves (legacy path).
add_action(
	'save_post',
	function ( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'students', 'teachers', 'schools', 'certificates' ), true ) ) {
			certificate_generator_flush_filter_caches();
		}
	}
);

/**
 * Get unique email addresses for a post type
 *
 * @param string|array $post_types Post type(s) to query
 * @return array Array of unique email addresses
 */
function certificate_generator_get_unique_emails( $post_types = array( 'students', 'teachers', 'schools' ) ) {
	global $wpdb;

	if ( ! is_array( $post_types ) ) {
		$post_types = array( $post_types );
	}

	$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the IN() list is %s placeholders, filled by prepare()

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$query = $wpdb->prepare(
		"SELECT DISTINCT pm.meta_value as email
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key = 'email'
         AND pm.meta_value != ''
         AND p.post_type IN ($placeholders)
         AND p.post_status = 'publish'
         ORDER BY pm.meta_value ASC",
		...$post_types
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$results = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	return array_filter( $results );
}

/**
 * Get email send status for multiple posts
 *
 * @param array $post_ids Array of post IDs
 * @return array Associative array [post_id => status]
 */
function certificate_generator_get_email_status_for_posts( $post_ids ) {
	global $wpdb;

	if ( empty( $post_ids ) ) {
		return array();
	}

	$table_name   = $wpdb->prefix . 'cert_email_logs';
	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$query = $wpdb->prepare(
		"SELECT certificate_id,
                MAX(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as is_sent,
                MAX(sent_at) as last_sent
         FROM $table_name
         WHERE certificate_id IN ($placeholders)
         GROUP BY certificate_id",
		...$post_ids
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	$status_map = array();
	foreach ( $results as $row ) {
		$status_map[ $row['certificate_id'] ] = array(
			'sent'      => (bool) $row['is_sent'],
			'last_sent' => $row['last_sent'],
		);
	}

	return $status_map;
}

/**
 * Build the filtered recipient set across wp_cg_students/teachers/schools.
 *
 * One row per certificate record; the list, count and statistics all select
 * from this so the numbers on the Bulk Send page always agree.
 *
 * @param array $filters Filter criteria (same keys as get_filtered_recipients).
 * @return array{0:string,1:array}|null [ SQL, params ] or null when the SQL tables are unavailable.
 */
function certificate_generator_recipient_set_sql( array $filters ): ?array {
	global $wpdb;

	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return null;
	}

	$filters = wp_parse_args(
		$filters,
		array(
			'post_types'        => array( 'students', 'teachers', 'schools' ),
			'email_status'      => array(),
			'skip_already_sent' => false,
		)
	);

	$tables     = \CertificateGenerator\Database\CustomTables::instance();
	$email_logs = $wpdb->prefix . 'cert_email_logs';
	$entity_map = array(
		'students' => 'student_name',
		'teachers' => 'teacher_name',
		'schools'  => 'school_name',
	);

	$parts  = array();
	$params = array();

	foreach ( $entity_map as $type => $name_col ) {
		if ( ! in_array( $type, (array) $filters['post_types'], true ) ) {
			continue;
		}
		$tbl = $tables->get_table( $type );
		if ( ! $tbl || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
			continue;
		}

		// send_email = 1: included in bulk sends. send_email = 0: opt-out (admin individual send bypasses this).
		[ $extra_where, $extra_params ] = certificate_generator_build_recipient_filter_sql( $filters, 't' );
		$where_sql                      = implode( ' AND ', array_merge( array( 't.send_email = 1' ), $extra_where ) );
		$params                         = array_merge( $params, $extra_params );

		// "Sent" is per certificate (address + certificate type), so a new certificate for an
		// address that was already emailed is still listed. Log rows written without a type
		// (older versions) keep the old per-address meaning, so upgrading never re-sends them.
		// ponytail: two certificates with the same address AND type (e.g. "Participation" in two
		// years) still count as one; per-row tracking needs a log column, add if that case shows up.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$parts[] = "SELECT t.id AS row_id, t.wp_post_id AS post_id, '$type' AS post_type,
				t.$name_col AS name, t.email, t.school_name,
				t.certificate_type, t.issue_date, t.year,
				CASE WHEN EXISTS (
					SELECT 1 FROM $email_logs l
					WHERE l.status = 'sent'
					  AND l.recipient_email COLLATE utf8mb4_unicode_ci = t.email COLLATE utf8mb4_unicode_ci
					  AND ( COALESCE(l.certificate_type, '') = ''
					        OR l.certificate_type COLLATE utf8mb4_unicode_ci = t.certificate_type COLLATE utf8mb4_unicode_ci )
				) THEN 'sent' ELSE NULL END AS email_status,
				( SELECT MAX(l2.sent_at) FROM $email_logs l2
				  WHERE l2.recipient_email COLLATE utf8mb4_unicode_ci = t.email COLLATE utf8mb4_unicode_ci ) AS last_sent
			FROM $tbl t
			WHERE $where_sql";
	}

	if ( empty( $parts ) ) {
		return null;
	}

	// Email-status post-filter. Both apply: skipping already-sent must not discard the status ticks.
	$status_where = array();
	if ( $filters['skip_already_sent'] ) {
		$status_where[] = "(email_status IS NULL OR email_status != 'sent')";
	}
	if ( ! empty( $filters['email_status'] ) ) {
		$sc = array();
		foreach ( (array) $filters['email_status'] as $s ) {
			if ( $s === 'sent' ) {
				$sc[] = "email_status = 'sent'";
			}
			if ( $s === 'not_sent' ) {
				$sc[] = "((email_status IS NULL OR email_status != 'sent') AND email IS NOT NULL AND email != '')";
			}
			if ( $s === 'no_email' ) {
				$sc[] = "(email IS NULL OR email = '')";
			}
		}
		if ( $sc ) {
			$status_where[] = '(' . implode( ' OR ', $sc ) . ')';
		}
	}

	// UNION ALL: UNION would silently merge two genuinely separate certificates
	// whose visible columns happen to match, under-counting the total.
	$union = '(' . implode( ') UNION ALL (', $parts ) . ')';
	$where = $status_where ? ' WHERE ' . implode( ' AND ', $status_where ) : '';

	return array( "SELECT * FROM ($union) AS recipients$where", $params );
}

/**
 * $wpdb->prepare() refuses a query with no placeholders; skip it when there are no params.
 */
function certificate_generator_prepare_maybe( string $sql, array $params ): string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $params ? $wpdb->prepare( $sql, ...$params ) : $sql;
}

/**
 * Get filtered recipients based on filter criteria
 *
 * @param array $filters Filter criteria
 * @return array Array of recipient data
 */
function certificate_generator_get_filtered_recipients( $filters = array() ) {
	global $wpdb;

	$defaults = array(
		'post_types'        => array( 'students', 'teachers', 'schools' ),
		'schools'           => array(),
		'certificate_types' => array(),
		'sources'           => array(),
		'events'            => array(),
		'year'              => array(),
		'date_from'         => '',
		'date_to'           => '',
		'email_status'      => array(),
		'emails'            => array(),
		'email_search'      => '',
		'skip_already_sent' => true,
		'limit'             => 500,
		'offset'            => 0,
	);
	$filters  = wp_parse_args( $filters, $defaults );

	// SQL-first path — query wp_cg_* tables
	$set = certificate_generator_recipient_set_sql( $filters );
	if ( null !== $set ) {
		[ $sql, $params ] = $set;
		$params[]         = $filters['limit'];
		$params[]         = $filters['offset'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( "$sql ORDER BY name ASC, post_type ASC, row_id ASC LIMIT %d OFFSET %d", ...$params ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	// CPT fallback — only reachable when CustomTables class is absent (misconfigured/broken install).
	// send_email opt-out cannot be enforced here: the flag lives in wp_cg_* SQL tables, not postmeta.
	// In a normal install this path is never hit; CustomTables is always loaded.
	$query = "SELECT DISTINCT p.ID as post_id, p.post_title, p.post_type,
                pm_email.meta_value as email, pm_name.meta_value as name,
                pm_school.meta_value as school_name, pm_type.meta_value as certificate_type,
                pm_issue.meta_value as issue_date,
                el.status as email_status, el.sent_at as last_sent
              FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} pm_email  ON p.ID = pm_email.post_id  AND pm_email.meta_key  = 'email'
              LEFT JOIN {$wpdb->postmeta} pm_school ON p.ID = pm_school.post_id AND pm_school.meta_key = 'school_name'
              LEFT JOIN {$wpdb->postmeta} pm_type   ON p.ID = pm_type.post_id   AND pm_type.meta_key   = 'certificate_type'
              LEFT JOIN {$wpdb->postmeta} pm_name   ON p.ID = pm_name.post_id   AND pm_name.meta_key   IN ('student_name','teacher_name','school_name')
              LEFT JOIN {$wpdb->postmeta} pm_issue  ON p.ID = pm_issue.post_id  AND pm_issue.meta_key  = 'issue_date'
              LEFT JOIN (
                  SELECT certificate_id, MAX(sent_at) as sent_at,
                         MAX(CASE WHEN status='sent' THEN 'sent' ELSE NULL END) as status
                  FROM {$wpdb->prefix}cert_email_logs GROUP BY certificate_id
              ) el ON p.ID = el.certificate_id";

	$where      = array( "p.post_status = 'publish'" );
	$cpt_params = array();

	if ( ! empty( $filters['post_types'] ) ) {
		$ph         = implode( ',', array_fill( 0, count( $filters['post_types'] ), '%s' ) );
		$where[]    = "p.post_type IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cpt_params = array_merge( $cpt_params, $filters['post_types'] );
	}
	if ( ! empty( $filters['schools'] ) ) {
		$ph         = implode( ',', array_fill( 0, count( $filters['schools'] ), '%s' ) );
		$where[]    = "pm_school.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cpt_params = array_merge( $cpt_params, $filters['schools'] );
	}
	if ( ! empty( $filters['certificate_types'] ) ) {
		$ph         = implode( ',', array_fill( 0, count( $filters['certificate_types'] ), '%s' ) );
		$where[]    = "pm_type.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cpt_params = array_merge( $cpt_params, $filters['certificate_types'] );
	}
	if ( $filters['skip_already_sent'] ) {
		$where[] = "(el.status IS NULL OR el.status != 'sent')";
	}
	if ( ! empty( $filters['email_search'] ) ) {
		$where[]      = 'pm_email.meta_value LIKE %s';
		$cpt_params[] = '%' . $wpdb->esc_like( $filters['email_search'] ) . '%'; }
	if ( ! empty( $filters['emails'] ) ) {
		$ph         = implode( ',', array_fill( 0, count( $filters['emails'] ), '%s' ) );
		$where[]    = "pm_email.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cpt_params = array_merge( $cpt_params, $filters['emails'] );
	}
	if ( ! empty( $filters['year'] ) ) {
		$ph         = implode( ',', array_fill( 0, count( $filters['year'] ), '%d' ) );
		$where[]    = "YEAR(STR_TO_DATE(pm_issue.meta_value, '%Y-%m-%d')) IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cpt_params = array_merge( $cpt_params, array_map( 'intval', $filters['year'] ) );
	}
	if ( ! empty( $filters['date_from'] ) ) {
		$where[]      = 'pm_issue.meta_value >= %s';
		$cpt_params[] = $filters['date_from'];
	}
	if ( ! empty( $filters['date_to'] ) ) {
		$where[]      = 'pm_issue.meta_value <= %s';
		$cpt_params[] = $filters['date_to'];
	}

	$query       .= ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.post_title ASC';
	$cpt_params[] = $filters['limit'];
	$cpt_params[] = $filters['offset'];
	$query       .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $filters['limit'], $filters['offset'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	array_pop( $cpt_params );
	array_pop( $cpt_params ); // already appended via prepare above

	return $wpdb->get_results( $wpdb->prepare( $query, ...$cpt_params ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Count filtered recipients
 *
 * @param array $filters Filter criteria
 * @return int Count of matching recipients
 */
function certificate_generator_count_filtered_recipients( $filters = array() ) {
	global $wpdb;

	$defaults = array(
		'post_types'        => array( 'students', 'teachers', 'schools' ),
		'schools'           => array(),
		'certificate_types' => array(),
		'sources'           => array(),
		'events'            => array(),
		'year'              => array(),
		'date_from'         => '',
		'date_to'           => '',
		'email_status'      => array(),
		'emails'            => array(),
		'email_search'      => '',
		'skip_already_sent' => true,
	);
	$filters  = wp_parse_args( $filters, $defaults );

	// SQL-first path — same recipient set as get_filtered_recipients, wrapped in COUNT
	$set = certificate_generator_recipient_set_sql( $filters );
	if ( null !== $set ) {
		return (int) $wpdb->get_var( certificate_generator_prepare_maybe( "SELECT COUNT(*) FROM ({$set[0]}) AS c", $set[1] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	// CPT fallback
	$table_name = $wpdb->prefix . 'cert_email_logs';
	$query      = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} pm_email  ON p.ID = pm_email.post_id  AND pm_email.meta_key  = 'email'
              LEFT JOIN {$wpdb->postmeta} pm_school ON p.ID = pm_school.post_id AND pm_school.meta_key = 'school_name'
              LEFT JOIN {$wpdb->postmeta} pm_type   ON p.ID = pm_type.post_id   AND pm_type.meta_key   = 'certificate_type'
              LEFT JOIN {$wpdb->postmeta} pm_issue  ON p.ID = pm_issue.post_id  AND pm_issue.meta_key  = 'issue_date'
              LEFT JOIN (
                  SELECT certificate_id, MAX(CASE WHEN status='sent' THEN 'sent' ELSE NULL END) as status
                  FROM $table_name GROUP BY certificate_id
              ) el ON p.ID = el.certificate_id";

	$where  = array( "p.post_status = 'publish'" );
	$params = array();

	if ( ! empty( $filters['post_types'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['post_types'] ), '%s' ) );
		$where[] = "p.post_type IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['post_types'] );
	}
	if ( ! empty( $filters['schools'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['schools'] ), '%s' ) );
		$where[] = "pm_school.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['schools'] );
	}
	if ( ! empty( $filters['certificate_types'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['certificate_types'] ), '%s' ) );
		$where[] = "pm_type.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['certificate_types'] );
	}
	if ( $filters['skip_already_sent'] ) {
		$where[] = "(el.status IS NULL OR el.status != 'sent')";
	} elseif ( ! empty( $filters['email_status'] ) ) {
		$sc = array();
		foreach ( $filters['email_status'] as $s ) {
			if ( $s === 'sent' ) {
				$sc[] = "el.status = 'sent'";
			}
			if ( $s === 'not_sent' ) {
				$sc[] = "(el.status IS NULL OR el.status != 'sent')";
			}
			if ( $s === 'no_email' ) {
				$sc[] = "(pm_email.meta_value IS NULL OR pm_email.meta_value = '')";
			}
		}
		if ( $sc ) {
			$where[] = '(' . implode( ' OR ', $sc ) . ')';
		}
	}
	if ( ! empty( $filters['email_search'] ) ) {
		$where[]  = 'pm_email.meta_value LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $filters['email_search'] ) . '%';
	}
	if ( ! empty( $filters['emails'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['emails'] ), '%s' ) );
		$where[] = "pm_email.meta_value IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, $filters['emails'] );
	}
	if ( ! empty( $filters['year'] ) ) {
		$ph      = implode( ',', array_fill( 0, count( $filters['year'] ), '%d' ) );
		$where[] = "YEAR(STR_TO_DATE(pm_issue.meta_value, '%Y-%m-%d')) IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params  = array_merge( $params, array_map( 'intval', $filters['year'] ) );
	}
	if ( ! empty( $filters['date_from'] ) ) {
		$where[]  = 'pm_issue.meta_value >= %s';
		$params[] = $filters['date_from'];
	}
	if ( ! empty( $filters['date_to'] ) ) {
		$where[]  = 'pm_issue.meta_value <= %s';
		$params[] = $filters['date_to'];
	}

	$query .= ' WHERE ' . implode( ' AND ', $where );

	if ( ! empty( $params ) ) {
		return (int) $wpdb->get_var( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	return (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Parse email list from text input
 * Supports comma-separated, newline-separated, or space-separated emails
 *
 * @param string $email_list_text Raw text input
 * @return array Array of valid email addresses
 */
function certificate_generator_parse_email_list( $email_list_text ) {
	if ( empty( $email_list_text ) ) {
		return array();
	}

	// Split by common delimiters
	$emails = preg_split( '/[\s,;]+/', $email_list_text, -1, PREG_SPLIT_NO_EMPTY );

	// Validate and filter
	$valid_emails = array();
	foreach ( $emails as $email ) {
		$email = trim( $email );
		if ( is_email( $email ) ) {
			$valid_emails[] = $email;
		}
	}

	return array_unique( $valid_emails );
}

/**
 * Calculate statistics for filtered recipients
 * Including email grouping info
 *
 * @param array $filters Filter criteria
 * @return array Statistics array
 */
function certificate_generator_get_filter_statistics( $filters = array() ) {
	global $wpdb;

	$stats = array(
		'total_certificates' => 0, // certificate records matching the filters (all pages, not just the preview)
		'with_email'         => 0, // of those, records that have an email address
		'unique_emails'      => 0, // emails that will actually be queued — one per address
		'no_email'           => 0, // records that will be skipped
		'already_sent'       => 0, // records whose address already received a certificate email
		'grouped_sends'      => 0, // addresses receiving more than one certificate in a single email
		'invalid_email'      => 0, // records whose address is_email() rejects (the send step drops them)
		'duplicate_rows'     => 0, // extra copies of the same certificate (address, name, type, date)
		'long_names'         => 0, // names that won't fit their field even at the smallest size
	);

	$set = certificate_generator_recipient_set_sql( $filters );
	if ( null === $set ) {
		return $stats;
	}
	[ $sql, $params ] = $set;

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$row = $wpdb->get_row(
		certificate_generator_prepare_maybe(
			"SELECT COUNT(*) AS total_certificates,
				SUM(email IS NOT NULL AND email != '') AS with_email,
				COUNT(DISTINCT NULLIF(email, '')) AS unique_emails,
				SUM(email IS NULL OR email = '') AS no_email,
				SUM(email_status = 'sent') AS already_sent
			FROM ($sql) AS s",
			$params
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( (array) $row as $key => $value ) {
		$stats[ $key ] = (int) $value;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$stats['grouped_sends'] = (int) $wpdb->get_var(
		certificate_generator_prepare_maybe(
			"SELECT COUNT(*) FROM (
				SELECT email FROM ($sql) AS s WHERE email IS NOT NULL AND email != '' GROUP BY email HAVING COUNT(*) > 1
			) AS g",
			$params
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Dry-run warnings (PreSendCheck): measured in PHP, over every matching record.
	if ( class_exists( '\CertificateGenerator\Services\PreSendCheck' ) ) {
		$rows  = $wpdb->get_results( certificate_generator_prepare_maybe( "SELECT name, email, certificate_type, issue_date, post_type FROM ($sql) AS s", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$stats = array_merge( $stats, \CertificateGenerator\Services\PreSendCheck::summarize( $rows ?: array() ) );
	}

	return $stats;
}
