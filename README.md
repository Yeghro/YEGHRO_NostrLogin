=== YEGHRO Nostr Login ===
Contributors: yeghro
Donate link: https://getalby.com/p/yeghro
Tags: nostr, login, authentication, bitcoin, decentralized
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.7
Requires PHP: 7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enable secure WordPress authentication using Nostr keys - login with your Nostr identity.

== Description ==

YEGHRO Nostr Login enables WordPress users to authenticate using their Nostr keys, providing a seamless bridge between the decentralized Nostr protocol and WordPress websites.

= Key Features =

* One-click login with Nostr browser extensions (NIP-07 compatible)
* Automatic user registration for new Nostr users
* Profile synchronization from Nostr metadata
* Configurable Nostr relay settings

== Installation ==

1. Upload `nostr-login` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings > Nostr Login to configure relay settings

== Changelog ==

= 1.7 =
* 1.6 modifcation proved to be unstable. Reverted to more stable and secure setup that requires user to 
  manually install PHP-GMP extension to ensure secure authenticated login. Instructions are below in FAQ.

= 1.6 =
* nip98 modifications.

= 1.5 =
* Added more robust authentication flow using nip98 for better

= 1.4 =
* Added option to redirect users after login (to admin, home or profile page)

= 1.3 =
* Added option for existing Wordpress users to connect/sync their Nostr accounts from within profile profile page

= 1.2 =
* Enhanced profile synchronization
* Improved relay configuration options
* Bug fixes and performance improvements

= 1.0 =
* Initial release
* Automatic user registration
* Basic profile sync features

== Frequently Asked Questions ==

= What is Nostr? =
Nostr is a decentralized protocol enabling censorship-resistant social networking and authentication.

= Is it safe to use my Nostr keys? =
We recommend using a NIP-07 compatible browser extension like Alby or nos2x for the safest experience.

= How do I install the required PHP-GMP extension? =
The PHP-GMP extension is required for secure cryptographic operations. Here's how to install it:

For Ubuntu/Debian:
1. Run: `sudo apt-get update && sudo apt-get install php-gmp`
2. Restart PHP/web server: `sudo service php-fpm restart` (or apache2 if using Apache)

For CPanel:
1. Contact your hosting provider to enable the PHP-GMP module
2. Most managed WordPress hosts can enable this through the hosting control panel

For Windows:
1. Open php.ini file
2. Uncomment the line: extension=gmp
3. Restart your web server

After installation, verify GMP is enabled by checking your site's PHP info page.