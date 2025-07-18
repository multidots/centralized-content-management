=== Centralized Content Management ===
Contributors: Multidots
Tags: multisite, content sync, network management, automation, WordPress
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==
The Centralized Content Management (CCM) plugin enables seamless content management across WordPress multisite networks. With this plugin, you can create and update content on a central site and effortlessly synchronize it across selected subsites in your network. The plugin simplifies multisite management by allowing selective syncing of posts, taxonomies, media, and more.

== Key Features ==
* **Multisite Syncing**: Syncs posts, pages, and custom post types from a central site to selected subsites in the network.
* **Selective Sync Options**: Choose which post types, taxonomies, and post metadata to sync across subsites.
* **Media & Taxonomy Handling**: Syncs media files and taxonomy terms between the central and selected subsites.
* **Permissions Control**: Allows content modification restrictions on subsites based on central site settings.
* **Relational Fields Support**: Maintains post relationships and associations across central and subsite content.
* **Deletion Settings**: Automatically remove content from subsites when deleted from the central site.
* **User-Friendly UI**: Easy-to-use admin interface to configure syncing options and manage content settings.

== Installation ==
1. Upload the `centralized-content-management` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the plugin settings page in the network admin to configure syncing options and select subsites for content sync.

== Frequently Asked Questions ==
= How do I select which subsites to sync content with? =
Visit the plugin's settings page in the network admin and choose the subsites you want to include in the sync.

= What happens if I delete content from the central site? =
The plugin offers options to automatically delete the corresponding content from selected subsites to keep them in sync.

= Can I restrict editing on subsites? =
Yes, you can restrict content editing on subsites based on the settings configured in the plugin, ensuring centralized control over synced content.

== Changelog ==
= 1.0 =
* Initial release of the Centralized Content Management plugin.

== Upgrade Notice ==
= 1.0 =
Initial release. No upgrades available yet.

== Screenshots ==
1. Network settings page where administrators can select the central site and choose subsites for content syncing.
2. Central site settings page for managing sync preferences, including post types, taxonomies, media, and relational fields.
3. Single sync feature on post creation/edit screen, enabling users to select specific subsites for syncing individual posts.
4. Bulk sync page, allowing administrators to sync content across multiple subsites simultaneously.
5. Logs page displaying detailed sync activity records for tracking changes and updates across the multisite network.

== Support ==
For support, please visit [your support forum link or website].

== License ==
This plugin is licensed under the GPLv2 or later license.
