<?php
/**
 * Plugin Name: Bulk Customer Email for WooCommerce
 * Description: Send bulk emails to all WooCommerce customers with batched sending
 * Version: 1.2.4
 * Requires Plugins: woocommerce
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined('ABSPATH') ) { exit; }

// Conservative sending defaults for shared hosting. Apply once when this
// plugin version is first loaded; an active campaign keeps its own saved rate.
if ( get_option('pmbulk_safety_defaults_version', '') !== '1.1.0' ) {
    update_option('pmbulk_batch_size', 5);
    update_option('pmbulk_batch_interval', 90);
    update_option('pmbulk_safety_defaults_version', '1.1.0');
}

// Get configured brand logo URL
function pmbulk_get_logo_url() {
    $logo_id = (int) get_option('pmbulk_logo_id', 0);
    return $logo_id ? wp_get_attachment_url($logo_id) : '';
}

// Get configurable email/brand settings with WordPress-safe defaults.
function pmbulk_get_brand_settings() {
    $site_name = get_bloginfo('name');
    $site_url  = home_url('/');
    $admin_email = get_option('admin_email');

    $brand_name = sanitize_text_field(get_option('pmbulk_brand_name', ''));
    $brand_url  = esc_url_raw(get_option('pmbulk_brand_url', ''));
    $from_name  = sanitize_text_field(get_option('pmbulk_from_name', ''));
    $from_email = sanitize_email(get_option('pmbulk_from_email', ''));
    $footer     = sanitize_text_field(get_option('pmbulk_footer_blurb', ''));

    return array(
        'brand_name'   => $brand_name !== '' ? $brand_name : $site_name,
        'brand_url'    => $brand_url !== '' ? $brand_url : $site_url,
        'from_name'    => $from_name !== '' ? $from_name : $site_name,
        'from_email'   => is_email($from_email) ? $from_email : $admin_email,
        'footer_blurb' => $footer !== '' ? $footer : sprintf('You received this email because you are a customer or user of %s.', $site_name),
    );
}

// Add admin menu
add_action('admin_menu', 'pmbulk_add_menu');
function pmbulk_add_menu() {
    add_menu_page(
        'Bulk Customer Email',
        'Bulk Email',
        'manage_woocommerce',
        'pmbulk-email',
        'pmbulk_admin_page',
        'dashicons-email-alt',
        56
    );
}

// Normalize recipient scope from user input/options
function pmbulk_normalize_recipient_scope($scope) {
    return in_array($scope, array('all_users', 'non_customers'), true) ? $scope : 'customers';
}

// Human-readable label for selected recipient scope
function pmbulk_get_recipient_scope_label($scope) {
    
    $scope = pmbulk_normalize_recipient_scope($scope);
    if ( $scope === 'all_users' ) {
        return 'all users';
    }
    if ( $scope === 'non_customers' ) {
        return 'non-customers';
    }
    return 'customers with orders';
}

// Sanitize bulk batch size setting
function pmbulk_sanitize_batch_size($value) {
    $value = (int) $value;
    if ( $value < 1 ) {
        return 1;
    }
    if ( $value > 20 ) {
        return 50;
    }
    return $value;
}

// Sanitize bulk interval setting (seconds)
function pmbulk_sanitize_batch_interval($value) {
    $value = (int) $value;
    if ( $value < 60 ) {
        return 15;
    }
    if ( $value > 1800 ) {
        return 900;
    }
    return $value;
}

// Format timestamp for campaign logs
function pmbulk_format_campaign_time($timestamp) {
    if ( empty($timestamp) ) {
        return 'n/a';
    }

    return wp_date('Y-m-d H:i:s', (int) $timestamp);
}

// Get stored campaign history list
function pmbulk_get_campaign_history() {
    $history = get_option('pmbulk_campaign_history', array());
    return is_array($history) ? $history : array();
}

// Save campaign history while keeping only the most recent campaigns
function pmbulk_save_campaign_history($history) {
    $history = is_array($history) ? array_values($history) : array();
    $max_campaigns = 20;

    if ( count($history) > $max_campaigns ) {
        $history = array_slice($history, 0, $max_campaigns);
    }

    update_option('pmbulk_campaign_history', $history);
}

// Get saved draft history
function pmbulk_get_draft_history() {
    $history = get_option('pmbulk_draft_history', array());
    return is_array($history) ? $history : array();
}

// Save draft history while keeping only recent drafts
function pmbulk_save_draft_history($history) {
    $history = is_array($history) ? array_values($history) : array();
    $max_drafts = 30;

    if ( count($history) > $max_drafts ) {
        $history = array_slice($history, 0, $max_drafts);
    }

    update_option('pmbulk_draft_history', $history);
}

// Add a new draft snapshot to draft history
function pmbulk_add_draft_snapshot($subject, $message, $scope) {
    $draft = array(
        'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('pmdraft_', true),
        'saved_at' => current_time('timestamp'),
        'scope' => pmbulk_normalize_recipient_scope($scope),
        'subject' => sanitize_text_field($subject),
        'message' => wp_kses_post($message),
    );

    $history = pmbulk_get_draft_history();
    array_unshift($history, $draft);
    pmbulk_save_draft_history($history);
}

// Load one draft by id
function pmbulk_get_draft_by_id($draft_id) {
    $draft_id = sanitize_text_field($draft_id);
    $history = pmbulk_get_draft_history();

    foreach ( $history as $draft ) {
        if ( isset($draft['id']) && $draft['id'] === $draft_id ) {
            return $draft;
        }
    }

    return null;
}

// Delete one draft by id
function pmbulk_delete_draft_by_id($draft_id) {
    $draft_id = sanitize_text_field($draft_id);
    $history = pmbulk_get_draft_history();
    $updated_history = array();
    $deleted = false;

    foreach ( $history as $draft ) {
        if ( isset($draft['id']) && $draft['id'] === $draft_id ) {
            $deleted = true;
            continue;
        }
        $updated_history[] = $draft;
    }

    if ( $deleted ) {
        pmbulk_save_draft_history($updated_history);
    }

    return $deleted;
}

// Initialize active campaign tracking data
function pmbulk_start_campaign_tracking($scope, $subject, $message, $total, $batch_size = 5, $batch_interval = 90) {
    $campaign = array(
        'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('pmbulk_', true),
        'started_at' => current_time('timestamp'),
        'finished_at' => 0,
        'scope' => pmbulk_normalize_recipient_scope($scope),
        'scope_label' => pmbulk_get_recipient_scope_label($scope),
        'subject' => $subject,
        'message' => wp_kses_post($message),
        'total' => (int) $total,
        'batch_size' => pmbulk_sanitize_batch_size($batch_size),
        'batch_interval' => pmbulk_sanitize_batch_interval($batch_interval),
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'status' => 'running',
        'recipients' => array(),
    );

    update_option('pmbulk_active_campaign_log', $campaign);
}

// Append recipient delivery result to active campaign log
function pmbulk_track_campaign_recipient($email, $was_sent) {
    $campaign = get_option('pmbulk_active_campaign_log', array());
    if ( empty($campaign) || ! is_array($campaign) ) {
        return;
    }

    $campaign['processed'] = isset($campaign['processed']) ? ((int) $campaign['processed']) + 1 : 1;
    if ( $was_sent ) {
        $campaign['success'] = isset($campaign['success']) ? ((int) $campaign['success']) + 1 : 1;
    } else {
        $campaign['failed'] = isset($campaign['failed']) ? ((int) $campaign['failed']) + 1 : 1;
    }

    if ( ! isset($campaign['recipients']) || ! is_array($campaign['recipients']) ) {
        $campaign['recipients'] = array();
    }

    $campaign['recipients'][] = array(
        'email' => sanitize_email($email),
        'result' => $was_sent ? 'sent' : 'failed',
        'timestamp' => current_time('timestamp'),
    );

    update_option('pmbulk_active_campaign_log', $campaign);
}

// Finalize active campaign and move it into campaign history
function pmbulk_finalize_campaign_tracking($status) {
    $campaign = get_option('pmbulk_active_campaign_log', array());
    if ( empty($campaign) || ! is_array($campaign) ) {
        return;
    }

    $campaign['status'] = $status;
    $campaign['finished_at'] = current_time('timestamp');

    $history = pmbulk_get_campaign_history();
    array_unshift($history, $campaign);
    pmbulk_save_campaign_history($history);

    delete_option('pmbulk_active_campaign_log');
}

// Admin page UI
function pmbulk_admin_page() {
    if ( ! current_user_can('manage_woocommerce') ) {
        wp_die('Unauthorized');
    }

    $brand_settings = pmbulk_get_brand_settings();

    $selected_scope = pmbulk_normalize_recipient_scope(get_option('pmbulk_recipient_scope', 'customers'));
    $batch_size_setting = pmbulk_sanitize_batch_size(get_option('pmbulk_batch_size', 5));
    $batch_interval_setting = pmbulk_sanitize_batch_interval(get_option('pmbulk_batch_interval', 90));

    // Handle form submissions
    if ( isset($_POST['pmbulk_action']) && check_admin_referer('pmbulk_nonce_action', 'pmbulk_nonce') ) {
        $action = sanitize_text_field(wp_unslash($_POST['pmbulk_action'] ?? ''));
        $action_draft_id = '';

        if ( strpos($action, 'load_draft:') === 0 ) {
            $action_draft_id = sanitize_text_field(substr($action, strlen('load_draft:')));
            $action = 'load_draft';
        } elseif ( strpos($action, 'delete_draft:') === 0 ) {
            $action_draft_id = sanitize_text_field(substr($action, strlen('delete_draft:')));
            $action = 'delete_draft';
        }
        $submitted_scope = isset($_POST['pmbulk_recipient_scope'])
            ? pmbulk_normalize_recipient_scope(sanitize_text_field(wp_unslash($_POST['pmbulk_recipient_scope'])))
            : $selected_scope;

        if ( $submitted_scope !== $selected_scope ) {
            $selected_scope = $submitted_scope;
            update_option('pmbulk_recipient_scope', $selected_scope);
        }

        if ( $action === 'change_scope' ) {
            echo '<div class="notice notice-success"><p>Audience switched to ' . esc_html(pmbulk_get_recipient_scope_label($selected_scope)) . '.</p></div>';
        }

        if ( $action === 'save_rate_settings' ) {
            $new_batch_size = isset($_POST['pmbulk_batch_size'])
                ? pmbulk_sanitize_batch_size(wp_unslash($_POST['pmbulk_batch_size']))
                : $batch_size_setting;
            $new_batch_interval = isset($_POST['pmbulk_batch_interval'])
                ? pmbulk_sanitize_batch_interval(wp_unslash($_POST['pmbulk_batch_interval']))
                : $batch_interval_setting;

            update_option('pmbulk_batch_size', $new_batch_size);
            update_option('pmbulk_batch_interval', $new_batch_interval);
            $batch_size_setting = $new_batch_size;
            $batch_interval_setting = $new_batch_interval;

            echo '<div class="notice notice-success"><p>Send rate updated: ' . esc_html($batch_size_setting) . ' emails every ' . esc_html($batch_interval_setting) . ' seconds.</p></div>';
        }
        
        if ( $action === 'save_draft' ) {
            $draft_subject = sanitize_text_field(wp_unslash($_POST['pmbulk_subject'] ?? ''));
            $draft_message = wp_kses_post(wp_unslash($_POST['pmbulk_message'] ?? ''));

            update_option('pmbulk_subject', $draft_subject);
            update_option('pmbulk_message', $draft_message);
            pmbulk_add_draft_snapshot($draft_subject, $draft_message, $selected_scope);

            echo '<div class="notice notice-success"><p>Email draft saved to Draft Library.</p></div>';
        }

        if ( $action === 'load_draft' ) {
            $draft_id = ! empty($action_draft_id)
                ? $action_draft_id
                : ( isset($_POST['pmbulk_draft_id']) ? sanitize_text_field(wp_unslash($_POST['pmbulk_draft_id'])) : '' );
            $draft = pmbulk_get_draft_by_id($draft_id);

            if ( ! empty($draft) ) {
                $draft_subject = isset($draft['subject']) ? sanitize_text_field($draft['subject']) : '';
                $draft_message = isset($draft['message']) ? wp_kses_post($draft['message']) : '';
                $draft_scope = isset($draft['scope']) ? pmbulk_normalize_recipient_scope($draft['scope']) : $selected_scope;

                update_option('pmbulk_subject', $draft_subject);
                update_option('pmbulk_message', $draft_message);
                update_option('pmbulk_recipient_scope', $draft_scope);
                $selected_scope = $draft_scope;

                echo '<div class="notice notice-success"><p>Draft loaded into composer.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Draft not found.</p></div>';
            }
        }

        if ( $action === 'delete_draft' ) {
            $draft_id = ! empty($action_draft_id)
                ? $action_draft_id
                : ( isset($_POST['pmbulk_draft_id']) ? sanitize_text_field(wp_unslash($_POST['pmbulk_draft_id'])) : '' );
            if ( pmbulk_delete_draft_by_id($draft_id) ) {
                echo '<div class="notice notice-success"><p>Draft deleted.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Draft not found.</p></div>';
            }
        }
        
        if ( $action === 'send_test' ) {
            $test_email = sanitize_email(wp_unslash($_POST['pmbulk_test_email'] ?? ''));
            $subject = sanitize_text_field(wp_unslash($_POST['pmbulk_subject'] ?? ''));
            $message = wp_kses_post(wp_unslash($_POST['pmbulk_message'] ?? ''));
            
            if ( $test_email && is_email($test_email) ) {
                pmbulk_send_single_email($test_email, $subject, $message);
                echo '<div class="notice notice-success"><p>Test email sent to ' . esc_html($test_email) . '!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Invalid email address.</p></div>';
            }
        }
        
        if ( $action === 'start_bulk' ) {
            $subject = sanitize_text_field(wp_unslash($_POST['pmbulk_subject'] ?? ''));
            $message = wp_kses_post(wp_unslash($_POST['pmbulk_message'] ?? ''));
            $batch_size_setting = isset($_POST['pmbulk_batch_size'])
                ? pmbulk_sanitize_batch_size(wp_unslash($_POST['pmbulk_batch_size']))
                : $batch_size_setting;
            $batch_interval_setting = isset($_POST['pmbulk_batch_interval'])
                ? pmbulk_sanitize_batch_interval(wp_unslash($_POST['pmbulk_batch_interval']))
                : $batch_interval_setting;

            update_option('pmbulk_batch_size', $batch_size_setting);
            update_option('pmbulk_batch_interval', $batch_interval_setting);
            
            // Save email content
            update_option('pmbulk_subject', $subject);
            update_option('pmbulk_message', $message);
            
            // Get emails for selected audience
            $emails = pmbulk_get_recipient_emails($selected_scope);

            if ( empty($emails) ) {
                echo '<div class="notice notice-error"><p>No recipients found for the selected audience.</p></div>';
            } else {
                // Save queue
                update_option('pmbulk_queue', $emails);
                update_option('pmbulk_queue_total', count($emails));
                update_option('pmbulk_queue_sent', 0);
                update_option('pmbulk_queue_failed', 0);
                update_option('pmbulk_queue_active', true);
                update_option('pmbulk_queue_scope', $selected_scope);
                update_option('pmbulk_queue_batch_size', $batch_size_setting);
                update_option('pmbulk_queue_batch_interval', $batch_interval_setting);
                
                // Track campaign history details
                pmbulk_start_campaign_tracking($selected_scope, $subject, $message, count($emails), $batch_size_setting, $batch_interval_setting);
                
                // Schedule first batch
                if ( ! wp_next_scheduled('pmbulk_process_batch') ) {
                    wp_schedule_single_event(time() + 5, 'pmbulk_process_batch');
                }
                
                echo '<div class="notice notice-success"><p>Bulk email campaign started! Sending to ' . count($emails) . ' ' . esc_html(pmbulk_get_recipient_scope_label($selected_scope)) . ' in batches of ' . esc_html($batch_size_setting) . ' every ' . esc_html($batch_interval_setting) . ' seconds.</p></div>';
            }
        }
        
        if ( $action === 'stop_bulk' ) {
            pmbulk_stop_campaign();
            echo '<div class="notice notice-warning"><p>Bulk email campaign stopped.</p></div>';
        }
        
        if ( $action === 'reset_queue' ) {
            pmbulk_stop_campaign();
            delete_option('pmbulk_queue_total');
            delete_option('pmbulk_queue_sent');
            delete_option('pmbulk_queue_failed');
            delete_option('pmbulk_queue_scope');
            delete_option('pmbulk_queue_batch_size');
            delete_option('pmbulk_queue_batch_interval');
            delete_option('pmbulk_campaign_history');
            delete_option('pmbulk_active_campaign_log');
            delete_option('pmbulk_draft_history');
            echo '<div class="notice notice-success"><p>Queue, campaign history, and draft library cleared.</p></div>';
        }
    }

    // Get current draft
    $subject = wp_unslash(get_option('pmbulk_subject', 'Important Update'));
    $message = wp_unslash(get_option('pmbulk_message', '<p>Hello!</p><p>We wanted to reach out to you...</p><p>Thanks,<br>The Team</p>'));
    
    // Get queue status
    $queue_active = get_option('pmbulk_queue_active', false);
    $queue_total = get_option('pmbulk_queue_total', 0);
    $queue_sent = get_option('pmbulk_queue_sent', 0);
    $queue_failed = get_option('pmbulk_queue_failed', 0);
    $queue_scope = pmbulk_normalize_recipient_scope(get_option('pmbulk_queue_scope', $selected_scope));
    $queue_batch_size = pmbulk_sanitize_batch_size(get_option('pmbulk_queue_batch_size', $batch_size_setting));
    $queue_batch_interval = pmbulk_sanitize_batch_interval(get_option('pmbulk_queue_batch_interval', $batch_interval_setting));
    $campaign_history = pmbulk_get_campaign_history();
    $draft_history = pmbulk_get_draft_history();
    
    // Get recipient counts/lists
    $customer_emails = pmbulk_get_customer_emails();
    $all_user_emails = pmbulk_get_all_user_emails();
    $customer_count = count($customer_emails);
    $all_user_count = count($all_user_emails);
    $non_customer_emails = pmbulk_get_non_customer_emails();
    $non_customer_count = count($non_customer_emails);
    $active_emails = pmbulk_get_recipient_emails($selected_scope);
    $active_count = count($active_emails);
    $active_scope_label = pmbulk_get_recipient_scope_label($selected_scope);
    
    // Get logo URL
    $logo_url = pmbulk_get_logo_url();
    ?>
    <div class="wrap">
        <?php if ( ! empty($logo_url) ) : ?>
            <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_settings['brand_name']); ?>" style="max-width:140px;height:auto;margin:20px 0 10px 0;">
        <?php endif; ?>
        <h1 style="margin-top:0;">Bulk Customer Email</h1>
        
        <?php if ( $queue_active ) : ?>
            <div class="pm-box pm-progress" style="padding: 20px; border-left: 4px solid #111;">
                <h3 style="margin-top: 0; color: #111;">Campaign in Progress</h3>
                <p style="margin-top:0;"><strong>Audience:</strong> <?php echo esc_html(pmbulk_get_recipient_scope_label($queue_scope)); ?></p>
                <p style="margin-top:-4px;"><strong>Send rate:</strong> <?php echo esc_html($queue_batch_size); ?> every <?php echo esc_html($queue_batch_interval); ?> seconds</p>
                <p><strong>Progress:</strong> <?php echo esc_html($queue_sent); ?> of <?php echo esc_html($queue_total); ?> emails sent 
                    (<?php echo esc_html($queue_total > 0 ? round(($queue_sent / $queue_total) * 100) : 0); ?>%)</p>
                <p style="margin-top:-4px;"><strong>Delivery results:</strong> <?php echo esc_html($queue_sent - $queue_failed); ?> sent, <?php echo esc_html($queue_failed); ?> failed</p>
                <div style="background: #e5e7eb; height: 32px; border-radius: 8px; overflow: hidden; margin: 10px 0;">
                    <div style="background: #111; height: 100%; width: <?php echo esc_attr($queue_total > 0 ? ($queue_sent / $queue_total) * 100 : 0); ?>%; transition: width 0.3s;"></div>
                </div>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('pmbulk_nonce_action', 'pmbulk_nonce'); ?>
                    <input type="hidden" name="pmbulk_action" value="stop_bulk">
                    <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure you want to stop the campaign?');">Stop Campaign</button>
                </form>
                <button type="button" class="button" onclick="location.reload();">Refresh Progress</button>
            </div>
        <?php endif; ?>

        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('pmbulk_nonce_action', 'pmbulk_nonce'); ?>

            <div class="pm-layout">
                <div class="pm-main-column">
                    <div class="pm-box">
                        <h2 style="margin-top: 0; color: #111;">Compose Email</h2>
                        
                        <div style="margin-bottom: 20px;">
                            <label for="pmbulk_subject" style="display: block; font-weight: 600; margin-bottom: 8px;">Subject Line</label>
                            <input type="text" 
                                   id="pmbulk_subject" 
                                   name="pmbulk_subject" 
                                   value="<?php echo esc_attr($subject); ?>" 
                                   class="regular-text"
                                   style="width: 100%; padding: 8px; font-size: 14px;"
                                   required>
                        </div>
                        
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Message Preview</label>
                        <p class="description" style="margin-top: 0; margin-bottom: 12px;">This shows how your email will appear to recipients. Edit directly in the box below:</p>
                        
                        <!-- Email Template Wrapper -->
                        <div style="background:#f4f4f4; padding:24px; border-radius:8px; border: 2px solid #dcdcde;">
                            <div style="max-width:640px; margin:0 auto; background:#ffffff; padding:24px; border-radius:8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                <?php 
                                $logo_url = pmbulk_get_logo_url();
                                if ( ! empty($logo_url) ) : 
                                ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_settings['brand_name']); ?>" style="max-width:140px;height:auto;display:block;margin:0 0 22px 0;">
                                <?php endif; ?>
                                
                                <div style="font-size:15px;line-height:1.6;color:#111;">
                                    <?php 
                                    wp_editor($message, 'pmbulk_message', array(
                                        'textarea_rows' => 12,
                                        'media_buttons' => false,
                                        'teeny' => false,
                                        'tinymce' => array(
                                            'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,forecolor',
                                            'content_style' => 'body { font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif; font-size: 15px; line-height: 1.6; color: #111; margin: 0; padding: 10px; }'
                                        )
                                    )); 
                                    ?>
                                </div>
                                
                                <p style="margin:26px 0 0 0;font-size:14px;line-height:1.5;color:#111;padding:10px 0 0 0;border-top:1px solid #e5e7eb;">
                                  — <?php echo esc_html($brand_settings['brand_name']); ?><br>
                                  <a href="<?php echo esc_url($brand_settings['brand_url']); ?>" style="color:#555;text-decoration:none;"><?php echo esc_html($brand_settings['brand_url']); ?></a>
                                </p>
                                
                                <p style="margin:12px 0 0 0;font-size:12px;line-height:1.4;color:#999;">
                                  <?php echo esc_html($brand_settings['footer_blurb']); ?>
                                </p>
                            </div>
                        </div>

                        <p class="submit" style="margin-top: 20px; margin-bottom: 0;">
                            <button type="submit" name="pmbulk_action" value="save_draft" class="button">
                                💾 Save Draft
                            </button>
                        </p>
                    </div>

                    <div class="pm-box" style="margin-top: 20px;">
                        <h2 style="margin-top: 0; color: #111;">Draft Library</h2>
                        <p style="margin-top:0;">Reusable templates you saved before sending. Campaign History below is your sent-email audit trail.</p>

                        <?php if ( empty($draft_history) ) : ?>
                            <p style="margin-bottom: 0;">No saved drafts yet.</p>
                        <?php else : ?>
                            <?php foreach ( $draft_history as $draft ) : ?>
                                <?php
                                    $draft_id = isset($draft['id']) ? $draft['id'] : '';
                                    $draft_subject = isset($draft['subject']) ? $draft['subject'] : '(no subject)';
                                    $draft_scope = isset($draft['scope']) ? pmbulk_get_recipient_scope_label($draft['scope']) : 'customers with orders';
                                    $draft_saved_at = isset($draft['saved_at']) ? pmbulk_format_campaign_time($draft['saved_at']) : 'n/a';
                                    $draft_message_preview = isset($draft['message']) ? wp_strip_all_tags($draft['message']) : '';
                                    $draft_message_preview = wp_trim_words($draft_message_preview, 22, '...');
                                ?>
                                <details class="pm-history-item">
                                    <summary><?php echo esc_html($draft_saved_at); ?> | <?php echo esc_html($draft_subject); ?></summary>
                                    <p><strong>Audience:</strong> <?php echo esc_html($draft_scope); ?></p>
                                    <p><strong>Preview:</strong> <?php echo esc_html($draft_message_preview); ?></p>
                                    <div class="pm-inline-actions">
                                        <button type="submit" name="pmbulk_action" value="load_draft:<?php echo esc_attr($draft_id); ?>" class="button button-secondary">Load Draft</button>
                                        <button type="submit" name="pmbulk_action" value="delete_draft:<?php echo esc_attr($draft_id); ?>" class="button" onclick="return confirm('Delete this draft?');">Delete</button>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="pm-box" style="margin-top: 20px;">
                        <h3 style="margin-top: 0; color: #111;">Campaign History</h3>

                        <?php if ( empty($campaign_history) ) : ?>
                            <p style="margin-bottom: 0;">No campaign history yet.</p>
                        <?php else : ?>
                            <p style="margin-top:0;">Recent campaigns are listed below. Expand one to view exact recipients and delivery results.</p>
                            <?php foreach ( $campaign_history as $campaign ) : ?>
                                <?php
                                    $history_total = isset($campaign['total']) ? (int) $campaign['total'] : 0;
                                    $history_success = isset($campaign['success']) ? (int) $campaign['success'] : 0;
                                    $history_failed = isset($campaign['failed']) ? (int) $campaign['failed'] : 0;
                                    $history_scope = isset($campaign['scope']) ? pmbulk_get_recipient_scope_label($campaign['scope']) : 'unknown';
                                    $history_status = isset($campaign['status']) ? $campaign['status'] : 'unknown';
                                    $history_subject = isset($campaign['subject']) ? $campaign['subject'] : '';
                                    $history_message = isset($campaign['message']) ? $campaign['message'] : '';
                                    $history_started = isset($campaign['started_at']) ? pmbulk_format_campaign_time($campaign['started_at']) : 'n/a';
                                    $history_finished = isset($campaign['finished_at']) ? pmbulk_format_campaign_time($campaign['finished_at']) : 'n/a';
                                    $history_batch_size = isset($campaign['batch_size']) ? pmbulk_sanitize_batch_size($campaign['batch_size']) : 5;
                                    $history_batch_interval = isset($campaign['batch_interval']) ? pmbulk_sanitize_batch_interval($campaign['batch_interval']) : 90;
                                    $history_message_preview = wp_trim_words(wp_strip_all_tags($history_message), 26, '...');
                                    $recipient_lines = array();

                                    if ( ! empty($campaign['recipients']) && is_array($campaign['recipients']) ) {
                                        foreach ( $campaign['recipients'] as $entry ) {
                                            $entry_time = isset($entry['timestamp']) ? pmbulk_format_campaign_time($entry['timestamp']) : 'n/a';
                                            $entry_result = isset($entry['result']) ? strtoupper($entry['result']) : 'UNKNOWN';
                                            $entry_email = isset($entry['email']) ? $entry['email'] : '';
                                            $recipient_lines[] = '[' . $entry_time . '] ' . $entry_result . ' - ' . $entry_email;
                                        }
                                    }
                                ?>
                                <details class="pm-history-item">
                                    <summary>
                                        <?php echo esc_html($history_started); ?> | <?php echo esc_html($history_scope); ?> | <?php echo esc_html($history_success); ?>/<?php echo esc_html($history_total); ?> sent
                                    </summary>
                                    <p><strong>Status:</strong> <?php echo esc_html($history_status); ?></p>
                                    <p><strong>Subject:</strong> <?php echo esc_html($history_subject); ?></p>
                                    <p><strong>Started:</strong> <?php echo esc_html($history_started); ?></p>
                                    <p><strong>Finished:</strong> <?php echo esc_html($history_finished); ?></p>
                                    <p><strong>Audience:</strong> <?php echo esc_html($history_scope); ?></p>
                                    <p><strong>Send rate:</strong> <?php echo esc_html($history_batch_size); ?> every <?php echo esc_html($history_batch_interval); ?> seconds</p>
                                    <p><strong>Delivery:</strong> <?php echo esc_html($history_success); ?> sent, <?php echo esc_html($history_failed); ?> failed, <?php echo esc_html($history_total); ?> total</p>
                                    <p><strong>Message preview:</strong> <?php echo esc_html($history_message_preview); ?></p>
                                    <label style="display:block; font-weight:600; margin-bottom:6px;">Recipient Log</label>
                                    <textarea readonly style="width:100%; height:140px; font-family:monospace; font-size:12px;"><?php echo esc_textarea(implode("\n", $recipient_lines)); ?></textarea>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <button type="submit" name="pmbulk_action" value="reset_queue" class="button button-small">Clear Statistics</button>
                    </div>
                </div>

                <div class="pm-side-column">
                    <div class="pm-box" style="border-left: 4px solid #111;">
                        <h2 style="margin-top: 0; color: #111;">Send Bulk Email</h2>
                        <p><strong>Warning:</strong> This will send the email to all <?php echo esc_html($active_count); ?> <?php echo esc_html($active_scope_label); ?>.</p>
                        <p>Emails are sent in batches of <?php echo esc_html($batch_size_setting); ?> every <?php echo esc_html($batch_interval_setting); ?> seconds to avoid server overload.</p>

                        <?php if ( ! $queue_active ) : ?>
                            <p class="submit" style="margin-bottom: 0;">
                                <button type="submit" 
                                        name="pmbulk_action" 
                                        value="start_bulk" 
                                        class="button button-primary button-hero"
                                        onclick="return confirm('Are you sure you want to send this email to <?php echo esc_js($active_count); ?> <?php echo esc_js($active_scope_label); ?>? Make sure you\'ve sent a test email first.');"
                                        <?php echo $active_count === 0 ? 'disabled' : ''; ?>>
                                    🚀 Start Bulk Send
                                </button>
                            </p>
                        <?php else : ?>
                            <p><em>A campaign is currently running. Wait for it to complete or stop it before starting a new one.</em></p>
                        <?php endif; ?>
                    </div>

                    <div class="pm-box">
                        <h2 style="margin-top: 0; color: #111;">Audience</h2>
                        <p style="margin-top:0;">Choose who receives the campaign.</p>
                        <label class="pm-audience-option">
                            <input type="radio" name="pmbulk_recipient_scope" value="customers" <?php checked($selected_scope, 'customers'); ?>>
                            Customers with orders (<?php echo esc_html($customer_count); ?>)
                        </label>
                        <label class="pm-audience-option">
                            <input type="radio" name="pmbulk_recipient_scope" value="all_users" <?php checked($selected_scope, 'all_users'); ?>>
                            All WordPress users (<?php echo esc_html($all_user_count); ?>)
                        </label>
                        <label class="pm-audience-option">
                            <input type="radio" name="pmbulk_recipient_scope" value="non_customers" <?php checked($selected_scope, 'non_customers'); ?>>
                            Non-customers (<?php echo esc_html($non_customer_count); ?>)
                        </label>

                        <p style="margin-bottom: 0;">
                            <button type="submit" name="pmbulk_action" value="change_scope" class="button button-secondary">Apply Audience</button>
                        </p>

                        <hr style="margin:16px 0;">

                        <p style="margin:0;"><strong>Current audience:</strong> <?php echo esc_html($active_scope_label); ?></p>
                        <p style="margin:8px 0 0 0;"><strong>Total recipients:</strong> <?php echo esc_html($active_count); ?></p>

                        <details style="margin-top:12px;">
                            <summary style="cursor: pointer; color: #111; font-weight: 600;">View email addresses</summary>
                            <textarea readonly style="width: 100%; height: 180px; margin-top: 10px; font-family: monospace; font-size: 12px;"><?php 
                                echo esc_textarea(implode("\n", $active_emails)); 
                            ?></textarea>
                        </details>
                    </div>

                    <div class="pm-box" style="margin-top: 20px;">
                        <h3 style="margin-top: 0; color: #111;">Sending Speed</h3>
                        <p style="margin-top:0;">Tune throughput for Exchange throttling tolerance.</p>
                        <label for="pmbulk_batch_size" style="display:block; font-weight:600; margin-bottom:6px;">Batch size (1-20)</label>
                        <input type="number"
                               id="pmbulk_batch_size"
                               name="pmbulk_batch_size"
                               min="1"
                               max="20"
                               value="<?php echo esc_attr($batch_size_setting); ?>"
                               class="small-text"
                               style="width:100%; max-width:120px; margin-bottom:10px;">

                        <label for="pmbulk_batch_interval" style="display:block; font-weight:600; margin-bottom:6px;">Interval seconds (60-1800)</label>
                        <input type="number"
                               id="pmbulk_batch_interval"
                               name="pmbulk_batch_interval"
                               min="60"
                               max="1800"
                               value="<?php echo esc_attr($batch_interval_setting); ?>"
                               class="small-text"
                               style="width:100%; max-width:120px; margin-bottom:12px;">

                        <button type="submit" name="pmbulk_action" value="save_rate_settings" class="button button-secondary">Save Speed Settings</button>
                    </div>

                    <div class="pm-box" style="margin-top: 20px;">
                        <h2 style="margin-top: 0; color: #111;">Test Email</h2>
                        <p>Send a test email to yourself before sending to the selected audience.</p>
                        <label for="pmbulk_test_email" style="display:block; font-weight: 600; margin-bottom: 8px;">Test Email Address</label>
                        <input type="email" 
                               id="pmbulk_test_email" 
                               name="pmbulk_test_email" 
                               value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" 
                               class="regular-text"
                               style="width:100%; max-width:100%; margin-bottom: 12px;">
                        <button type="submit" name="pmbulk_action" value="send_test" class="button">
                            🧪 Send Test
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <style>
        .pm-box { 
            background: #fff; 
            border: 1px solid #dcdcde; 
            border-radius: 12px; 
            padding: 20px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.08); 
        }
        .pm-progress {
            background: #f6f7f8;
        }
        details summary:hover { 
            text-decoration: underline; 
        }
        .button-primary {
            background: #111 !important;
            border-color: #111 !important;
            text-shadow: none !important;
            box-shadow: none !important;
        }
        .button-primary:hover {
            background: #333 !important;
            border-color: #333 !important;
        }
        .pm-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.9fr) minmax(300px, 1fr);
            gap: 20px;
            align-items: start;
        }
        .pm-main-column,
        .pm-side-column {
            min-width: 0;
        }
        .pm-side-column {
            position: sticky;
            top: 48px;
        }
        .pm-audience-option {
            display: block;
            margin: 10px 0;
        }
        .pm-audience-option input {
            margin-right: 8px;
        }
        .pm-history-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            background: #fafafa;
        }
        .pm-history-item summary {
            cursor: pointer;
            font-weight: 600;
        }
        .pm-history-item p {
            margin: 8px 0;
        }
        .pm-inline-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        @media (max-width: 1200px) {
            .pm-layout {
                grid-template-columns: 1fr;
            }
            .pm-side-column {
                position: static;
            }
        }
        /* Style the editor to blend with email template */
        #pmbulk_message_ifr {
            border: 1px dashed #dcdcde !important;
            border-radius: 4px;
        }
        .wp-editor-container {
            border: none !important;
        }
    </style>
    <?php
}

