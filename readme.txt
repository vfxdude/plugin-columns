=== Plugin Columns ===

Contributors: coderdimension
Tags: plugin columns, plugins, plugin, categories, columns, manager, organizer, groups, folders, sort, filter
Requires at least: 4.5
Tested up to: 5.4.1
Requires PHP: 5.6
Stable tag: 1.2.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Plugin Columns adds several columns to the plugins list (Categories, dates, counters). Useful if you have a lot of plugins installed to filter by categories and sort by install date etc.

== Description ==

Plugin Columns adds several columns to the plugins list (Categories, dates, counters). Useful if you have a lot of plugins installed to filter by categories and sort by install date etc.

= Categories =

Add plugins to Categories. Right click (or ctrl/command click) on column headers to access the options (it can also be accessed in the screen options) to create categories. Plugins can be added to categories by clicking the pencil icon in the category column for the plugin or with bulk edit. In the options the categories can be pinned to the plugins menu (click the pin icon next to the category name) and also hidden from the plugins list (only show up in the category list). There is a category filter select at the top of the plugins list, that will only show if categories has been created. Categories can also be filtered by clicking on the category link in the column.

= Sorting =

All columns can be sorted by clicking on the columns header, and there is also a sort dropdown list (that will show the column if it's hidden).

= Pin and hide categories =

Categories can be pinned to the plugin menu, and also hidden from the main plugin list. This way important plugins can have their own list/area so they are not messed with by clients etc.

= Display a Warning message on plugin deactivation =

Categories can have a warning message confirm modal shown on plugin deactivation. This is useful if it's a required framework plugin that you don't want your customer to deactivate to mess up the website. It also possible to hide plugins from the main plugin list, but it will show up in category filters though.

= Prevent plugin updates =

Categories can have a feature to block updates for plugins added to it. This can be useful for plugins that can potentially break the website if the client updates it.

= Trash =

Deleted plugins will be added to a trash list (like posts and pages). From there they can be reinstalled or removed (bulk actions also work).

= Export/import =

Plugins and it's categories can be exported and then imported at another installation. An import list will show the imported plugins and from there they can be installed. The import link will appear at the top links (all, active etc.). This can also be used to backup the categories, since when importing it will add the categories to installed plugins. There is also a backup feature where everything is backup up to a file (use the import feature to restore the backup).

= Multisite support =

The plugin works with multisite installations. The network admin will have more options (import/export and clear), and certain columns like activated will be unique per site. Pinned and hidden categories are also per site.

= Update/install columns =

The update column fetches the dates from the file system (and when a plugin is updated), but the install date will only be added when a plugin is installed. The install date could have been populated with file dates like the updated column, but that would probably be the update date and not the install date since those dates change when plugins are updated. Sorting by install date will show recently installed plugins, and that is it's intended use, and not seeing historical data.

= Folder column =

Will show the plugin folder name. Sometimes the folder name does not match the plugin name so it can be hard to find, so this makes it easier.

= Source column =

Will show if the plugin is hosted on wordpress.org, Github etc. 

= Counters =

Counters are an addition to the updated and activated columns to identify plugins that update frequently (or opposite) and plugins that have been activated/deactivated many times. The activated counter is useful when testing plugins compatibility, then it becomes a most popular list.

== Installation ==

1. Click the 'Plugins' menu option in WordPress and then 'Add New'.
2. Choose 'Tag' and Search for 'plugin columns'.
3. Install and activate the plugin.
4. Right click (or ctrl/command click) a column header to add more columns (or select them in the screen options).
5. Create a plugin category by selecting options in the dialog that appears when right clicking a column header.
6. Add a plugin to a category by clicking on the pencil icon in the category column for the plugin or use the bulk action.

== Screenshots ==

1. **Show columns, add categories and sort.**
2. **Options and pinned categories in the plugin menu.**

== Changelog ==

= 1.2.1 =
* Fix: Warning for plugin deactivation in multisite network admin.
* Fix: The no-update category feature.

= 1.2 =
* New: Category feature to prevent plugin updates.
* New: Wordpress.org meta columns (rating, downloaded, last updated, added and screenshots added to the description column).
* New: Prevent redirection on activation option.
* New: Prevent feedback dialog on deactivation option.
* New: Sticky column header option.

= 1.1.2 =
* Tweak: Plugins installed with the zip installer or copied to the plugin folder now gets an install date.
* Fix: Categories with space bug.

= 1.1.1 =
* Fix: Number sorting.

= 1.1 =
* New: More columns (folder, source and author).
* New: Backup feature.
* New: Deactivate warning feature.

= 1.0.0 =
* The first release of Plugins Columns on wordpress.org.
 