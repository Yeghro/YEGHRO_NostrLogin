=== Nostr Login ===
Contributors: YEGHRO
Tags: login, authentication, nostr, Bitcoin, lightning Network
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable login and registration using Nostr keys for your WordPress site.

== Description ==

Nostr Login is a WordPress plugin that allows users to log in and register using their Nostr keys. This plugin integrates the decentralized Nostr protocol with WordPress, providing a seamless authentication experience for Nostr users.

Key features:
* Login using Nostr private key
* Login using Nostr browser extension (NIP-07 compatible)
* Automatic user registration for new Nostr users
* Profile information syncing from Nostr metadata
* Custom avatar support using Nostr profile pictures
* Admin settings for configuring Nostr relays

This plugin is perfect for WordPress site owners who want to offer their Nostr-using audience a familiar and secure way to interact with their website.

== Installation ==

1. Upload the `nostr-login` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Nostr Login to configure the plugin settings

== Frequently Asked Questions ==

= What is Nostr? =

Nostr (Notes and Other Stuff Transmitted by Relays) is a decentralized protocol that enables global, censorship-resistant social media.

= Is it safe to use my Nostr private key to log in? =

While the plugin is designed with security in mind, it's generally recommended to use a Nostr-compatible browser extension for the most secure experience. The plugin supports this method of authentication.

= Can I use this plugin alongside traditional WordPress login? =

Yes, this plugin adds Nostr login as an additional option. Traditional WordPress login remains available.

= How does user registration work? =

When a user logs in with Nostr for the first time, a new WordPress user account is automatically created using their Nostr public key and metadata.

== Screenshots ==

1. Nostr login toggle on the WordPress login page
2. Nostr login fields and buttons
3. Admin settings page for configuring Nostr relays

== Changelog ==

= 1.0 =
* Initial release

== Upgrade Notice ==

= 1.0 =
Initial release of the Nostr Login plugin.

== Additional Information ==

= Usage =

After activating the plugin, users will see a "Use Nostr Login" toggle on the WordPress login page. When enabled, users can either enter their Nostr private key or use a compatible Nostr browser extension to log in.

For first-time users, the plugin will automatically create a new WordPress account using the Nostr public key and available profile information.

= Configuration =

Administrators can configure the Nostr relays used by the plugin under 'Settings' > 'Nostr Login'. By default, the plugin uses a set of predefined relays, but these can be customized to suit your needs.

= Development =

This plugin is open source and we welcome contributions. For support or to report issues, please visit the plugin's GitHub repository (link to be added).

= For More Information =

For more information about Nostr, visit [nostr.com](https://nostr.com).