// Get all unique WordPress user emails
function pmbulk_get_all_user_emails() {
    $users = get_users(array(
        'fields' => array('user_email'),
        'orderby' => 'user_email',
        'order' => 'ASC',
        'number' => -1,
    ));

    $emails = array();
    foreach ( $users as $user ) {
        if ( ! empty($user->user_email) && is_email($user->user_email) ) {
            $emails[] = strtolower($user->user_email);
        }
    }

    return array_values(array_unique($emails));
}

// Get recipient emails based on selected scope
function pmbulk_get_recipient_emails($scope) {
    $scope = pmbulk_normalize_recipient_scope($scope);
    if ( $scope === 'all_users' ) {
        return pmbulk_get_all_user_emails();
    }

    if ( $scope === 'non_customers' ) {
        return pmbulk_get_non_customer_emails();
    }

    return pmbulk_get_customer_emails();
}

// Get all unique non-customer WordPress user emails
// A non-customer is a WordPress user whose email does not appear on a qualifying WooCommerce order.
function pmbulk_get_non_customer_emails() {
    $all_users = pmbulk_get_all_user_emails();
    $customers = pmbulk_get_customer_emails();
    $customer_lookup = array_fill_keys(array_map('strtolower', $customers), true);

    $emails = array();
    foreach ( $all_users as $email ) {
        $email = strtolower($email);
        if ( ! isset($customer_lookup[$email]) ) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

// Get all unique customer emails from orders
function pmbulk_get_customer_emails() {
    // Use WooCommerce's order API so this works with both legacy order storage and HPOS.
    $emails = array();

    $orders = wc_get_orders(array(
        'status' => array('wc-completed', 'wc-processing', 'wc-on-hold'),
        'limit'  => -1,
        'return' => 'objects',
    ));

    foreach ( $orders as $order ) {
        if ( ! $order instanceof WC_Order ) {
            continue;
        }

        $email = $order->get_billing_email();

        if ( $email && is_email($email) ) {
            $emails[] = strtolower($email);
        }
    }

    $emails = array_values(array_unique($emails));
    sort($emails, SORT_NATURAL | SORT_FLAG_CASE);

    return $emails;
}

// Send a single email
function pmbulk_send_single_email($to, $subject, $message) {
    $settings = pmbulk_get_brand_settings();
    $logo_url = pmbulk_get_logo_url();
    $logo_html = '';

    if ( ! empty($logo_url) ) {
        $logo_html = '<img src="'.esc_url($logo_url).'" alt="'.esc_attr($settings['brand_name']).'" style="max-width:140px;height:auto;display:block;margin:0 0 22px 0;">';
    }

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . sanitize_text_field($settings['from_name']) . ' <' . sanitize_email($settings['from_email']) . '>'
    );

    $html_message = '
<div style="background:#f4f4f4;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
  <div style="max-width:640px;margin:0 auto;background:#ffffff;padding:24px;border-radius:8px;">
    '.$logo_html.'
    <div style="font-size:15px;line-height:1.6;color:#111;">
        ' . wpautop($message) . '
    </div>
    <p style="margin:26px 0 0 0;font-size:14px;line-height:1.5;color:#111;">
      — ' . esc_html($settings['brand_name']) . '<br>
      <a href="' . esc_url($settings['brand_url']) . '" style="color:#555;text-decoration:none;">' . esc_html($settings['brand_url']) . '</a>
    </p>
    <p style="margin:16px 0 0 0;padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.4;color:#999;">
      ' . esc_html($settings['footer_blurb']) . '
    </p>
  </div>
</div>';

    return wp_mail($to, $subject, $html_message, $headers);
}

// Process batch of emails (run via cron)
add_action('pmbulk_process_batch', 'pmbulk_process_batch_callback');
function pmbulk_process_batch_callback() {
    $queue = get_option('pmbulk_queue', array());
    $sent_count = get_option('pmbulk_queue_sent', 0);
    $failed_count = get_option('pmbulk_queue_failed', 0);
    $batch_size = pmbulk_sanitize_batch_size(get_option('pmbulk_queue_batch_size', get_option('pmbulk_batch_size', 5)));
    $batch_interval = pmbulk_sanitize_batch_interval(get_option('pmbulk_queue_batch_interval', get_option('pmbulk_batch_interval', 90)));
    $subject = get_option('pmbulk_subject', '');
    $message = get_option('pmbulk_message', '');
    
    if ( empty($queue) ) {
        // Queue is empty - campaign completed
        pmbulk_stop_campaign('completed');
        return;
    }

    if ( ! get_option('pmbulk_queue_active', false) ) {
        // Campaign was manually stopped or deactivated
        pmbulk_stop_campaign('stopped');
        return;
    }
    
    // Send configured batch size. Keep batches small so one cron request never
    // holds a large number of SMTP/PHP operations at once.
    $batch = array_slice($queue, 0, $batch_size);
    $batch_results = array();

    foreach ( $batch as $email ) {
        $was_sent = pmbulk_send_single_email($email, $subject, $message);
        if ( ! $was_sent ) {
            $failed_count++;
        }
        $batch_results[] = array(
            'email'     => sanitize_email($email),
            'result'    => $was_sent ? 'sent' : 'failed',
            'timestamp' => current_time('timestamp'),
        );
        $sent_count++;
    }

    // Update queue and counters once per batch instead of once per recipient.
    $remaining = array_slice($queue, $batch_size);
    update_option('pmbulk_queue', $remaining);
    update_option('pmbulk_queue_sent', $sent_count);
    update_option('pmbulk_queue_failed', $failed_count);

    // Append this batch to the campaign log with one database write.
    $campaign = get_option('pmbulk_active_campaign_log', array());
    if ( ! empty($campaign) && is_array($campaign) ) {
        if ( ! isset($campaign['recipients']) || ! is_array($campaign['recipients']) ) {
            $campaign['recipients'] = array();
        }
        $campaign['processed'] = isset($campaign['processed']) ? ((int) $campaign['processed']) + count($batch_results) : count($batch_results);
        $campaign['success'] = isset($campaign['success']) ? ((int) $campaign['success']) + count(array_filter($batch_results, function($r) { return $r['result'] === 'sent'; })) : count(array_filter($batch_results, function($r) { return $r['result'] === 'sent'; }));
        $campaign['failed'] = isset($campaign['failed']) ? ((int) $campaign['failed']) + count(array_filter($batch_results, function($r) { return $r['result'] === 'failed'; })) : count(array_filter($batch_results, function($r) { return $r['result'] === 'failed'; }));
        $campaign['recipients'] = array_merge($campaign['recipients'], $batch_results);
        update_option('pmbulk_active_campaign_log', $campaign);
    }

    // Release the lock before scheduling the next independent request.
    delete_option($lock_key);

    if ( empty($remaining) ) {
        // All done!
        pmbulk_stop_campaign('completed');
    } else {
        // Schedule the next batch only after this request has finished its work.
        wp_schedule_single_event(time() + $batch_interval, 'pmbulk_process_batch');
    }
}

// Stop the campaign
function pmbulk_stop_campaign($status = 'stopped') {
    update_option('pmbulk_queue_active', false);
    delete_option('pmbulk_queue');
    wp_clear_scheduled_hook('pmbulk_process_batch');
    pmbulk_finalize_campaign_tracking($status);
}

// Clean up on deactivation
register_deactivation_hook(__FILE__, 'pmbulk_deactivate');
function pmbulk_deactivate() {
    pmbulk_stop_campaign('stopped');
}

// Brand/email settings page
add_action('admin_menu', 'pmbulk_add_settings_page');
function pmbulk_add_settings_page() {
    add_submenu_page(
        'pmbulk-email',
        'Bulk Email Settings',
        'Settings',
        'manage_woocommerce',
        'pmbulk-settings',
        'pmbulk_settings_page'
    );
}

add_action('admin_enqueue_scripts', 'pmbulk_settings_assets');
function pmbulk_settings_assets($hook) {
    if ( $hook !== 'bulk-email_page_pmbulk-settings' ) {
        return;
    }
    wp_enqueue_media();
}

function pmbulk_settings_page() {
    if ( ! current_user_can('manage_woocommerce') ) {
        wp_die('Unauthorized');
    }

    if ( isset($_POST['pmbulk_save_brand_settings']) && check_admin_referer('pmbulk_save_brand_settings') ) {
        update_option('pmbulk_brand_name', sanitize_text_field(wp_unslash($_POST['pmbulk_brand_name'] ?? '')));
        update_option('pmbulk_brand_url', esc_url_raw(wp_unslash($_POST['pmbulk_brand_url'] ?? '')));
        update_option('pmbulk_from_name', sanitize_text_field(wp_unslash($_POST['pmbulk_from_name'] ?? '')));
        update_option('pmbulk_from_email', sanitize_email(wp_unslash($_POST['pmbulk_from_email'] ?? '')));
        update_option('pmbulk_footer_blurb', sanitize_text_field(wp_unslash($_POST['pmbulk_footer_blurb'] ?? '')));
        update_option('pmbulk_logo_id', absint($_POST['pmbulk_logo_id'] ?? 0));
        echo '<div class="notice notice-success"><p>Brand and email settings saved.</p></div>';
    }

    $settings = pmbulk_get_brand_settings();
    $logo_id = (int) get_option('pmbulk_logo_id', 0);
    $logo_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
    ?>
    <div class="wrap">
        <h1>Bulk Email Settings</h1>
        <p>Customize the branding used in outgoing bulk emails. Defaults are based on your WordPress site.</p>
        <form method="post">
            <?php wp_nonce_field('pmbulk_save_brand_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pmbulk_brand_name">Brand / Site Name</label></th>
                    <td><input name="pmbulk_brand_name" id="pmbulk_brand_name" type="text" class="regular-text" value="<?php echo esc_attr($settings['brand_name']); ?>">
                    <p class="description">Used in the email signature and logo alt text.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pmbulk_brand_url">Website URL</label></th>
                    <td><input name="pmbulk_brand_url" id="pmbulk_brand_url" type="url" class="regular-text" value="<?php echo esc_attr($settings['brand_url']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pmbulk_from_name">From Name</label></th>
                    <td><input name="pmbulk_from_name" id="pmbulk_from_name" type="text" class="regular-text" value="<?php echo esc_attr($settings['from_name']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pmbulk_from_email">From Email</label></th>
                    <td><input name="pmbulk_from_email" id="pmbulk_from_email" type="email" class="regular-text" value="<?php echo esc_attr($settings['from_email']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pmbulk_logo_id">Email Logo</label></th>
                    <td>
                        <input type="hidden" name="pmbulk_logo_id" id="pmbulk_logo_id" value="<?php echo esc_attr($logo_id); ?>">
                        <button type="button" class="button" id="pmbulk_select_logo">Select Logo</button>
                        <button type="button" class="button" id="pmbulk_remove_logo" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>>Remove</button>
                        <div id="pmbulk_logo_preview" style="margin-top:10px;"><?php if ($logo_url) : ?><img src="<?php echo esc_url($logo_url); ?>" style="max-width:140px;height:auto;"><?php endif; ?></div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pmbulk_footer_blurb">Footer Blurb</label></th>
                    <td><input name="pmbulk_footer_blurb" id="pmbulk_footer_blurb" type="text" class="large-text" value="<?php echo esc_attr($settings['footer_blurb']); ?>">
                    <p class="description">Short explanation shown below the email signature.</p></td>
                </tr>
            </table>
            <p><button type="submit" name="pmbulk_save_brand_settings" class="button button-primary">Save Settings</button></p>
        </form>
    </div>
    <script>
    jQuery(function($){
        let frame;
        $('#pmbulk_select_logo').on('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({title:'Select Email Logo', button:{text:'Use Logo'}, multiple:false, library:{type:'image'}});
            frame.on('select', function(){
                const attachment = frame.state().get('selection').first().toJSON();
                $('#pmbulk_logo_id').val(attachment.id);
                $('#pmbulk_logo_preview').html('<img src="'+attachment.url+'" style="max-width:140px;height:auto;">');
                $('#pmbulk_remove_logo').show();
            });
            frame.open();
        });
        $('#pmbulk_remove_logo').on('click', function(){
            $('#pmbulk_logo_id').val('0');
            $('#pmbulk_logo_preview').empty();
            $(this).hide();
        });
    });
    </script>
    <?php
}

// Add settings link on plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pmbulk_add_action_links');
function pmbulk_add_action_links($links) {
    $send_link = '<a href="admin.php?page=pmbulk-email">Bulk Email</a>';
    $settings_link = '<a href="admin.php?page=pmbulk-settings">Settings</a>';
    array_unshift($links, $settings_link, $send_link);
    return $links;
}
