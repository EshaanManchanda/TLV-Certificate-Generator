<?php
/**
 * Documentation & Getting Started Page
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── AJAX: dismiss welcome banner ─────────────────────────────────────────────
add_action(
	'wp_ajax_cg_dismiss_welcome',
	function () {
		check_ajax_referer( 'cg_dismiss_welcome', 'nonce' );
		update_option( 'cg_welcome_dismissed', 1 );
		wp_send_json_success();
	}
);

// ── Welcome banner (shows once after activation) ─────────────────────────────
add_action( 'admin_notices', 'cg_maybe_show_welcome_banner' );
function cg_maybe_show_welcome_banner() {
	if ( get_option( 'cg_welcome_dismissed' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$docs_url = admin_url( 'admin.php?page=cg-documentation' );
	?>
	<div class="notice notice-info cg-welcome-banner" style="display:flex;align-items:center;gap:16px;padding:14px 16px;">
		<span class="dashicons dashicons-award" style="font-size:32px;color:#2271b1;flex-shrink:0;"></span>
		<div style="flex:1;">
			<strong>Certificate Generator is active!</strong>
			New here? The <a href="<?php echo esc_url( $docs_url ); ?>">Getting Started guide</a>
			walks you through SMTP setup, templates, and your first bulk send in under 10 minutes.
		</div>
		<button type="button" class="cg-dismiss-welcome notice-dismiss" style="position:static;flex-shrink:0;">
			<span class="screen-reader-text"><?php esc_html_e( 'Dismiss', 'certificate-generator' ); ?></span>
		</button>
	</div>
	<script>
	(function(){
		document.querySelector('.cg-dismiss-welcome')?.addEventListener('click', function(){
			this.closest('.cg-welcome-banner').remove();
			fetch(ajaxurl, {
				method: 'POST',
				headers: {'Content-Type':'application/x-www-form-urlencoded'},
				body: 'action=cg_dismiss_welcome&nonce=<?php echo esc_js( wp_create_nonce( 'cg_dismiss_welcome' ) ); ?>'
			});
		});
	})();
	</script>
	<?php
}

// ── Page renderer ─────────────────────────────────────────────────────────────
function cg_render_documentation_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ) );
	}

	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'getting-started';
	$base_url   = admin_url( 'admin.php?page=cg-documentation' );

	// ── Live setup status ────────────────────────────────────────────────────
	$admin_email = get_option( 'admin_email', '' );

	global $wpdb;
	$cg_table        = $wpdb->prefix . 'certificate_generator';
	$has_cg_table    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cg_table ) ) === $cg_table;
	$cert_count      = $has_cg_table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cg_table" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$tpl_table       = $wpdb->prefix . 'cg_certificate_templates';
	$templates_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tpl_table WHERE status = 'published'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$students_table  = $wpdb->prefix . 'cg_students';
	$students_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $students_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	$teachers_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cg_teachers" );
	$schools_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cg_schools" );
	$steps          = ! function_exists( 'cg_setup_steps' ) ? array() : cg_setup_steps( $templates_count, $students_count + $teachers_count + $schools_count, $cert_count > 0 );

	$all_done = ! in_array( false, array_column( $steps, 'done' ), true );

	?>
	<div class="wrap cg-docs-wrap">
	<?php cg_ui_page_header( 'Certificate Generator — Documentation', 'Set-up checklist, how-to guides, troubleshooting and FAQ.' ); ?>

	<style>
	.cg-docs-wrap { max-width: 980px; }
	.cg-tab-content { background:#fff; border:1px solid #c3c4c7; border-top:none; padding:28px 32px; }
	.cg-step { display:flex; align-items:flex-start; gap:12px; padding:10px 0; border-bottom:1px solid #f0f0f1; }
	.cg-step:last-child { border-bottom:none; }
	.cg-section { margin-bottom:0; }
	.cg-section summary { font-size:15px; font-weight:600; cursor:pointer; padding:14px 0; list-style:none; display:flex; align-items:center; gap:8px; }
	.cg-section summary::-webkit-details-marker { display:none; }
	.cg-section summary::before { content:'▶'; font-size:11px; color:#2271b1; transition:transform .15s; }
	.cg-section[open] summary::before { transform:rotate(90deg); }
	.cg-section-body { padding:4px 0 20px 24px; color:#444; line-height:1.7; }
	.cg-section-body h4 { margin:16px 0 6px; color:#1d2327; }
	.cg-section-body ul,
	.cg-section-body ol { margin-left:20px; }
	.cg-section-body code { background:#f6f7f7; padding:2px 6px; border-radius:3px; font-size:13px; }
	.cg-section-body .cg-note { background:#f0f6fc; border-left:4px solid #2271b1; padding:10px 14px; border-radius:0 4px 4px 0; margin:12px 0; }
	.cg-section-body .cg-warn { background:#fff8e5; border-left:4px solid #f0b849; padding:10px 14px; border-radius:0 4px 4px 0; margin:12px 0; }
	.cg-divider { border:none; border-top:1px solid #f0f0f1; margin:4px 0; }
	.cg-docs-wrap .nav-tab-wrapper { margin-bottom:0; }
	.cg-tab-content > h2:first-child, .cg-tab-content > .notice + h2 { margin-top:0; }
	.cg-step__label { flex:1; }
	.cg-docs-steps { margin-bottom:28px; }
	.cg-docs-steps-list { line-height:2; font-size:14px; }
	.cg-docs-links { margin:16px 0; }
	.cg-docs-link { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #c3c4c7; border-radius:4px; text-decoration:none; color:#1d2327; transition:border-color .15s; }
	.cg-docs-link:hover, .cg-docs-link:focus { border-color:#2271b1; color:#1d2327; }
	.cg-docs-link .dashicons { color:#2271b1; flex-shrink:0; }
	</style>

	<?php
	cg_ui_tabs(
		array(
			'getting-started' => '🚀 Getting Started',
			'user-guide'      => '📖 User Guide',
			'email-guide'     => '✉️ Email & Bulk Send',
			'troubleshooting' => '🔧 Troubleshooting',
			'faq'             => '❓ FAQ',
		),
		$active_tab,
		$base_url
	);
	?>

	<div class="cg-tab-content">

	<?php /* ═══════════════════════  GETTING STARTED  ══════════════════════════ */ ?>
	<?php if ( $active_tab === 'getting-started' ) : ?>

		<?php
		if ( $all_done ) {
			cg_ui_notice( 'success', '<strong>All setup steps complete — your plugin is ready for production!</strong>', false, true );
		} else {
			cg_ui_notice( 'warning', 'Complete the steps below before sending live certificates.', false, true );
		}
		?>

		<h2>Setup Checklist</h2>
		<div class="cg-docs-steps">
		<?php foreach ( $steps as $step ) : ?>
			<div class="cg-step">
				<?php echo $step['done'] ? cg_ui_badge( 'Done', 'good' ) : cg_ui_badge( 'To do', 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="cg-step__label">
					<strong><?php echo esc_html( $step['label'] ); ?></strong>
				</div>
				<?php if ( ! $step['done'] ) : ?>
				<a href="<?php echo esc_url( $step['url'] ); ?>" class="button button-small">
					<?php echo esc_html( $step['cta'] ); ?> →
				</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>

		<hr class="cg-divider">
		<h2>Quick Start (4 steps)</h2>
		<ol class="cg-docs-steps-list">
			<li>
				<strong>Create a certificate template</strong> — go to
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cg-templates' ) ); ?>">Templates</a>,
				upload your background image, and position the text fields (name, certificate type, date).
			</li>
			<li>
				<strong>Email yourself a test certificate</strong> — save the template, then click
				<em>Email me a test certificate</em> under it. If it doesn't arrive (check spam), set up
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mail-smtp' ) ); ?>">WP Mail SMTP</a> with your provider (Gmail/Hostinger/SendGrid).
			</li>
			<li>
				<strong>Add students</strong> — go to
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cg-students' ) ); ?>">Students</a>
				and add records manually, or use
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cg-bulk-import' ) ); ?>">Bulk Import</a>
				to upload a CSV.
			</li>
			<li>
				<strong>Bulk send</strong> — go to
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=certificate-bulk-send' ) ); ?>">Bulk Send</a>,
				filter by school / certificate type, and click <em>Send Certificates</em>.
			</li>
		</ol>

		<hr class="cg-divider">
		<h2>Quick Links</h2>
		<div class="cg-grid cg-docs-links">
			<?php
			$links = array(
				array( 'Templates', admin_url( 'admin.php?page=cg-templates' ), 'dashicons-images-alt2' ),
				array( 'Students', admin_url( 'admin.php?page=cg-students' ), 'dashicons-groups' ),
				array( 'Bulk Send', admin_url( 'admin.php?page=certificate-bulk-send' ), 'dashicons-email-alt' ),
				array( 'Email Logs', admin_url( 'admin.php?page=certificate-email-logs' ), 'dashicons-list-view' ),
				array( 'Bulk Import', admin_url( 'admin.php?page=cg-bulk-import' ), 'dashicons-upload' ),
				array( 'Analytics', admin_url( 'admin.php?page=cg-analytics' ), 'dashicons-chart-bar' ),
			);
			foreach ( $links as [$label, $url, $icon] ) :
				?>
			<a href="<?php echo esc_url( $url ); ?>" class="cg-docs-link">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<strong><?php echo esc_html( $label ); ?></strong>
			</a>
			<?php endforeach; ?>
		</div>

		<?php /* ═══════════════════════  USER GUIDE  ══════════════════════════════ */ ?>
	<?php elseif ( $active_tab === 'user-guide' ) : ?>

		<h2>User Guide</h2>

		<details class="cg-section" open>
			<summary>Certificate Templates</summary>
			<div class="cg-section-body">
				<h4>Creating a template</h4>
				<ol>
					<li>Go to <strong>Certificate Generator → Templates → Add New</strong>.</li>
					<li>Choose the <strong>Entity Type</strong> the template is for: Student, Teacher, or School. This controls which core fields (e.g. <code>graduation_date</code> for students, <code>subject</code> for teachers) appear in the field dropdowns below.</li>
					<li>Upload your own certificate background image (PNG or JPG, A4 landscape recommended), or pick one from the <strong>bundled template gallery</strong> (see below) to skip designing your own.</li>
					<li>Set <strong>Orientation</strong> (Portrait/Landscape) and <strong>Page Size</strong> (A4, Letter, Legal, or Custom).</li>
					<li>Set the default <strong>Font</strong>, size, and colour — each field can also override these individually.</li>
					<li>Use the drag-and-drop canvas to position each field slot, and pick what data each slot shows from its dropdown (see <strong>Field mapping</strong> below).</li>
					<li>Click <strong>Save Template</strong>.</li>
				</ol>
				<div class="cg-note">Each student's certificate type is matched against the template's <em>Certificate Type</em> name (and, if set, its linked <em>Event</em>/event date — see the <strong>Events</strong> section). If no match is found, the default template is used.</div>

				<h4>Bundled template gallery</h4>
				<p>Don't have a designed background yet? On the template editor, open <strong>Choose from Gallery</strong> to pick a ready-made starter design. Selecting one copies the image into your Media Library and sets it as the template background — you can still reposition fields and swap it out later.</p>

				<h4>Field mapping (what each slot shows)</h4>
				<p>Every field slot on the canvas has a dropdown of data it can display — populated from the <strong>Entity Type</strong> you chose (e.g. Students get <code>student_name</code>, <code>school_name</code>, <code>photo_url</code>, plus any custom fields you've added via CSV import). Changing the Entity Type live-updates the dropdown options.</p>

				<h4>Field types</h4>
				<ul>
					<li><strong>Text</strong> — the default. Renders the selected field's value as text, with alignment (Left/Centre/Right) and width for wrapping.</li>
					<li><strong>Image</strong> — a static image that's the same on every certificate (e.g. a signature or logo). Upload it once on the template; it doesn't come from student data.</li>
					<li><strong>Photo</strong> — draws that specific recipient's own photo (see <strong>Photo</strong> in Managing Students below). Falls back to a blank slot if the recipient has no photo uploaded.</li>
				</ul>
				<div class="cg-note">Image and Photo fields have an optional <strong>Height</strong> (mm) — leave it blank to keep the source image's own aspect ratio, or set it to force a fixed size.</div>

				<h4>QR code &amp; serial number placement</h4>
				<p>Toggle <strong>QR Code</strong> and <strong>Serial Number Display</strong> on the template, then drag their markers to position them — these are separate from the numbered field slots. See the <strong>QR Codes</strong> and <strong>Serial Numbers</strong> sections below.</p>

				<h4>Editing an existing template</h4>
				<p>Open the template and adjust field positions. Changes apply to all future PDF/PNG generations — previously generated files are not regenerated automatically.</p>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Managing Students, Teachers &amp; Schools</summary>
			<div class="cg-section-body">
				<h4>Adding records manually</h4>
				<p>Go to <strong>Students / Teachers / Schools → Add New</strong>. Fill in the required fields: Name, Email, School, Certificate Type, Issue Date.</p>

				<h4>Student photo</h4>
				<p>On a student's edit page, use <strong>Choose from Media Library</strong> under the <em>Photo</em> field to attach their picture. It's only used by templates that have a <strong>Photo</strong>-type field slot (see Certificate Templates above) — leaving it blank is fine for templates that don't use one.</p>

				<h4>Linking to an Event</h4>
				<p>If you've set up an <strong>Event</strong> (see the Events section below), select it on the record's edit page to associate that student/teacher/school with it. This lets templates match by event date and lets you filter bulk sends/exports by event.</p>

				<h4>Bulk import via CSV</h4>
				<ol>
					<li>Go to <strong>Bulk Import</strong> and choose Students, Teachers, Schools, or Certificate Templates.</li>
					<li>Prepare your CSV with the required columns for that entity (see below).</li>
					<li>Upload the file — matching rows (same email, or same name + certificate type) update existing records instead of creating duplicates.</li>
				</ol>
				<div class="cg-note">Required student CSV columns: <code>student_name</code>, <code>email</code>, <code>school_name</code>, <code>certificate_type</code>, <code>issue_date</code>. Optional columns: <code>status</code>, <code>send_email</code>, <code>phone</code>, <code>year</code>, <code>event_code</code>, <code>photo_url</code>. Teachers and Schools follow the same pattern with their own name column instead of <code>student_name</code>.</div>
				<div class="cg-note"><strong>Custom fields:</strong> any extra column not in the list above (e.g. <code>grade</code>, <code>course_name</code>) is automatically saved and registered as a selectable field for that row's certificate type — no separate setup needed. It'll show up in the template editor's field dropdown the next time you edit a matching template.</div>

				<h4>Bulk export</h4>
				<p>Go to <strong>Bulk Export</strong> to download all records (including any custom fields, serial numbers, and photo URLs) as CSV. Use this to back up data, migrate to another site, or edit many records at once and re-import.</p>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Events</summary>
			<div class="cg-section-body">
				<p>Events (<strong>Certificate Generator → Events</strong>) group students, teachers, schools, and templates issued for the same occasion — a graduation ceremony, a course cohort, an annual awards night.</p>
				<ol>
					<li>Create an event with a unique <strong>Event Code</strong>, name, and start/end dates.</li>
					<li>Link records to it either by selecting it on their edit page, or by including an <code>event_code</code> column when bulk-importing CSVs.</li>
					<li>A template can also be tied to a specific event (via its event date), so different cohorts can use different certificate designs automatically.</li>
				</ol>
				<div class="cg-note">The event's edit page shows how many students, teachers, schools, and templates are currently linked to it — handy for double-checking an import landed correctly.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Serial Numbers</summary>
			<div class="cg-section-body">
				<h4>Auto-generation</h4>
				<p>Serial numbers are generated automatically the first time a certificate is created for a recipient. Format is configured in <strong>Serial Settings</strong>.</p>
				<h4>Format options</h4>
				<ul>
					<li><code>PREFIX-{YEAR}-{SEQ}</code> — e.g. <em>CERT-2025-00142</em></li>
					<li>Prefix, year format, and sequence padding are all configurable.</li>
				</ul>
				<div class="cg-note">Re-downloading or re-generating a certificate for the same recipient reuses their existing serial number — it will never issue a second, different serial for the same person and certificate type.</div>
				<h4>Bulk serial assignment</h4>
				<p>Go to <strong>Bulk Serials</strong> to assign serials to existing records that don't have one yet.</p>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>QR Codes</summary>
			<div class="cg-section-body">
				<p>QR codes are embedded in certificates and link to the public verification page (<code>/verify-certificate/</code>).</p>
				<ul>
					<li>QR codes are generated automatically during PDF/PNG creation, once enabled on the template.</li>
					<li>Scanning the code takes the viewer to a page showing the certificate's authenticity status.</li>
					<li>The verification page only shows fields that actually have a value — blank fields are hidden rather than shown empty (smart privacy display).</li>
					<li>No manual setup required — the library is bundled with the plugin.</li>
				</ul>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Certificate Downloads (PDF, PNG &amp; Bulk ZIP)</summary>
			<div class="cg-section-body">
				<h4>PDF</h4>
				<p>The primary format. Generated from the template + recipient data, includes QR code and serial number if enabled.</p>
				<h4>PNG (shareable image)</h4>
				<p>Each certificate can also be downloaded as a PNG image — handy for sharing on social media or LinkedIn where a PDF isn't practical. Available anywhere a PDF download link appears; requires PHP's GD extension on the server (most hosts have this enabled by default).</p>
				<div class="cg-note">PNG rendering aims for the same field positions as the PDF, but text wrapping can differ very slightly since the two use different rendering engines.</div>
				<h4>Bulk ZIP download for a school</h4>
				<p>The <code>[school_bulk_certificate_download]</code> shortcode lets a school look up its name and download a ZIP of every one of its students' PDF certificates in one click.</p>
				<div class="cg-note">This shortcode comes with the <strong>Certificate Generator Pro</strong> add-on. Admins can always download ZIPs from <strong>Certificate Generator → Download Certificates</strong>.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Digital Badges (beta, opt-in)</summary>
			<div class="cg-section-body">
				<p>In addition to the full certificate, a template can generate a smaller companion <strong>badge</strong> image — a compact graphic with the recipient's name overlaid on a badge-specific background.</p>
				<div class="cg-warn">⚠️ This is an opt-in beta feature, off by default. Turn it on first at <strong>Settings → Tools → Features &amp; Integrations</strong> ("Badges system") — without that, uploading a badge image on a template has no effect.</div>
				<p>Once enabled, upload a badge background image on the template's <em>Badge Image</em> field. A badge PNG is then generated automatically alongside every certificate issued from that template.</p>
				<div class="cg-note">Current limitation: the generated badge is stored on the server (<code>wp-content/uploads/cg-badges/</code>) but there's no download link, email attachment, or admin display wired up for it yet — retrieving it today requires a developer to fetch it directly. Leave the Badge Image field empty to skip this entirely.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Analytics</summary>
			<div class="cg-section-body">
				<p>Go to <strong>Certificate Generator → Analytics</strong> to see:</p>
				<ul>
					<li>Total certificates issued</li>
					<li>Emails sent / failed / pending</li>
					<li>Send volume over time (chart)</li>
					<li>Breakdown by certificate type and school</li>
				</ul>
				<div class="cg-note">Analytics pull from the <code>wp_cert_email_logs</code> table. Data is only as complete as your email log history.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Shortcodes</summary>
			<div class="cg-section-body">
				<h4>Available shortcodes</h4>
				<ul>
					<li><code>[student_search]</code> — front-end search form letting a student look up and download their own certificate(s) by email.</li>
					<li><code>[teacher_search]</code> — same lookup flow for teachers.</li>
					<li><code>[school_search]</code> — same lookup flow for schools (searches by school name and place).</li>
					<li><code>[school_bulk_certificate_download]</code> — lets a school download a ZIP of all its students' certificates. <strong>Needs the Pro add-on.</strong></li>
					<li><code>[cg_verify_certificate]</code> — public certificate-authenticity verification page (also linked from certificate QR codes).</li>
				</ul>

				<h4>Customizing search form text</h4>
				<p>The three search shortcodes (<code>student_search</code>, <code>teacher_search</code>, <code>school_search</code>) support optional attributes to override their title, subtitle, button text, and help text on a specific page:</p>
				<pre class="cg-pre">[student_search title="Custom Title" subtitle="Custom subtitle" button_text="Find Mine" help_text="Custom help text"]</pre>
				<p>To set a <strong>sitewide default</strong> instead of editing every page's shortcode, go to
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=certificate_generator_settings&tab=shortcode_text' ) ); ?>">Settings → Shortcode Text</a>.
					Fields left blank there fall back to the plugin's built-in defaults, and a shortcode attribute on a specific page always overrides the sitewide setting.
				</p>
				<div class="cg-note">Resolution order for each piece of text: shortcode attribute → Settings → Shortcode Text value → built-in default.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Free plugin and Pro add-on</summary>
			<div class="cg-section-body">
				<p>This plugin has no limits: certificates, emails, imports and exports are unlimited, and every feature on this page works without a license.</p>
				<p>The optional <strong>Certificate Generator Pro</strong> add-on is a separate plugin. It adds the integrations with other plugins (auto-issue on course completion in Tutor LMS, LearnDash, LifterLMS, Sensei and LearnPress, and on WooCommerce orders), the <code>[school_bulk_certificate_download]</code> shortcode, custom font (.ttf) upload, the REST API and priority support. <a href="https://techlovev.in/certificate-generator/pricing" target="_blank" rel="noopener">Plans and pricing</a>.</p>
			</div>
		</details>

		<?php /* ═══════════════════════  EMAIL & BULK SEND  ══════════════════════ */ ?>
	<?php elseif ( $active_tab === 'email-guide' ) : ?>

		<h2>Email &amp; Bulk Send Guide</h2>

		<details class="cg-section" open>
			<summary>Setting up SMTP (recommended for bulk sending)</summary>
			<div class="cg-section-body">
				<p>The plugin sends via WordPress's <code>wp_mail()</code>, which works out of the box but often lands in spam. For reliable bulk delivery, configure a real SMTP mailer.</p>
				<ol>
					<li>Install the free <strong>WP Mail SMTP</strong> plugin.</li>
					<li>Go to <strong>WP Mail SMTP → Settings</strong>.</li>
					<li>Set <em>From Email</em> to your authenticated sender address (must match your SMTP account login).</li>
					<li>Choose your mailer (Other SMTP / Gmail / SendGrid / Mailgun).</li>
					<li>Enter host, port, username, and password from your email provider.</li>
					<li>Click <strong>Send Test Email</strong> in WP Mail SMTP to verify.</li>
				</ol>
				<div class="cg-warn">⚠️ The From Email address <strong>must exactly match</strong> the account you authenticate with. Mismatch causes "Sender address rejected" bounces.</div>
				<h4>Hostinger / cPanel SMTP example</h4>
				<ul>
					<li>Host: <code>smtp.hostinger.com</code> (or your cPanel hostname)</li>
					<li>Port: <code>587</code> (TLS) or <code>465</code> (SSL)</li>
					<li>Username: full email address (e.g. <code>contact@yourdomain.com</code>)</li>
				</ul>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Email templates &amp; placeholders</summary>
			<div class="cg-section-body">
				<p>Configure per-entity email templates in <strong>Certificate Generator → Settings → Email</strong>.</p>
				<h4>Available placeholders</h4>
				<div class="cg-table-wrap"><table class="widefat striped cg-table">
					<thead><tr><th>Placeholder</th><th>Replaced with</th></tr></thead>
					<tbody>
						<?php
						$placeholders = array(
							'{name}'              => 'Recipient\'s full name',
							'{certificate_title}' => 'Certificate type / title',
							'{email}'             => 'Recipient\'s email address',
							'{serial_number}'     => 'Certificate serial number',
							'{expires_at}'        => 'Expiry date (or "Never")',
							'{certificate_count}' => 'Number of certificates in this send',
							'{site_name}'         => 'Your site name (Settings → General)',
							'{result_link}'       => 'Link to online results page',
							'{verify_link}'       => 'Link to certificate verification page',
							'{zip_link}'          => 'Download link (when ZIP > 25 MB)',
						);
						foreach ( $placeholders as $ph => $desc ) :
							?>
						<tr><td><code><?php echo esc_html( $ph ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
				<div class="cg-note">WordPress shortcodes (e.g. <code>[site_name]</code>) are processed after placeholder substitution, so you can mix both.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Sending bulk emails</summary>
			<div class="cg-section-body">
				<ol>
					<li>Go to <strong>Certificate Generator → Bulk Send</strong>.</li>
					<li>Use the filters to narrow by <em>School</em>, <em>Certificate Type</em>, or <em>Post Type</em>.</li>
					<li>Preview the recipient list.</li>
					<li>Choose whether to <em>skip already sent</em> (recommended — prevents duplicates).</li>
					<li>Click <strong>Send Certificates</strong>.</li>
				</ol>
				<div class="cg-note">Emails are processed via a background queue — the page doesn't need to stay open. Check <strong>Email Logs</strong> for delivery status.</div>
				<h4>How multiple certificates per recipient are handled</h4>
				<p>If a student has more than one certificate, all are bundled into a single ZIP file and attached to one email. This prevents inbox flooding.</p>
				<h4>Rate limits</h4>
				<p>Configurable in <strong>Settings → Rate Limits</strong>. Defaults: 60 emails/hour, 10 emails/minute. The queue processor respects these automatically.</p>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Email Logs</summary>
			<div class="cg-section-body">
				<p>Go to <strong>Certificate Generator → Email Logs</strong> to see every send attempt with:</p>
				<ul>
					<li>Recipient name &amp; email</li>
					<li>Certificate type</li>
					<li>Status: <code>sent</code> / <code>failed</code> / <code>pending</code></li>
					<li>Timestamp</li>
					<li>Error message (if failed)</li>
				</ul>
				<p>You can filter by status, date range, or search by email address. Logs are retained for 90 days then automatically cleaned up.</p>
			</div>
		</details>

		<?php /* ═══════════════════════  TROUBLESHOOTING  ══════════════════════════ */ ?>
	<?php elseif ( $active_tab === 'troubleshooting' ) : ?>

		<h2>Troubleshooting</h2>

		<details class="cg-section" open>
			<summary>Emails not being received</summary>
			<div class="cg-section-body">
				<ol>
					<li><strong>Check Email Logs</strong> — go to Email Logs and look for <code>failed</code> entries. The error message column shows the exact reason.</li>
					<li><strong>Verify SMTP config</strong> — open WP Mail SMTP → Tools → Email Test and send a test to your own address.</li>
					<li><strong>From Email mismatch</strong> — the most common cause. Your WP Mail SMTP <em>From Email</em> must exactly match the email address you authenticate with. Example: if your SMTP login is <code>contact@kidrove.com</code>, From Email must also be <code>contact@kidrove.com</code>.</li>
					<li><strong>Spam folder</strong> — check if emails are landing in spam. This usually means your domain lacks SPF/DKIM records.</li>
					<li><strong>Rate limit hit</strong> — if sending in bulk, the queue may be paused waiting for rate limits to reset. Check Email Logs for entries with error containing "rate limit".</li>
				</ol>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>"Successfully queued 0 certificates"</summary>
			<div class="cg-section-body">
				<p>This means the selected filter returned recipients, but no matching records were found in the certificate table.</p>
				<ol>
					<li>Go to <strong>Certificate Generator → Migration</strong> and run the data migration to populate the custom table from your CPT records.</li>
					<li>After migration, retry the bulk send.</li>
				</ol>
				<div class="cg-note">Run this SQL to check record count:<br><code>SELECT COUNT(*), SUM(email='') FROM wp_certificate_generator;</code><br>If <em>email</em> count is high, use the email backfill AJAX action.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Certificate PDF not generating</summary>
			<div class="cg-section-body">
				<ol>
					<li>Make sure the certificate template has <strong>all field positions set</strong> (X and Y coordinates for each visible field). A missing position causes generation to abort.</li>
					<li>Check that the WordPress uploads directory is writable: <code><?php echo esc_html( wp_upload_dir()['basedir'] ); ?></code></li>
					<li>Ensure the FPDF library is present at <code>lib/fpdf/fpdf.php</code> — it should be bundled with the plugin.</li>
					<li>Enable <code>WP_DEBUG</code> and <code>WP_DEBUG_LOG</code> in <code>wp-config.php</code> and check <code>wp-content/debug.log</code> for FPDF errors.</li>
				</ol>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>ZIP file not attaching / download link missing</summary>
			<div class="cg-section-body">
				<p>ZIP files are created when a recipient has <strong>more than one certificate</strong>. Requirements:</p>
				<ul>
					<li>PHP's <code>ZipArchive</code> extension must be enabled — check with your host if unsure.</li>
					<li>The uploads directory must be writable.</li>
					<li>If the ZIP exceeds 25 MB, it is not attached but a download link is included in the email body instead.</li>
				</ul>
				<div class="cg-note">Run <code>php -m | grep zip</code> via SSH or ask your host to confirm <code>ZipArchive</code> is enabled.</div>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Bulk send shows "No certificate records found"</summary>
			<div class="cg-section-body">
				<p>The custom table (<code>wp_certificate_generator</code>) has no rows for the filtered recipients.</p>
				<ol>
					<li>Run the migration page to copy existing CPT data to the custom table.</li>
					<li>Or import students via CSV (Bulk Import), which populates the table directly.</li>
				</ol>
			</div>
		</details>
		<hr class="cg-divider">

		<details class="cg-section">
			<summary>Debug mode</summary>
			<div class="cg-section-body">
				<p>To get detailed logs, add this to <code>wp-config.php</code>:</p>
				<pre class="cg-pre">define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );</pre>
				<p>Then check <code>wp-content/debug.log</code> after triggering an email send. All <code>[CG Email]</code> prefixed lines are from this plugin.</p>
			</div>
		</details>

		<?php /* ═══════════════════════  FAQ  ══════════════════════════════════════ */ ?>
	<?php elseif ( $active_tab === 'faq' ) : ?>

		<h2>Frequently Asked Questions</h2>

		<?php
		$faqs = array(
			'Can I send certificates to teachers and schools too, not just students?' =>
				'Yes. The bulk send page lets you choose the entity type (students / teachers / schools) before filtering. Each entity type has its own email template configurable in Settings → Email.',

			'Will re-sending skip students who already received their certificate?' =>
				'Yes — the "Skip already sent" checkbox (checked by default) checks the email log and skips any recipient who has a <code>sent</code> entry for their certificate ID.',

			'Can I use a Gmail account to send emails?'   =>
				'Yes, but Gmail requires an App Password (not your regular login). In WP Mail SMTP: choose Gmail as mailer, follow the OAuth flow or use SMTP with host <code>smtp.gmail.com</code>, port 587, and your App Password.',

			'How do I change the certificate PDF design?' =>
				'Edit the template in Certificate Generator → Templates. Upload a new background image and reposition the text fields. Future PDFs will use the updated design; previously generated PDFs are not changed.',

			'What happens if an email fails to send?'     =>
				'The queue retries up to 3 times with exponential backoff. After 3 failures the item is marked <code>failed</code> in the queue and logged in Email Logs with the error message. You can reset failed items from the queue management section.',

			'How long are email logs kept?'               =>
				'Logs are retained for 90 days. A weekly cron job automatically deletes older entries. You can change this by editing the <code>certificate_generator_cleanup_email_logs()</code> call in <code>includes/Email/log.php</code>.',

			'Can students verify their certificate online?' =>
				'Yes. Each certificate has a QR code that links to <code>/verify-certificate/</code>. Visitors can also search by serial number or email on the results page (<code>/result/</code>).',

			'Is the plugin multisite compatible?'         =>
				'Partially. The plugin is designed for single-site use. Multisite installs may work but custom table creation has not been formally tested across network sites.',

			'How do I back up all certificate data?'      =>
				'Use Bulk Export (CSV) to back up all records. The custom tables (<code>wp_certificate_generator</code>, <code>wp_cert_email_logs</code>) are also included in any full database backup.',

			'How do I update the plugin without losing data?' =>
				'Plugin updates only replace PHP/JS/CSS files — they do not drop or truncate database tables. Your certificate records, email logs, and settings are preserved across updates. Always take a database backup before major updates.',
		);
		foreach ( $faqs as $q => $a ) :
			?>
		<details class="cg-section">
			<summary><?php echo esc_html( $q ); ?></summary>
			<div class="cg-section-body"><p><?php echo wp_kses_post( $a ); ?></p></div>
		</details>
		<hr class="cg-divider">
		<?php endforeach; ?>

	<?php endif; ?>

	</div><!-- .cg-tab-content -->
	</div><!-- .cg-docs-wrap -->
	<?php
}
