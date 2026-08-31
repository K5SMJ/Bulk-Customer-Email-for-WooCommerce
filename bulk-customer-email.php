<?php
/**
 * Plugin Name: Bulk Customer Email
 * Plugin URI:  https://github.com/K5SMJ/Bulk_Customer_Email
 * Description: Send HTML bulk emails directly to your WordPress/WooCommerce users by role — no external service required.
 * Version:     1.0.0
 * Author:      K5SMJ
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bulk-customer-email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCE_VERSION', '1.0.0' );
define( 'BCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ──────────────────────────────────────────────
// Admin menu
// ──────────────────────────────────────────────

add_action( 'admin_menu', 'bce_register_menu' );

function bce_register_menu() {
	add_menu_page(
		__( 'Bulk Customer Email', 'bulk-customer-email' ),
		__( 'Bulk Email', 'bulk-customer-email' ),
		'manage_options',
		'bulk-customer-email',
		'bce_render_admin_page',
		'dashicons-email-alt',
		30
	);
}

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

/**
 * Return every WordPress role slug => label pair available on the site.
 *
 * @return array<string,string>
 */
function bce_get_roles() {
	$roles  = [];
	$wp_roles = wp_roles();
	foreach ( $wp_roles->roles as $slug => $data ) {
		$roles[ $slug ] = translate_user_role( $data['name'] );
	}
	return $roles;
}

/**
 * Fetch user e-mail addresses for a given role.
 * Pass 'all' to target every user.
 *
 * @param string $role
 * @return string[]
 */
function bce_get_emails_by_role( $role ) {
	$args = [
		'fields' => [ 'user_email' ],
		'number' => -1,
	];

	if ( 'all' !== $role ) {
		$args['role'] = sanitize_key( $role );
	}

	$users  = get_users( $args );
	$emails = [];
	foreach ( $users as $user ) {
		if ( is_email( $user->user_email ) ) {
			$emails[] = $user->user_email;
		}
	}
	return array_unique( $emails );
}

// ──────────────────────────────────────────────
// Send action
// ──────────────────────────────────────────────

add_action( 'admin_post_bce_send_email', 'bce_handle_send' );

function bce_handle_send() {
	// Capability check.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'bulk-customer-email' ) );
	}

	// Nonce verification.
	check_admin_referer( 'bce_send_email_action', 'bce_nonce' );

	$role    = isset( $_POST['bce_role'] ) ? sanitize_key( $_POST['bce_role'] ) : 'all';
	$subject = isset( $_POST['bce_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['bce_subject'] ) ) : '';
	$message = isset( $_POST['bce_message'] ) ? wp_kses_post( wp_unslash( $_POST['bce_message'] ) ) : '';

	if ( empty( $subject ) || empty( $message ) ) {
		wp_safe_redirect(
			add_query_arg( [ 'page' => 'bulk-customer-email', 'bce_status' => 'missing_fields' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	$emails = bce_get_emails_by_role( $role );

	if ( empty( $emails ) ) {
		wp_safe_redirect(
			add_query_arg( [ 'page' => 'bulk-customer-email', 'bce_status' => 'no_users' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	$sent  = 0;
	$from  = get_option( 'admin_email' );
	$name  = get_option( 'blogname' );
	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $name . ' <' . $from . '>',
	];

	foreach ( $emails as $email ) {
		if ( wp_mail( $email, $subject, $message, $headers ) ) {
			$sent++;
		}
	}

	wp_safe_redirect(
		add_query_arg(
			[
				'page'       => 'bulk-customer-email',
				'bce_status' => 'sent',
				'bce_count'  => $sent,
				'bce_total'  => count( $emails ),
			],
			admin_url( 'admin.php' )
		)
	);
	exit;
}

// ──────────────────────────────────────────────
// Admin page render
// ──────────────────────────────────────────────

function bce_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$roles   = bce_get_roles();
	$status  = isset( $_GET['bce_status'] ) ? sanitize_key( $_GET['bce_status'] ) : '';
	$count   = isset( $_GET['bce_count'] )  ? absint( $_GET['bce_count'] )         : 0;
	$total   = isset( $_GET['bce_total'] )  ? absint( $_GET['bce_total'] )         : 0;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Bulk Customer Email', 'bulk-customer-email' ); ?></h1>

		<?php if ( 'sent' === $status ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: emails sent, 2: total recipients */
						esc_html__( 'Done! Sent %1$d of %2$d email(s).', 'bulk-customer-email' ),
						$count,
						$total
					);
					?>
				</p>
			</div>
		<?php elseif ( 'no_users' === $status ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'No users found for the selected role.', 'bulk-customer-email' ); ?></p>
			</div>
		<?php elseif ( 'missing_fields' === $status ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Please fill in both the subject and message fields.', 'bulk-customer-email' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bce_send_email_action', 'bce_nonce' ); ?>
			<input type="hidden" name="action" value="bce_send_email">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bce_role"><?php esc_html_e( 'Send To (Role)', 'bulk-customer-email' ); ?></label>
					</th>
					<td>
						<select name="bce_role" id="bce_role">
							<option value="all"><?php esc_html_e( '— All Users —', 'bulk-customer-email' ); ?></option>
							<?php foreach ( $roles as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select a role to limit recipients, or choose "All Users" to email everyone.', 'bulk-customer-email' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bce_subject"><?php esc_html_e( 'Subject', 'bulk-customer-email' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							name="bce_subject"
							id="bce_subject"
							class="regular-text"
							required
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bce_message"><?php esc_html_e( 'Message (HTML allowed)', 'bulk-customer-email' ); ?></label>
					</th>
					<td>
						<?php
						wp_editor(
							'',
							'bce_message',
							[
								'textarea_name' => 'bce_message',
								'textarea_rows' => 15,
								'media_buttons' => false,
							]
						);
						?>
						<p class="description">
							<?php esc_html_e( 'You may use full HTML formatting. The email will be sent as text/html.', 'bulk-customer-email' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Send Emails', 'bulk-customer-email' ) ); ?>
		</form>
	</div>
	<?php
}
