# Bulk Customer Email

A **free** WordPress plugin that lets you send HTML bulk emails directly to your registered WordPress and WooCommerce users — no external service, no monthly fee.

## Features

* **Send directly from your admin panel** — uses WordPress's built-in `wp_mail()` / your server's mail stack.
* **HTML email** — compose rich messages with the native WordPress visual editor.
* **Role-based targeting** — filter recipients by any WordPress role (Subscriber, Customer, Editor, Administrator, etc.).  Select *All Users* to reach everyone at once.
* **WooCommerce-aware** — the *Customer* role added by WooCommerce is automatically listed alongside all other roles.
* **Zero cost** — completely free and open source (GPL-2.0-or-later).

## Requirements

* WordPress 5.0+
* PHP 7.4+
* WooCommerce (optional — Customer role is detected automatically if active)

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   cd wp-content/plugins
   git clone https://github.com/K5SMJ/Bulk_Customer_Email.git bulk-customer-email
   ```
2. In your WordPress admin go to **Plugins → Installed Plugins** and activate **Bulk Customer Email**.

## Usage

1. Navigate to **Bulk Email** in the WordPress admin sidebar.
2. Choose a **role** from the drop-down (or leave it as *All Users*).
3. Enter a **Subject**.
4. Write your **Message** — the editor supports full HTML/rich text.
5. Click **Send Emails**.

A success notice will tell you how many emails were dispatched.

## Frequently Asked Questions

**Why is this free?**  
Sending email to your own users is a basic feature that no one should have to pay a subscription for.

**Does it work without WooCommerce?**  
Yes — it works with any standard WordPress installation and its built-in roles.

**Does it batch large lists?**  
The current release sends emails synchronously in a single request.  For very large lists (thousands of users) make sure your PHP `max_execution_time` is set high enough, or consider a task-queue plugin.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
