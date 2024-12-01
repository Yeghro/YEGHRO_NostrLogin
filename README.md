=== YEGHRO Nostr Login ===
Contributors: yeghro
Donate link: https://getalby.com/p/yeghro
Tags: nostr, login, authentication, bitcoin, decentralized
Requires at least: 5.0
Tested up to: 6.6.2
Stable tag: 1.3
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

== Frequently Asked Questions ==

= What is Nostr? =
Nostr is a decentralized protocol enabling censorship-resistant social networking and authentication.

= Is it safe to use my Nostr keys? =
We recommend using a NIP-07 compatible browser extension like Alby or nos2x for the safest experience.

== Can I use this plugin alongside traditional WordPress login? ==

Yes, the plugin adds Nostr login as an additional option without removing the traditional WordPress login method.

## Screenshots

1. Nostr login toggle on the WordPress login page
   <br>
   <img src="https://github.com/user-attachments/assets/8ae6a901-cc94-402b-836b-6560ec64b477" alt="Nostr login toggle" width="300">
   <div style="clear: both;"></div>

2. Nostr login fields and buttons
   <br>
   <img src="https://github.com/user-attachments/assets/0805e84c-75e4-4f19-b22d-7b50535098f7" alt="Nostr login fields" width="300">
   <div style="clear: both;"></div>

3. Admin settings page for configuring Nostr relays
   <br>
   <img src="https://github.com/user-attachments/assets/eb2c1aa8-335f-4cb9-b3de-34e4ec06ed06" alt="Admin settings" width="500">
   <div style="clear: both;"></div>




== Changelog ==

= 1.4 =
* Added option to redirect users after login (to admin, home or profile page)

= 1.3 =
* Added option for existing Wordpress users to connect/sync their Nostr accounts from within profile page

= 1.2 =
* Enhanced profile synchronization
* Improved relay configuration options
* Bug fixes and performance improvements

= 1.0 =
* Initial release
* Automatic user registration
* Basic profile sync features

## Additional Information

### Usage

After activating the plugin, users will see a "Use Nostr Login" toggle on the WordPress login page. When enabled, users can either enter their Nostr private key or use a compatible Nostr browser extension to log in.

For first-time users, the plugin will automatically create a new WordPress account using the Nostr public key and available profile information.

### Configuration

Administrators can configure the Nostr relays used by the plugin under 'Settings' > 'Nostr Login'. By default, the plugin uses a set of predefined relays, but these can be customized to suit your needs.

### Development

This plugin is open source and we welcome contributions. 
