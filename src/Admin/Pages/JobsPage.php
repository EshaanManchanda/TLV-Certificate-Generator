<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Services\Jobs;

/**
 * Certificate Generator → Bulk jobs: recent generation jobs, and one job's progress (polled;
 * each poll also runs a batch, so a job finishes while the page is open even if WP-Cron
 * doesn't fire), failed items with their errors, and Retry failed.
 */
class JobsPage {

	private const SLUG = 'cg-jobs';
	private const CAP  = 'certificate_generator_issue';
	private const EDIT = array(
		'students' => 'cg-student-edit',
		'teachers' => 'cg-teacher-edit',
		'schools'  => 'cg-school-edit',
	);

	public function register(): void {
		add_submenu_page( 'cg-dashboard', 'Bulk Jobs', 'Bulk Jobs', self::CAP, self::SLUG, array( $this, 'render' ) );
	}

	public function register_ajax(): void {
		add_action( 'wp_ajax_certificate_generator_job_status', array( $this, 'status_ajax' ) );
	}

	/** The poll: run a batch when no worker holds the job, then report. */
	public function status_ajax(): void {
		check_ajax_referer( 'certificate_generator_job_status' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}
		$id = absint( $_POST['job'] ?? 0 );
		if ( ! Jobs::get( $id ) ) {
			wp_send_json_error( array( 'message' => 'Job not found' ), 404 );
		}
		Jobs::process( $id ); // no-op when finished or locked by another worker
		wp_send_json_success( $this->state( Jobs::get( $id ) ) );
	}

