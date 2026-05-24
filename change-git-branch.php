<?php
/**
 * Legacy entry point — redirects to the WordPress admin branch switcher.
 *
 * @package wp-git-branch
 */

require_once dirname( __DIR__, 3 ) . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to change git branches.', 'wp-git-branch' ) );
}

$folder = isset( $_GET['folderName'] ) ? sanitize_file_name( wp_unslash( $_GET['folderName'] ) ) : '';
$plugin = isset( $_GET['pluginName'] ) ? sanitize_text_field( wp_unslash( $_GET['pluginName'] ) ) : $folder;

wp_safe_redirect(
	add_query_arg(
		array(
			'page'      => 'wgb-change-branch',
			'folder'    => $folder,
			'plugin'    => rawurlencode( $plugin ),
			'TB_iframe' => 'true',
			'width'     => 600,
			'height'    => 500,
		),
		admin_url( 'admin.php' )
	)
);
exit;
