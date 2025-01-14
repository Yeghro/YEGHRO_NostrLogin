=== YEGHRO Nostr Login ===
Contributors: yeghro
Donate link: https://getalby.com/p/yeghro
Tags: nostr, login, authentication, bitcoin, decentralized
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.5
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