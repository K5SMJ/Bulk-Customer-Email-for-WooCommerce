# WooCommerce Bulk Customer Email

A simple, lightweight WordPress plugin for sending email campaigns to
WooCommerce customers and WordPress users.

The plugin is intentionally focused: compose an HTML email, choose an
audience, send a test, and then send the campaign in small batches
designed to be friendly to modest/shared hosting.

## Requirements

-   WordPress
-   WooCommerce
-   A working WordPress mail configuration
-   PHP version compatible with your current WordPress installation

**WooCommerce is required.** The plugin uses WooCommerce order data to
identify customers and non-customers.

## Installation

1.  Download the plugin PHP file.
2.  In WordPress, go to **Plugins → Add New → Upload Plugin**.
3.  Upload the plugin ZIP, or place the plugin file in
    `wp-content/plugins/`.
4.  Activate **WooCommerce Bulk Customer Email**.
5.  Open **Bulk Email** in the WordPress admin menu.

## Before Sending

### 1. Configure your email identity

Use the plugin's branding/settings area to configure:

-   Brand / site name
-   Website URL
-   From name
-   From email address
-   Email logo
-   Footer text

If these fields are left blank, sensible WordPress defaults are used.

Your WordPress mail system still needs to be configured correctly. This
plugin sends through `wp_mail()`; it is not an SMTP service.

### 2. Choose an audience

The plugin can build an audience from:

-   **Customers with orders** --- billing email addresses from
    qualifying WooCommerce orders
-   **All WordPress users**
-   **Non-customers** --- WordPress users whose email does not appear
    among qualifying WooCommerce orders

Always review the recipient list before sending.

### 3. Compose the message

Enter a subject and compose the HTML message using the WordPress editor.

The plugin keeps reusable drafts and maintains recent campaign history.

### 4. Send a test

Enter a test address and use **Send Test** before starting a campaign.

### 5. Set a conservative send rate

The plugin deliberately uses small batches and delays between batches.

The default rate is conservative for shared hosting:

-   Batch size: **5**
-   Interval: **90 seconds**

You can adjust the rate, but faster is not necessarily better. Your
hosting provider and mail provider may impose their own limits.

### 6. Start the campaign

Click **Start Bulk Send** after reviewing the audience and test email.

The campaign is processed through scheduled WordPress events rather than
attempting to send every message in one web request. Progress, failures,
and recipient results are recorded in campaign history.

## Important Notes

### Email delivery

This plugin does not bypass your mail provider's sending limits. If your
site is using basic WordPress/PHP mail, delivery may be less reliable
than using a properly configured SMTP service.

For larger campaigns, make sure your hosting and mail provider allow the
volume you intend to send.

### Privacy

The plugin works with email addresses stored in WordPress and
WooCommerce. You are responsible for using it in accordance with
applicable privacy, anti-spam, and email-marketing laws and with your
own site's policies.

### Campaign history

The plugin keeps recent campaign history and draft history in WordPress
options so you can review previous sends. The admin interface includes
controls to clear these records.

## What This Plugin Is Not

This is not intended to replace a full email-marketing platform.

It does not provide:

-   Mailing-list subscription management
-   Marketing automation
-   Advanced analytics
-   Open/click tracking
-   Dedicated email infrastructure
-   Guaranteed inbox delivery

It is deliberately a small tool for straightforward WooCommerce email
campaigns.

## Why This Exists

This plugin was created because a useful tool should not have to become
a subscription service simply because someone found a way to put a
paywall around it.

It is provided **free to use**. If it saves you time, great. If you
improve it, even better.

### A request from the author

Please don't take this simple plugin, put your own name on it, and turn
around and charge people money just to obtain or use the plugin.

The point of releasing it publicly is to make a useful little piece of
software available to other WooCommerce users without another monthly
fee or artificial restriction.

You're welcome to fork it, modify it, improve it, and build something
useful with it. Please preserve that spirit when you do.

## License

See the repository license for the terms governing copying,
modification, and redistribution.

If you redistribute a modified version, please clearly identify your
changes and retain appropriate attribution.

## Contributing

Bug fixes, compatibility improvements, security improvements,
documentation improvements, and sensible feature additions are welcome.

Please keep the project focused and avoid turning a small utility into
unnecessary complexity.
