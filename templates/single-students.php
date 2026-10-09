<?php
/**
 * Single Student Certificate Profile Page
 * Loaded via 'single_template' filter in includes/single-student-template.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) : the_post();

    $post_id          = get_the_ID();
    $student_name     = get_post_meta( $post_id, 'student_name', true ) ?: get_the_title();
    $school_name      = get_post_meta( $post_id, 'school_name', true );
    $issue_date_raw   = get_post_meta( $post_id, 'issue_date', true );
    $certificate_type = get_post_meta( $post_id, 'certificate_type', true );

    // Format date for display
    $display_date = $issue_date_raw ? cg_format_date($issue_date_raw) : '';

    // Nonce-protected download URL (valid ~12 h)
    $download_url = '';
    if ( $certificate_type && function_exists( 'generate_certificate_pdf_with_data' ) ) {
        $download_url = add_query_arg( [
            'cg_download_cert' => '1',
            'id'               => $post_id,
            'nonce'            => wp_create_nonce( 'cg_download_cert_' . $post_id ),
        ], get_permalink( $post_id ) );
    }
    ?>
    <style>
    .cg-profile-wrap {
        max-width: 680px;
        margin: 56px auto 72px;
        padding: 0 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    }
    .cg-profile-card {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 16px;
        padding: 60px 48px 48px;
        text-align: center;
        box-shadow: 0 2px 16px rgba(0,0,0,.06), 0 0 0 1px rgba(0,0,0,.03);
        position: relative;
        overflow: hidden;
    }
    .cg-profile-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #2563eb 0%, #0d9488 50%, #2563eb 100%);
    }
    .cg-seal {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 72px;
        height: 72px;
        background: #ecfdf5;
        border: 2px solid #6ee7b7;
        border-radius: 50%;
        margin-bottom: 20px;
    }
    .cg-seal svg {
        width: 32px;
        height: 32px;
        color: #059669;
    }
    .cg-verified-tag {
        display: inline-block;
        background: #ecfdf5;
        color: #065f46;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        padding: 4px 12px;
        border-radius: 100px;
        margin-bottom: 28px;
    }
    .cg-name {
        font-size: 2rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
        margin: 0 0 10px;
        word-break: break-word;
    }
    .cg-awarded {
        font-size: .95rem;
        color: #64748b;
        margin: 0 0 6px;
    }
    .cg-cert-type {
        font-size: 1.25rem;
        font-weight: 600;
        color: #2563eb;
        margin: 0 0 36px;
    }
    .cg-meta-row {
        display: flex;
        justify-content: center;
        gap: 48px;
        flex-wrap: wrap;
        padding: 24px 0;
        border-top: 1px solid #f1f5f9;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 36px;
    }
    .cg-meta-item { text-align: center; min-width: 120px; }
    .cg-meta-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #94a3b8;
        display: block;
        margin-bottom: 5px;
    }
    .cg-meta-value {
        font-size: .9rem;
        font-weight: 500;
        color: #334155;
        line-height: 1.4;
    }
    .cg-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 13px 28px;
        background: #2563eb;
        color: #fff !important;
        text-decoration: none !important;
        border-radius: 8px;
        font-size: .9rem;
        font-weight: 600;
        letter-spacing: .01em;
        transition: background .15s;
    }
    .cg-btn:hover { background: #1d4ed8; }
    .cg-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    @media (max-width: 540px) {
        .cg-profile-card { padding: 44px 24px 36px; }
        .cg-name { font-size: 1.5rem; }
        .cg-meta-row { gap: 28px; }
    }
    </style>

    <div class="cg-profile-wrap">
        <div class="cg-profile-card">

            <div class="cg-seal">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>

            <span class="cg-verified-tag">Certificate Verified</span>

            <h1 class="cg-name"><?php echo esc_html( $student_name ); ?></h1>
            <p class="cg-awarded">has been awarded the</p>
            <p class="cg-cert-type"><?php echo esc_html( $certificate_type ?: 'Certificate of Achievement' ); ?></p>

            <div class="cg-meta-row">
                <?php if ( $school_name ) : ?>
                <div class="cg-meta-item">
                    <span class="cg-meta-label">Institution</span>
                    <span class="cg-meta-value"><?php echo esc_html( $school_name ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $display_date ) : ?>
                <div class="cg-meta-item">
                    <span class="cg-meta-label">Issue Date</span>
                    <span class="cg-meta-value"><?php echo esc_html( $display_date ); ?></span>
                </div>
                <?php endif; ?>

                <?php
                // Render extra fields for this student's certificate type
                if ( class_exists( 'CG_Field_Schema' ) ) {
                    foreach ( CG_Field_Schema::get_extra_fields( $certificate_type ) as $slug ) {
                        $extra_value = get_post_meta( $post_id, $slug, true );
                        if ( ! empty( $extra_value ) ) : ?>
                        <div class="cg-meta-item">
                            <span class="cg-meta-label"><?php echo esc_html( CG_Field_Schema::get_display_label( $slug ) ); ?></span>
                            <span class="cg-meta-value"><?php echo esc_html( $extra_value ); ?></span>
                        </div>
                        <?php endif;
                    }
                }
                ?>
            </div>

            <?php if ( $download_url ) : ?>
            <a href="<?php echo esc_url( $download_url ); ?>" class="cg-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Download Certificate (PDF)
            </a>
            <?php endif; ?>

        </div>
    </div>

    <?php
endwhile;

get_footer();
