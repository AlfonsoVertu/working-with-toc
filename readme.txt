=== Working with TOC ===

Contributors: workingwithweb
Tags: table of contents, seo, accessibility, structured data, multisite
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create a responsive, sticky table of contents with SEO-ready structured data.

![Working with TOC logo](assets/images/www-logo.png)

== Description ==
Working with TOC creates a polished, mobile-friendly table of contents (TOC) for articles, pages, and WooCommerce products.
The plugin respects WordPress coding conventions, splits responsibilities across dedicated folders, and integrates with Rank Math and Yoast SEO.
It stores its configuration using standard WordPress options and post meta, without creating custom database tables or version-tracking options.

= Key Features =
* Sticky accordion TOC fixed to the bottom of the viewport with touch-friendly controls.
* Automatic heading anchor generation (`<h2>`–`<h6>`) that keeps TOC links synchronized with on-page headings.
* Admin page with toggles to enable the TOC and Schema.org `ItemList` data by post type.
* JSON-LD output compatible with Rank Math and Yoast SEO schema graphs, avoiding duplicate markup.
* Conditional logging that respects the `WP_DEBUG` flag for safe troubleshooting in development environments.

= Admin Permissions =
The plugin registers the custom capability `manage_working_with_toc`. Administrators receive it automatically, and you can modify access via the `working_with_toc_admin_capability` filter:

```
add_filter( 'working_with_toc_admin_capability', function ( $capability ) {
    return 'edit_others_posts';
} );
```

= Plugin Structure =
```
working-with-toc/
├── working-with-toc.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
├── includes/
│   ├── admin/
│   │   └── class-admin-page.php
│   ├── frontend/
│   │   └── class-frontend.php
│   ├── structured-data/
│   │   └── class-structured-data-manager.php
│   ├── class-autoloader.php
│   ├── class-heading-parser.php
│   ├── class-logger.php
│   ├── class-plugin.php
│   └── class-settings.php
└── README.md
```

> Maintenance note: Legacy root-level loaders `class-toc-json.php` and `class-toc-product-json.php` were removed because the PSR
-4 autoloader now serves the modern classes from the `includes/` directory.

= Backend Settings =
The admin menu gains a **Working with TOC** page with individual cards for each content type. Toggles let you decide whether the TOC and structured data should load for posts, pages, or WooCommerce products. Gradients, soft shadows, and micro animations deliver a premium experience.

= Frontend Functionality =
The TOC appears in an accordion bar pinned to the bottom of the viewport. Visitors can expand or collapse it instantly; once open, the panel slides up and highlights the section currently in view using `IntersectionObserver`.

= SEO Compatibility =
* **Rank Math** – registers as a supported TOC to silence the “No TOC plugin installed” warning.
* **Yoast SEO** – injects structured data into the existing schema graph via the `wpseo_schema_graph` filter.
* **Schema.org** – outputs an `ItemList` node whose links mirror the heading anchors generated on the page.

= Logging and Debugging =
Initialization, settings saves, and schema rendering are routed to `error_log` whenever `WP_DEBUG` is enabled, keeping production environments clean while exposing helpful context in development.

= Quick Start Guide =
1. Copy the plugin folder into `wp-content/plugins/` and activate it.
2. Visit **Settings → Working with TOC** to choose where the TOC and structured data load.
3. Edit or create a post/product to see the sticky accordion TOC appended to the layout.
4. Confirm Rank Math or Yoast SEO recognizes the TOC and structured data in their analysis tools.

= Multisite Support =
Working with TOC has been validated on a WordPress multisite network (subdirectory mode) with three sites.

= Multisite QA =
* **Per-site activation** – activating on an individual site adds the capability via `Plugin::ensure_capability()` and stores preferences in the site-specific `wwt_toc_settings` option so each blog keeps separate settings.
* **Network Activate** – running the plugin from the Network Admin dashboard bootstraps it everywhere and grants administrators the `manage_working_with_toc` capability automatically.
* **Roles and capabilities** – roles inheriting `manage_options` receive the capability, so tools like `wp cap list administrator` can confirm the assignment.
* **Classic and block editors** – the metabox in `includes/admin/class-meta-box.php` loads on `post.php` and `post-new.php`, enqueueing `admin.css` and `admin.js` while storing preferences in `_wwt_toc_meta`.
* **Frontend** – when enabled for a post type, `Frontend::enqueue_assets()` registers the frontend CSS/JS and `Frontend::inject_toc()` renders the markup with site- and post-level preferences.

== Installation ==
1. Upload the `working-with-toc` folder to `/wp-content/plugins/` or install it from the WordPress admin.
2. Activate the plugin through the **Plugins** menu.
3. Visit **Settings → Working with TOC** to decide where the TOC and structured data should be enabled.

== Frequently Asked Questions ==
= Which post types are supported? =
Posts, pages, and WooCommerce products can generate the table of contents and structured data.

= Does the plugin add schema markup? =
Yes. The plugin outputs a Schema.org `ItemList` node that integrates with Rank Math or Yoast SEO graphs.

= What are the minimum requirements? =
Working with TOC requires WordPress 6.0 or higher and PHP 7.4 or higher, and it is released under the GPLv2 or later license.

= Is the plugin compatible with WordPress multisite? =
Yes. The plugin has been verified on multisite networks. You can activate it per-site for independent settings, or network-activate it to make the admin page available across all sites while each site retains its own configuration.

== Screenshots ==
1. Sticky accordion TOC displayed on the frontend.
   ![Sticky accordion TOC displayed on the frontend](assets/images/screenshot-1.png)
2. Settings page with post-type specific toggles.
   ![Settings page with post-type specific toggles](assets/images/screenshot-2.png)
3. Organization structured data defaults configured in the admin.
   ![Organization structured data defaults configured in the admin](assets/images/screenshot-3.png)

== Changelog ==
= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 1.0.0 =
Initial public release under the GPLv2 or later license.
