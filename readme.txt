=== Working With TOC ===
Contributors: workingwithweb
Tags: table of contents, seo, accessibility, structured data, multisite
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Working with TOC creates a polished, mobile-friendly table of contents (TOC) for articles, pages, and WooCommerce products.
The plugin respects WordPress coding conventions, splits responsibilities across dedicated folders, and integrates with Rank Math and Yoast SEO.

= Key Features =
* Sticky accordion TOC fixed to the bottom of the viewport with touch-friendly controls.
* Automatic heading anchor generation (`<h2>`–`<h6>`) that keeps TOC links synchronized with on-page headings.
* Admin page with toggles to enable the TOC and Schema.org `TableOfContents` data by post type.
* JSON-LD output compatible with Rank Math and Yoast SEO schema graphs, avoiding duplicate markup.
* Conditional logging that respects the `WP_DEBUG` flag for safe troubleshooting in development environments.

= Admin Permissions =
The plugin registers the custom capability `manage_working_with_toc`. Administrators receive it automatically, and you can modify access via the `working_with_toc_admin_capability` filter:

```
add_filter( 'working_with_toc_admin_capability', function ( $capability ) {
    return 'edit_others_posts';
} );
```

== Installation ==
1. Upload the `working-with-toc` folder to `/wp-content/plugins/` or install it from the WordPress admin.
2. Activate the plugin through the **Plugins** menu.
3. Visit **Settings → Working with TOC** to decide where the TOC and structured data should be enabled.

== Frequently Asked Questions ==
= Which post types are supported? =
Posts, pages, and WooCommerce products can generate the table of contents and structured data.

= Does the plugin add schema markup? =
Yes. The plugin outputs a Schema.org `TableOfContents` node that integrates with Rank Math or Yoast SEO graphs.

= What are the minimum requirements? =
Working with TOC requires WordPress 6.0 or higher and PHP 7.4 or higher, and it is released under the GPLv2 or later license.

= Is the plugin compatible with WordPress multisite? =
Yes. The plugin has been verified on multisite networks. You can activate it per-site for independent settings, or network-activate it to make the admin page available across all sites while each site retains its own configuration.

== Screenshots ==
1. Sticky accordion TOC displayed on the frontend.
2. Settings page with post-type specific toggles.

== Changelog ==
= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 1.0.0 =
Initial public release under the GPLv2 or later license.