	private function state( array $job ): array {
		$s            = Jobs::summary( $job, 0 );
		$s['stalled'] = Jobs::is_stalled( $job );
		$s['percent'] = $s['total'] ? (int) floor( 100 * ( $s['done'] + $s['failed'] ) / $s['total'] ) : 0;
		return $s;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions' );
		}
		$id = absint( $_GET['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
		echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Jobs', 'certificate-generator' ) . '</h1>';
		$job = $id ? Jobs::get( $id ) : null;
		if ( $job && 'certificate_generation' === $job['type'] ) {
			$this->render_job( $job );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function render_list(): void {
		$jobs = Jobs::recent( 50 );
		echo '<p>' . esc_html__( 'Certificates generated in the background, 25 at a time. Start one with "Generate certificates" on the Students, Teachers or Schools list.', 'certificate-generator' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Recipients', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Status', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Progress', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Started by', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Created (UTC)', 'certificate-generator' ) . '</th></tr></thead><tbody>';
		if ( ! $jobs ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No jobs yet.', 'certificate-generator' ) . '</td></tr>';
		}
		foreach ( $jobs as $job ) {
			$user = $job['api_key_id'] ? __( 'API key', 'certificate-generator' ) : ( get_userdata( (int) $job['created_by'] )->display_name ?? '—' );
			printf(
				'<tr><td><a href="%s">%d</a></td><td>%s%s</td><td>%s</td><td>%d / %d%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&job=' . (int) $job['id'] ) ),
				(int) $job['id'],
				esc_html( ucfirst( (string) ( $job['params']['entity'] ?? 'students' ) ) ),
				empty( $job['params']['force'] ) ? '' : ' ' . esc_html__( '(re-render)', 'certificate-generator' ),
				esc_html( $this->status_label( $job ) ),
				(int) $job['done'] + (int) $job['failed'],
				(int) $job['total'],
				$job['failed'] ? ' — ' . sprintf( /* translators: %d: failed items */ esc_html__( '%d failed', 'certificate-generator' ), (int) $job['failed'] ) : '',
				esc_html( (string) $user ),
				esc_html( (string) $job['created_at'] )
			);
		}
		echo '</tbody></table>';
	}

	private function status_label( array $job ): string {
		return Jobs::is_stalled( $job ) ? __( 'stalled', 'certificate-generator' ) : (string) $job['status'];
	}

	private function render_job( array $job ): void {
		$id = (int) $job['id'];
		if ( isset( $_POST['cg_job_retry'] ) && check_admin_referer( 'certificate_generator_job_retry_' . $id ) ) {
			if ( Jobs::retry( $id ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Failed items queued again.', 'certificate-generator' ) . '</p></div>';
			}
			$job = Jobs::get( $id );
		}
		$s      = $this->state( $job );
		$entity = (string) ( $job['params']['entity'] ?? 'students' );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">&larr; ' . esc_html__( 'All jobs', 'certificate-generator' ) . '</a></p>';
		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: 1: job id, 2: recipient type */ __( 'Job #%1$d — %2$s', 'certificate-generator' ), $id, ucfirst( $entity ) ) ) );
		?>
		<div id="cg-job" data-job="<?php echo (int) $id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'certificate_generator_job_status' ) ); ?>">
			<progress id="cg-job-bar" max="100" value="<?php echo (int) $s['percent']; ?>" style="width:100%;max-width:600px;height:20px"></progress>
			<p id="cg-job-text" aria-live="polite"></p>
			<p id="cg-job-stalled" class="notice notice-warning" style="padding:8px" hidden>
				<?php esc_html_e( 'Stalled: no worker has run this job for 15 minutes. WP-Cron may be disabled on this site. Keep this page open (it runs the job while open), or run: wp certificate-generator jobs run', 'certificate-generator' ); ?> <?php echo (int) $id; ?>
			</p>
		</div>
		<?php
		$failed = array_filter( $job['items'], static fn( $i ) => 'failed' === $i['status'] );
		if ( $failed ) {
			echo '<h3>' . esc_html__( 'Failed items', 'certificate-generator' ) . '</h3>';
			if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				echo '<form method="post">';
				wp_nonce_field( 'certificate_generator_job_retry_' . $id );
				echo '<p><button class="button button-primary" name="cg_job_retry" value="1">' . esc_html__( 'Retry failed items', 'certificate-generator' ) . '</button> ' . esc_html__( 'Done items are not generated again.', 'certificate-generator' ) . '</p></form>';
			}
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Record', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Error', 'certificate-generator' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $failed, 0, 200 ) as $item ) {
				printf(
					'<tr><td><a href="%s">#%d</a></td><td>%s</td></tr>',
					esc_url( admin_url( 'admin.php?page=' . ( self::EDIT[ $entity ] ?? self::EDIT['students'] ) . '&id=' . (int) $item['ref'] ) ),
					(int) $item['ref'],
					esc_html( (string) ( $item['error'] ?? '' ) )
				);
			}
			echo '</tbody></table>';
			if ( count( $failed ) > 200 ) {
				echo '<p>' . esc_html( sprintf( /* translators: %d: number of failed items not shown */ __( '…and %d more.', 'certificate-generator' ), count( $failed ) - 200 ) ) . '</p>';
			}
		}
		?>
		<script>
		(function () {
			var box = document.getElementById('cg-job'), bar = document.getElementById('cg-job-bar'), text = document.getElementById('cg-job-text'), stalled = document.getElementById('cg-job-stalled');
			var done = ['done', 'failed', 'cancelled'];
			function show(s) {
				bar.value = s.percent;
				text.textContent = s.status + ' — ' + s.done + ' done, ' + s.failed + ' failed, ' + s.pending + ' pending of ' + s.total + ' (' + s.percent + '%)';
				stalled.hidden = !s.stalled;
			}
			show(<?php echo wp_json_encode( $s ); ?>);
			var initial = '<?php echo esc_js( (string) $job['status'] ); ?>';
			if (done.indexOf(initial) !== -1) { return; }
			function poll() {
				var body = new FormData();
				body.append('action', 'certificate_generator_job_status');
				body.append('_ajax_nonce', box.dataset.nonce);
				body.append('job', box.dataset.job);
				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						if (!r.success) { text.textContent = r.data && r.data.message ? r.data.message : 'Error'; return; }
						show(r.data);
						if (done.indexOf(r.data.status) !== -1) { window.location.href = window.location.href; return; } // GET, not a re-POST; shows failed items + Retry
						setTimeout(poll, 3000);
					})
					.catch(function () { setTimeout(poll, 10000); });
			}
			setTimeout(poll, 1000);
		})();
		</script>
		<?php
	}
}
