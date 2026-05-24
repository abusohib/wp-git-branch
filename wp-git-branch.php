<?php
/**
 * @package   	      Wordpress git
 * @contributors      Abu Sohib (Approve Me)
 * @wordpress-plugin
 * Plugin Name:       Wordpress git branch.
 * Plugin URI:        https://www.approveme.com
 * Description:       This plugin help you to display git branch information in plugins page for wordpress.
 * Version:           1.5
 * Author:            Abu Sohib
 * Author URI:        https://www.approveme.com
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

add_filter( 'plugin_row_meta', 'wgb_plugins_meta', 10, 4 );
add_action( 'admin_menu', 'wgb_register_admin_page' );
add_action( 'admin_post_wgb_git_checkout', 'wgb_handle_git_checkout' );
add_action( 'admin_post_wgb_git_create_branch', 'wgb_handle_git_create_branch' );

/**
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @return string Unix account that owns the plugin directory.
 */
function wgb_get_repo_owner_name( $plugin_dir ) {
	if ( ! function_exists( 'posix_getpwuid' ) ) {
		return '';
	}

	$info = posix_getpwuid( fileowner( $plugin_dir ) );

	return ( $info && ! empty( $info['name'] ) ) ? $info['name'] : '';
}

/**
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @return bool
 */
function wgb_git_dir_writable( $plugin_dir ) {
	$git_dir = $plugin_dir . '/.git';
	if ( ! is_dir( $git_dir ) ) {
		return false;
	}

	$lock_test = $git_dir . '/.wgb_write_test';
	$written   = @file_put_contents( $lock_test, '1' );

	if ( $written !== false ) {
		@unlink( $lock_test );
		return true;
	}

	return is_writable( $git_dir );
}

/**
 * Prefix for shell git commands when PHP cannot write to .git (runs as repo owner).
 *
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @return string e.g. "sudo -n -u 'abu' " or empty.
 */
function wgb_git_run_prefix( $plugin_dir ) {
	if ( wgb_git_dir_writable( $plugin_dir ) ) {
		return '';
	}

	$owner = apply_filters( 'wgb_git_run_as_user', wgb_get_repo_owner_name( $plugin_dir ), $plugin_dir );
	if ( $owner === '' || ! preg_match( '/^[a-z_][a-z0-9_-]*$/i', $owner ) ) {
		return '';
	}

	return 'sudo -n -u ' . escapeshellarg( $owner ) . ' ';
}

/**
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @param string $command    Git command arguments (without the "git" prefix).
 * @return string|null
 */
function wgb_git_exec( $plugin_dir, $command ) {
	if ( ! function_exists( 'shell_exec' ) ) {
		return null;
	}

	$plugin_dir = realpath( $plugin_dir );
	if ( ! $plugin_dir || ! is_dir( $plugin_dir . '/.git' ) ) {
		return null;
	}

	// Web server user often differs from repo owner (Git "dubious ownership").
	$git    = 'git -c ' . escapeshellarg( 'safe.directory=' . $plugin_dir );
	$prefix = wgb_git_run_prefix( $plugin_dir );
	$shell  = $prefix . 'cd ' . escapeshellarg( $plugin_dir ) . ' && ' . $git . ' ' . $command . ' 2>&1';

	return shell_exec( $shell );
}

/**
 * User-facing hint when git cannot write to the repository.
 *
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @return string
 */
function wgb_permission_help_message( $plugin_dir ) {
	$owner = wgb_get_repo_owner_name( $plugin_dir );

	return sprintf(
		/* translators: 1: plugin path, 2: repo owner username */
		__(
			'The web server (www-data) cannot write to this repository. On the server run: sudo bash %3$s/fix-git-permissions.sh %1$s — or install %3$s/sudoers.example for user %2$s.',
			'wp-git-branch'
		),
		$plugin_dir,
		$owner !== '' ? $owner : 'REPO_OWNER',
		dirname( __FILE__ )
	);
}

/**
 * @param string $folder  Plugin folder slug.
 * @param string $message Notice text.
 * @param string $kind    Transient kind: error or success.
 */
function wgb_set_flash( $folder, $message, $kind = 'error' ) {
	$key = 'wgb_' . $kind . '_' . get_current_user_id() . '_' . md5( $folder );
	set_transient( $key, $message, MINUTE_IN_SECONDS );
}

