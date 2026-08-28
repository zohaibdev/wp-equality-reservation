=== Equality Reservation Form ===
Contributors: yourname
Tags: form, multi-step, reservation, ajax, shortcode, car rental
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A multi-step "Reservation Request" form embedded inline with a shortcode, submitted via AJAX, and emailed to you.

== Description ==

This plugin adds a 3-step reservation form to any page or post. It is embedded directly in the
content (no popup / modal, no close button). Submissions are sent over AJAX without a page reload
and emailed to the recipient you configure.

Steps:
1. Basic information — full name, email, phone.
2. Trip details — date, car type, number of passengers.
3. Driver details — age, driving license type, agree to terms & conditions.

Features:
* Inline embedding through a shortcode.
* AJAX submission with a loading state and a success message.
* Front-end and server-side validation.
* WordPress nonce + honeypot spam protection.
* HTML email with Reply-To set to the visitor, so you can reply directly.
* Settings page to set the recipient, subject, and dropdown options.

== Installation ==

1. In wp-admin go to Plugins → Add New → Upload Plugin.
2. Upload `equality-reservation-form.zip` and click "Install Now", then "Activate".
3. Go to Settings → Reservation Form and enter the email address that should receive submissions.
4. Add the shortcode below to any page or post.

== Usage ==

Basic:

    [reservation_form]

With options:

    [reservation_form title="Book Your Vehicle" subtitle="Tell us about your trip" bg="https://your-site.com/car.jpg" terms_url="/terms-and-conditions"]

Attributes:
* title      — heading text (default: "Reservation Request").
* subtitle   — sub-heading text.
* bg         — optional background image URL for the card.
* terms_url  — URL the "terms & conditions" link points to.

== Notes on email delivery ==

The plugin uses WordPress's built-in wp_mail() function. If emails do not arrive, your server may
not be configured to send mail reliably — install an SMTP plugin (for example "WP Mail SMTP") and
connect it to your email provider.

To change the recipient programmatically you can also use a filter in your theme:

    add_filter( 'erf_recipient_email', function ( $email ) {
        return 'bookings@your-site.com';
    } );

== Changelog ==

= 1.0.0 =
* Initial release.
