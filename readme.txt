=== PuppyPress Migrator ===
Contributors: timofricke
Tags: migration, import, export, dogs, breeding
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moves all club data from a site with the "Extra Child" theme to a site with PuppyPress, remapping every ID.

== Description ==

Exports stud dogs, pedigrees, litters, dogs to place, approvals, breeding licenses, results, shows, trimming proofs, dog sitters, literature, kennels, breeders, owners, media, posts, pages, menus, comments and widgets into one ZIP package and imports it into another site. All references between records are rewritten to the new IDs.

Works in resumable steps (no timeouts), never duplicates records when run twice, never overwrites existing files and validates every file in the package. Includes WP-CLI commands.

== Installation ==

1. Install and activate the plugin on the old and on the new site.
2. On the new site, activate PuppyPress as well.
3. Old site: Tools → PuppyPress Migration → Create export.
4. New site: Tools → PuppyPress Migration → upload the ZIP → Start import.

== Changelog ==

= 1.2.0 =
* Jobs overview with status, progress, counters, log, Continue, Download and Delete.
* Paused jobs can be continued after reloading the page; a job never runs twice at the same time.
* WP-CLI: status and resume commands.
* Cleanup removes jobs three days after their last activity instead of their creation.

= 1.1.1 =
* Running the import again adds term assignments that were skipped because a taxonomy (e.g. et_post_format of Extra/Divi) was not registered.

= 1.1.0 =
* Media files can be downloaded from the old site during import instead of being packed (default). Needed for large media libraries.
* Separate messages for missing and rejected media files, including the reason.

= 1.0.0 =
* Initial release.