/**
 * @param string $folder Plugin folder slug.
 * @param string $kind   Transient kind: error or success.
 * @return string
 */
function wgb_get_flash( $folder, $kind = 'error' ) {
	$key     = 'wgb_' . $kind . '_' . get_current_user_id() . '_' . md5( $folder );
	$message = get_transient( $key );
	if ( is_string( $message ) && $message !== '' ) {
		delete_transient( $key );
		return $message;
	}
	return '';
}

/**
 * @param string $folder Plugin folder slug.
 * @param string $message Error text.
 */
function wgb_set_flash_error( $folder, $message ) {
	wgb_set_flash( $folder, $message, 'error' );
}

/**
 * @param string $folder Plugin folder slug.
 * @return string
 */
function wgb_get_flash_error( $folder ) {
	return wgb_get_flash( $folder, 'error' );
}

/**
 * @param string $name Proposed branch name.
 * @return true|string True if valid, otherwise an error message.
 */
function wgb_validate_branch_name( $name ) {
	$name = trim( $name );

	if ( $name === '' ) {
		return __( 'Branch name is required.', 'wp-git-branch' );
	}

	if ( strlen( $name ) > 255 ) {
		return __( 'Branch name is too long.', 'wp-git-branch' );
	}

	if ( str_contains( $name, '..' ) || str_ends_with( $name, '/' ) || str_ends_with( $name, '.' ) ) {
		return __( 'Invalid branch name.', 'wp-git-branch' );
	}

	if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._\/-]*$/', $name ) ) {
		return __( 'Use letters, numbers, and / - _ . only. Name must start with a letter or number.', 'wp-git-branch' );
	}

	return true;
}

/**
 * @param string $folder      Plugin folder slug.
 * @param string $plugin_name Plugin display name.
 * @param string $notice      success|error
 */
