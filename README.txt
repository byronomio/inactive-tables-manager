=== Inactive Tables Manager ===
Contributors: byronjacobs
Tags: database, cleanup, tables, maintenance
Requires at least: 4.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean up your WordPress database by managing tables left behind by inactive plugins.

== Description ==
Inactive Tables Manager helps you identify and clean up database tables that were created by plugins you're no longer using. This powerful tool helps optimize your database by removing unnecessary data that can slow down your site.

Key benefits:
- Reclaim valuable database space
- Improve site performance
- Clean up after deactivated plugins
- Easy-to-use interface with safety confirmations
- Detailed table statistics before taking action

== Key Features ==
### Core Functionality
- **Automatic Detection**: Scans your database for tables from inactive plugins
- **Detailed Statistics**: Shows row count and size for each inactive table
- **Multiple Management Options**:
  - Truncate (empty) individual tables
  - Drop (delete) individual tables
  - Bulk actions for multiple tables
  - Empty all inactive tables at once
  - Drop all inactive tables at once
- **Safety Features**:
  - Confirmation dialogs for all destructive actions
  - Nonce verification and capability checks
  - Clear success/error messages
  - Excludes WordPress core tables automatically


== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/inactive-tables-manager` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to 'Inactive Tables' in the WordPress admin menu to manage your tables.


== Frequently Asked Questions ==
= What does this plugin do? =
It finds database tables from inactive plugins and allows you to safely remove them or just empty their contents.

= Is it safe to use this plugin? =
Yes, the plugin includes multiple safety measures:
- Never touches WordPress core tables
- Requires confirmation for all destructive actions
- Includes capability checks
- Provides clear feedback about each operation

= What's the difference between truncate and drop? =
- **Truncate**: Empties the table but keeps its structure (all rows are deleted)
- **Drop**: Completely removes the table from the database

= Will this affect my active plugins? =
No, the plugin only identifies tables from plugins that are currently inactive.

= Can I recover tables after dropping them? =
No, dropped tables are permanently deleted. Always backup your database before performing major operations.
