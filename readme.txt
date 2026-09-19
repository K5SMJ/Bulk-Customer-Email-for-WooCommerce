=== Bulk Customer Email for WooCommerce ===
Contributors: pocketmidi
Requires at least: 6.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.5
Tested up to: 7.1
License: GPL-3.0-only
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A simple, lightweight plugin for sending email campaigns to WooCommerce customers and WordPress users.

== Description ==

Bulk Customer Email for WooCommerce is a focused utility for sending email to customers without requiring a subscription or an external email-marketing service.

It uses WordPress wp_mail() and sends messages in small batches with delays between them to reduce server load.

Features include:

* Customer audience selection based on WooCommerce orders
* All WordPress users audience
* Non-customer audience
* Configurable brand and sender information
* Logo and footer customization
* Test email before sending
* Saved drafts
* Campaign history
* Conservative batch sending

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin ZIP through Plugins → Add New → Upload Plugin.
3. Activate Bulk Customer Email for WooCommerce.
4. Open WooCommerce → Bulk Email.
5. Configure branding under WooCommerce → Bulk Email → Settings.

== Usage ==

Select an audience, compose an email, optionally save a draft, and send a test before starting a campaign.

Campaigns are processed in small batches with a configurable delay between batches. The default is 5 emails per batch with a 90-second interval, intended to be conservative on shared hosting.

== Important Notes ==

* Requires WooCommerce.
* Uses WordPress wp_mail() for delivery; actual delivery depends on the site's mail configuration.
* This plugin does not provide an external email delivery service.
* Campaign history and saved drafts are stored in WordPress options.

== A Note from the Author ==

This plugin exists because sending a useful email to your own customers shouldn't require a subscription or license fee.

So I'm giving it away.

Use it. Modify it. Improve it. Fork it. Share it.

The GPL permits people to charge for distributing GPL software, and that is their right. But selling this plugin is not what this project is intended for, and it is not something I endorse.

I'd much rather see someone improve a useful little tool and give those improvements back to the community than turn it into another subscription.

== License ==

This plugin is licensed under the GNU General Public License v3.0 (GPL-3.0-only).

See the LICENSE file for the full license.