function wgb_redirect_to_branch_modal( $folder, $plugin_name, $notice = '' ) {
	$args = array(
		'page'      => 'wgb-change-branch',
		'folder'    => $folder,
		'plugin'    => rawurlencode( $plugin_name ),
		'TB_iframe' => 'true',
		'width'     => 600,
		'height'    => 620,
	);

	if ( $notice !== '' ) {
		$args['wgb_notice'] = $notice;
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Store or retrieve the last branch-list error message.
 *
 * @param string|null $message Set message, or null to read.
 * @return string
 */
function wgb_branch_list_error( $message = null ) {
	static $error = '';
	if ( $message !== null ) {
		$error = $message;
	}
	return $error;
}

/**
 * @param string $output Raw git branch command output.
 * @return bool
 */
function wgb_is_git_error_output( $output ) {
	return (bool) preg_match( '/^fatal:/mi', $output );
}

/**
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @return string
 */
function wgb_get_current_branch( $plugin_dir ) {
	$head_file = $plugin_dir . '/.git/HEAD';
	if ( ! file_exists( $head_file ) ) {
		return '';
	}

	$git_str = file_get_contents( $head_file );
	return rtrim( preg_replace( '/(.*?\/){2}/', '', $git_str ) );
}

/**
 * Parse newline-separated git ref output into branch names.
 *
 * @param string $output Raw git output.
 * @return string[]
 */
function wgb_parse_branch_lines( $output ) {
	$branches = array();
	foreach ( explode( "\n", trim( $output ) ) as $line ) {
		$line = trim( $line );
		if ( $line === '' || preg_match( '/^(fatal|error|hint):/i', $line ) ) {
			continue;
		}
		$branches[] = $line;
	}
	return $branches;
}

/**
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @param string $branch     Branch short name.
 * @return bool
 */
function wgb_branch_exists( $plugin_dir, $branch ) {
	$output = wgb_git_exec( $plugin_dir, 'rev-parse --verify ' . escapeshellarg( 'refs/heads/' . $branch ) );
	return is_string( $output ) && $output !== '' && ! wgb_is_git_error_output( $output );
}

/**
 * Most recently updated local branches (for UI lists).
 *
 * @param string $plugin_dir Absolute path to the plugin directory.
 * @param int    $limit      Max branches to return.
 * @return string[]
 */
function wgb_get_local_branches( $plugin_dir, $limit = 0 ) {
	wgb_branch_list_error( '' );

	if ( $limit <= 0 ) {
		$limit = (int) apply_filters( 'wgb_branch_list_limit', 5 );
	}

	$output = wgb_git_exec(
		$plugin_dir,
		'for-each-ref --sort=-committerdate --count=' . (int) $limit . ' --format="%(refname:short)" refs/heads/'
	);

	if ( $output === null || $output === '' ) {
		return array();
	}

	if ( wgb_is_git_error_output( $output ) ) {
		wgb_branch_list_error( trim( preg_replace( '/\s+/', ' ', $output ) ) );
		return array();
	}

	$branches = wgb_parse_branch_lines( $output );
	$current  = wgb_get_current_branch( $plugin_dir );

	if ( $current !== '' && ! in_array( $current, $branches, true ) ) {
		array_unshift( $branches, $current );
		$branches = array_slice( array_values( array_unique( $branches ) ), 0, $limit );
	}

	return $branches;
}

/**
 * @param string $plugin_folder Plugin folder slug under wp-content/plugins.
 * @return string|false
 */
function wgb_get_plugin_dir( $plugin_folder ) {
	$plugin_folder = sanitize_file_name( $plugin_folder );
	if ( $plugin_folder === '' ) {
		return false;
	}

	$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_folder;
	if ( ! is_dir( $plugin_dir ) || ! is_dir( $plugin_dir . '/.git' ) ) {
		return false;
	}

	return $plugin_dir;
}

function wgb_register_admin_page() {
	add_submenu_page(
		null,
		__( 'Change Git Branch', 'wp-git-branch' ),
		__( 'Change Git Branch', 'wp-git-branch' ),
		'manage_options',
		'wgb-change-branch',
		'wgb_render_change_branch_page'
	);
}

function wgb_render_change_branch_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change git branches.', 'wp-git-branch' ) );
	}

	$folder       = isset( $_GET['folder'] ) ? sanitize_file_name( wp_unslash( $_GET['folder'] ) ) : '';
	$plugin_name  = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : $folder;
	$plugin_dir   = wgb_get_plugin_dir( $folder );
	$notice     = isset( $_GET['wgb_notice'] ) ? sanitize_key( wp_unslash( $_GET['wgb_notice'] ) ) : '';
	$notice_msg    = wgb_get_flash_error( $folder );
	$success_flash = wgb_get_flash( $folder, 'success' );
	if ( $notice_msg === '' && isset( $_GET['wgb_message'] ) ) {
		$notice_msg = sanitize_text_field( wp_unslash( $_GET['wgb_message'] ) );
	}

	if ( ! $plugin_dir ) {
		wp_die( esc_html__( 'This plugin is not a git repository.', 'wp-git-branch' ) );
	}

	$current_branch = wgb_get_current_branch( $plugin_dir );
	$branches       = wgb_get_local_branches( $plugin_dir );
	$git_error      = wgb_branch_list_error();

	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<title><?php echo esc_html( $plugin_name ); ?> — <?php esc_html_e( 'Change Git Branch', 'wp-git-branch' ); ?></title>
		<?php wp_admin_css( 'common', true ); ?>
		<style>
			body { padding: 16px 20px; background: #fff; }
			.wgb-branch-list { margin: 16px 0; }
			.wgb-branch-list label { display: block; margin: 6px 0; cursor: pointer; }
			.wgb-branch-list input { margin-right: 8px; }
			.wgb-current { color: #2271b1; font-weight: 600; }
			.wgb-actions { margin-top: 20px; }
			.wgb-section { margin-top: 24px; padding-top: 20px; border-top: 1px solid #c3c4c7; }
			.wgb-section h2 { margin: 0 0 12px; font-size: 14px; }
			.wgb-field { margin-bottom: 12px; }
			.wgb-field label { display: block; margin-bottom: 4px; font-weight: 600; }
			.wgb-field input[type="text"],
			.wgb-field select { width: 100%; max-width: 100%; box-sizing: border-box; }
			.wgb-hint { color: #646970; font-size: 12px; margin-top: 4px; }
		</style>
	</head>
	<body>
		<h1><?php echo esc_html( $plugin_name ); ?></h1>

		<?php if ( $notice === 'success' ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $success_flash !== '' ? $success_flash : __( 'Branch switched successfully.', 'wp-git-branch' ) ); ?></p></div>
		<?php elseif ( $notice === 'error' && $notice_msg !== '' ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $notice_msg ); ?></p></div>
			<?php if ( stripos( $notice_msg, 'permission denied' ) !== false ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( wgb_permission_help_message( $plugin_dir ) ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>

		<p>
			<?php esc_html_e( 'Current branch:', 'wp-git-branch' ); ?>
			<span class="wgb-current"><?php echo esc_html( $current_branch ); ?></span>
		</p>

		<?php if ( empty( $branches ) ) : ?>
			<?php if ( $git_error !== '' ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $git_error ); ?></p></div>
			<?php else : ?>
				<p><?php esc_html_e( 'No local branches found. Run git fetch in the plugin directory first.', 'wp-git-branch' ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wgb_git_checkout', 'wgb_nonce' ); ?>
				<input type="hidden" name="action" value="wgb_git_checkout">
				<input type="hidden" name="folder" value="<?php echo esc_attr( $folder ); ?>">
				<input type="hidden" name="plugin" value="<?php echo esc_attr( $plugin_name ); ?>">
				<input type="hidden" name="TB_iframe" value="1">

				<div class="wgb-branch-list">
					<p><strong><?php esc_html_e( 'Switch to branch:', 'wp-git-branch' ); ?></strong></p>
					<p class="wgb-hint">
						<?php
						printf(
							/* translators: %d: number of branches shown */
							esc_html__( 'Showing the %d most recently updated branches.', 'wp-git-branch' ),
							(int) apply_filters( 'wgb_branch_list_limit', 5 )
						);
						?>
					</p>
					<?php foreach ( $branches as $branch ) : ?>
						<label>
							<input type="radio" name="branch" value="<?php echo esc_attr( $branch ); ?>" <?php checked( $branch, $current_branch ); ?>>
							<?php echo esc_html( $branch ); ?>
							<?php if ( $branch === $current_branch ) : ?>
								<em>(<?php esc_html_e( 'current', 'wp-git-branch' ); ?>)</em>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="wgb-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Checkout branch', 'wp-git-branch' ); ?></button>
				</p>
			</form>

			<div class="wgb-section">
				<h2><?php esc_html_e( 'Create new branch', 'wp-git-branch' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wgb_git_create_branch', 'wgb_nonce' ); ?>
					<input type="hidden" name="action" value="wgb_git_create_branch">
					<input type="hidden" name="folder" value="<?php echo esc_attr( $folder ); ?>">
					<input type="hidden" name="plugin" value="<?php echo esc_attr( $plugin_name ); ?>">
					<input type="hidden" name="TB_iframe" value="1">

					<div class="wgb-field">
						<label for="wgb-new-branch"><?php esc_html_e( 'New branch name', 'wp-git-branch' ); ?></label>
						<input type="text" id="wgb-new-branch" name="new_branch" value="" placeholder="feature/my-change" autocomplete="off" required>
						<p class="wgb-hint"><?php esc_html_e( 'Example: feature/redesign or hotfix/login-bug', 'wp-git-branch' ); ?></p>
					</div>

					<div class="wgb-field">
						<label for="wgb-base-branch"><?php esc_html_e( 'Branch off from', 'wp-git-branch' ); ?></label>
						<select id="wgb-base-branch" name="base_branch" required>
							<?php foreach ( $branches as $branch ) : ?>
								<option value="<?php echo esc_attr( $branch ); ?>" <?php selected( $branch, $current_branch ); ?>>
									<?php echo esc_html( $branch ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<p class="wgb-actions">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Create & checkout branch', 'wp-git-branch' ); ?></button>
					</p>
				</form>
			</div>
		<?php endif; ?>
	</body>
	</html>
	<?php
	exit;
}

function wgb_handle_git_checkout() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change git branches.', 'wp-git-branch' ) );
	}

	check_admin_referer( 'wgb_git_checkout', 'wgb_nonce' );

	$folder      = isset( $_POST['folder'] ) ? sanitize_file_name( wp_unslash( $_POST['folder'] ) ) : '';
	$plugin_name = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : $folder;
	$branch      = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';
	$plugin_dir  = wgb_get_plugin_dir( $folder );

	if ( ! $plugin_dir || $branch === '' ) {
		wgb_set_flash_error( $folder, __( 'Invalid plugin or branch.', 'wp-git-branch' ) );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	$allowed = wgb_get_local_branches( $plugin_dir );
	if ( ! in_array( $branch, $allowed, true ) ) {
		wgb_set_flash_error( $folder, __( 'That branch does not exist locally.', 'wp-git-branch' ) );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	$current = wgb_get_current_branch( $plugin_dir );
	if ( $branch === $current ) {
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'success' );
	}

	$output = wgb_git_exec( $plugin_dir, 'checkout ' . escapeshellarg( $branch ) );
	$new    = wgb_get_current_branch( $plugin_dir );

	if ( $new === $branch ) {
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'success' );
	}

	$message = $output ? trim( $output ) : __( 'Git checkout failed. Check file permissions or uncommitted changes.', 'wp-git-branch' );
	wgb_set_flash_error( $folder, $message );
	wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
}

function wgb_handle_git_create_branch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to change git branches.', 'wp-git-branch' ) );
	}

	check_admin_referer( 'wgb_git_create_branch', 'wgb_nonce' );

	$folder      = isset( $_POST['folder'] ) ? sanitize_file_name( wp_unslash( $_POST['folder'] ) ) : '';
	$plugin_name = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : $folder;
	$new_branch  = isset( $_POST['new_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['new_branch'] ) ) : '';
	$base_branch = isset( $_POST['base_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['base_branch'] ) ) : '';
	$plugin_dir  = wgb_get_plugin_dir( $folder );

	if ( ! $plugin_dir ) {
		wgb_set_flash_error( $folder, __( 'Invalid plugin.', 'wp-git-branch' ) );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	$valid_name = wgb_validate_branch_name( $new_branch );
	if ( $valid_name !== true ) {
		wgb_set_flash_error( $folder, $valid_name );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	$allowed = wgb_get_local_branches( $plugin_dir );
	if ( ! in_array( $base_branch, $allowed, true ) ) {
		wgb_set_flash_error( $folder, __( 'Base branch does not exist locally.', 'wp-git-branch' ) );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	if ( wgb_branch_exists( $plugin_dir, $new_branch ) ) {
		wgb_set_flash_error( $folder, __( 'A branch with that name already exists.', 'wp-git-branch' ) );
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
	}

	$output = wgb_git_exec(
		$plugin_dir,
		'checkout -b ' . escapeshellarg( $new_branch ) . ' ' . escapeshellarg( $base_branch )
	);
	$current = wgb_get_current_branch( $plugin_dir );

	if ( $current === $new_branch ) {
		wgb_set_flash(
			$folder,
			sprintf(
				/* translators: 1: new branch name, 2: base branch name */
				__( 'Created and checked out branch "%1$s" from "%2$s".', 'wp-git-branch' ),
				$new_branch,
				$base_branch
			),
			'success'
		);
		wgb_redirect_to_branch_modal( $folder, $plugin_name, 'success' );
	}

	$message = $output ? trim( $output ) : __( 'Could not create branch. Check permissions or uncommitted changes.', 'wp-git-branch' );
	wgb_set_flash_error( $folder, $message );
	wgb_redirect_to_branch_modal( $folder, $plugin_name, 'error' );
}

function wgb_plugins_meta( $plugin_meta, $plugin_file, $plugin_data, $status ) {
	$file_info = explode( '/', $plugin_file );
	$plugin_dir = WP_PLUGIN_DIR . '/' . $file_info[0];

	if ( ! is_dir( $plugin_dir . '/.git' ) ) {
		return $plugin_meta;
	}

	$git_branch_name = wgb_get_current_branch( $plugin_dir );

	$plugin_meta[] = '<span style="color:red;font-weight:bold;">' . esc_html__( 'Git Branch:', 'wp-git-branch' ) . '</span> ' . esc_html( $git_branch_name );

	$change_url = add_query_arg(
		array(
			'page'      => 'wgb-change-branch',
			'folder'    => $file_info[0],
			'plugin'    => rawurlencode( $plugin_data['Name'] ),
			'TB_iframe' => 'true',
			'width'     => 600,
			'height'    => 620,
		),
		admin_url( 'admin.php' )
	);

	$plugin_meta[] = '<a class="thickbox" href="' . esc_url( $change_url ) . '" aria-label="' . esc_attr__( 'Change git branch', 'wp-git-branch' ) . '">' . esc_html__( 'Change Branch', 'wp-git-branch' ) . '</a>';

	return $plugin_meta;
}
