# WooCommerce Bulk Customer Email

A simple, lightweight plugin for sending email campaigns to WooCommerce
customers and WordPress users.

**Free software. No subscription. No license fee. No nonsense.**

## Requirements

-   WordPress
-   WooCommerce
-   A working WordPress mail configuration

**WooCommerce is required.** The plugin uses WooCommerce order data to
identify customers.

## Installation

1.  Upload the plugin through **Plugins → Add New → Upload Plugin**, or
    place it in `wp-content/plugins/`.
2.  Activate **WooCommerce Bulk Customer Email**.
3.  Open **Bulk Email** in the WordPress admin menu.

## Using the Plugin

### 1. Configure your email identity

Set your:

-   Brand/site name
-   Website URL
-   From name
-   From email
-   Logo
-   Footer text

If left blank, the plugin uses sensible WordPress defaults.

The plugin sends through WordPress `wp_mail()`. It is not an SMTP
service, so a properly configured mail/SMTP system is recommended.

### 2. Choose your audience

You can send to:

-   **Customers with orders**
-   **All WordPress users**
-   **Non-customers**

Review the recipient list before sending.

### 3. Compose and test

Write your subject and message, then send a test email before starting
the campaign.

Saved drafts and recent campaign history are available in the admin
interface.

### 4. Send

The plugin uses small batches and delays between them to reduce server
load.

The default rate is:

-   **5 emails per batch**
-   **90 seconds between batches**

These settings can be adjusted, but your hosting and mail provider may
have their own limits.

The campaign runs through scheduled WordPress events rather than trying
to send the entire list in one request.

## Important Notes

You are responsible for using this plugin in accordance with applicable
email, privacy, and anti-spam laws and your own policies.

This is a simple sending utility, not a replacement for a full
email-marketing platform. It does not provide subscriptions, automation,
advanced analytics, tracking, or dedicated email infrastructure.

## A Note from the Author

I made this because I needed a simple solution to a simple problem. I
couldn't see any good reason for a tool like this to require a
subscription or license fee.

**So I'm giving it away.**

Use it. Modify it. Improve it. Fork it. Share it.

The GPL permits people to charge for distributing GPL software, and that
is their right. But please understand that **selling this plugin is not
what this project is intended for, and it is not something I endorse.**

I'd much rather see someone improve a useful little tool and give those
improvements back to the community than turn it into another
subscription.

## License

This plugin is licensed under the **GNU General Public License v3.0
(GPL-3.0-only)**.

See the `LICENSE` file for the full license.

## Contributing

Bug fixes, security improvements, compatibility fixes, and sensible
improvements are welcome. Please keep the plugin simple and focused.
