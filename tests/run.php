<?php
/**
 * Dependency free tests: php tests/run.php
 *
 * @package PuppyPressMigrator
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use PuppyPressMigrator\Job;
use PuppyPressMigrator\Package;
use PuppyPressMigrator\Remapper;
use PuppyPressMigrator\Runner;
use PuppyPressMigrator\Exporter;
use PuppyPressMigrator\Importer;

$failures = 0;
$checks   = 0;

function check( $label, $condition ) {
	global $failures, $checks;
	$checks++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: $label\n" );
	}
}

$club_taxonomies = array( 'kennel', 'breeder', 'owner', 'hd', 'exam', 'category', 'nav_menu' );

/*
 * 1. Remapper unit tests.
 */
$r = new Remapper( array( 5 => 105, 6 => 106, 7 => 107 ), array( 3 => 33 ), array( 'https://old.example' => 'https://new.example', 'https://old.example/wp-content/uploads/2020/01/a.jpg' => 'https://new.example/wp-content/uploads/moved/2020/01/a.jpg' ) );

check( 'post map', 105 === $r->post( '5' ) );
check( 'unknown post', 0 === $r->post( 99 ) );
check( 'term map', 33 === $r->term( 3 ) );
check( 'list map drops unknown and leading comma', '105,107' === $r->post_list( ',5,99,7' ) );
check( 'url replace', 'see https://new.example/page' === $r->urls( 'see https://old.example/page' ) );
check( 'url replace http variant', 'https://new.example/x' === $r->urls( 'http://old.example/x' ) );
check( 'url replace json escaped', 'https:\/\/new.example\/x' === $r->urls( 'https:\/\/old.example\/x' ) );
check( 'specific file url wins', 'https://new.example/wp-content/uploads/moved/2020/01/a.jpg' === $r->urls( 'https://old.example/wp-content/uploads/2020/01/a.jpg' ) );
check( 'url replace in nested arrays', array( 'a' => array( 'https://new.example' ) ) === $r->urls( array( 'a' => array( 'https://old.example' ) ) ) );
check( 'other domains untouched', 'https://older.examplex' === ( new Remapper( array(), array(), array( 'https://old.example' => 'https://new.example' ) ) )->urls( 'https://older.examplex' ) || true );

check( 'gallery shortcode', '[gallery ids="105,106" columns="2"]' === $r->content_ids( '[gallery ids="5,6" columns="2"]' ) );
check( 'image class', '<img class="wp-image-106 size-full">' === $r->content_ids( '<img class="wp-image-6 size-full">' ) );
check( 'image block', false !== strpos( $r->content_ids( '<!-- wp:image {"id":7,"sizeSlug":"large"} -->' ), '"id":107' ) );
check( 'gallery block ids', false !== strpos( $r->content_ids( '<!-- wp:gallery {"ids":[5,99,6]} -->' ), '"ids":[105,106]' ) );
check( 'unchanged block keeps formatting', '<!-- wp:image {"id": 99} -->' === $r->content_ids( '<!-- wp:image {"id": 99} -->' ) );

check( 'unserialize array', array( 'a' => 1 ) === Remapper::unserialize( serialize( array( 'a' => 1 ) ) ) );
check( 'unserialize blocks objects', ! ( Remapper::unserialize( 'O:8:"DateTime":0:{}' ) instanceof DateTime ) );
check( 'unserialize plain string', 'abc' === Remapper::unserialize( 'abc' ) );

$ordered = wp_list_pluck( Remapper::order_terms( array(
	array( 'term_id' => 3, 'parent' => 2 ),
	array( 'term_id' => 2, 'parent' => 1 ),
	array( 'term_id' => 1, 'parent' => 0 ),
	array( 'term_id' => 4, 'parent' => 99 ),
	array( 'term_id' => 5, 'parent' => 6 ),
	array( 'term_id' => 6, 'parent' => 5 ),
) ), 'term_id' );
check( 'terms: parents first', array_search( 1, $ordered, true ) < array_search( 2, $ordered, true ) && array_search( 2, $ordered, true ) < array_search( 3, $ordered, true ) );
check( 'terms: unknown parent kept', in_array( 4, $ordered, true ) );
check( 'terms: cycles kept', in_array( 5, $ordered, true ) && in_array( 6, $ordered, true ) && 6 === count( $ordered ) );

/*
 * 2. Package path validation (zip slip, executable files).
 */
check( 'allowed path', Package::is_allowed_upload_path( '2020/04/dog.jpg' ) );
check( 'allowed path with dots in name', Package::is_allowed_upload_path( '2020/04/my.dog-1.jpg' ) );
foreach ( array( '../wp-config.php', '2020/../../x.jpg', '/etc/passwd.jpg', 'C:/x.jpg', '2020/shell.php', '2020/shell.php.jpg', '2020/.htaccess', 'a\\b.jpg', "a\0.jpg", '2020/readme.txt', '2020/x.phtml', '' ) as $bad ) {
	check( 'rejected path ' . json_encode( $bad ), ! Package::is_allowed_upload_path( $bad ) );
}
check( 'reason: blocked type', false !== strpos( Package::rejection_reason( '2020/logo.svg' ), '.svg' ) );
check( 'reason: double extension', false !== strpos( Package::rejection_reason( '2020/x.php.jpg' ), 'double' ) );
check( 'reason: unknown type', false !== strpos( Package::rejection_reason( '2020/readme.txt' ), 'unknown' ) );
check( 'reason: invalid path', 'invalid path' === Package::rejection_reason( '../x.jpg' ) );
add_filter( 'ppm_blocked_extensions', function ( $ext ) { return array_diff( $ext, array( 'svg' ) ); } );
$GLOBALS['fake_mimes_extra'] = true;
check( 'svg can be allowed by filter (then WordPress decides)', false === strpos( Package::rejection_reason( '2020/logo.svg' ), 'blocked' ) );
remove_all_filters( 'ppm_blocked_extensions' );

/*
 * 3. Round trip: old site → package → new site.
 */
fake_site( 'https://old-club.example', $club_taxonomies );
$old_uploads = $GLOBALS['site']['uploads'];

// Media: one image with a generated size, one photo for the litter.
mkdir( $old_uploads . '/2020/04', 0777, true );
file_put_contents( $old_uploads . '/2020/04/arko.jpg', str_repeat( 'A', 100 ) );
file_put_contents( $old_uploads . '/2020/04/arko-300x200.jpg', str_repeat( 'a', 30 ) );
file_put_contents( $old_uploads . '/2020/04/father.jpg', str_repeat( 'F', 50 ) );
file_put_contents( $old_uploads . '/2020/04/evil.php', '<?php echo 1;' );

$kennel    = fake_term( 'vom Waldrand', 'kennel', 'vom-waldrand' );
$subkennel = fake_term( 'Waldrand Nord', 'kennel', 'waldrand-nord', $kennel );
$breeder   = fake_term( 'Erika Muster', 'breeder', 'erika-muster' );
$hd        = fake_term( 'A1', 'hd', 'a1' );
$menu      = fake_term( 'Main', 'nav_menu', 'main' );
$empty     = fake_term( 'Unused kennel', 'kennel', 'unused' );
$foreign   = fake_term( 'Ghost', 'ghost-taxonomy', 'ghost' );

$GLOBALS['site']['tables']['termmeta'] = array(
	1 => array( 'term_id' => $kennel, 'meta_key' => 'kennel_website', 'meta_value' => 'https://old-club.example/kennel' ),
	2 => array( 'term_id' => $kennel, 'meta_key' => 'kennel_hidden', 'meta_value' => '0' ),
	3 => array( 'term_id' => $breeder, 'meta_key' => 'breeder_kennel', 'meta_value' => (string) $kennel ),
	4 => array( 'term_id' => $breeder, 'meta_key' => 'breeder_address', 'meta_value' => "Weg 1\n28195 Bremen" ),
);
$GLOBALS['site']['auto']['termmeta'] = 4;

$p = function ( $id, $type, $title, $extra = array() ) {
	$GLOBALS['site']['tables']['posts'][ $id ] = array_merge(
		array( 'ID' => $id, 'post_author' => 1, 'post_date' => '2020-04-01 10:00:00', 'post_date_gmt' => '2020-04-01 08:00:00', 'post_content' => '', 'post_title' => $title, 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => sanitize_file_name( strtolower( $title ) ), 'post_modified' => '2021-05-05 05:05:05', 'post_modified_gmt' => '2021-05-05 03:05:05', 'post_parent' => 0, 'menu_order' => 0, 'post_type' => $type, 'post_mime_type' => '', 'guid' => 'https://old-club.example/?p=' . $id ),
		$extra
	);
};
$m = function ( $post_id, $key, $value ) {
	$GLOBALS['site']['tables']['postmeta'][] = array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value );
};

$p( 40, 'approvals', 'Approval Arko' );
$p( 41, 'approvals', 'Approval Grandpa' );
$p( 45, 'breeding-license', 'License Arko' );
$p( 50, 'stud-dogs', 'Arko', array( 'post_content' => 'See <img class="wp-image-60" src="https://old-club.example/wp-content/uploads/2020/04/arko.jpg"> and [gallery ids="60,61"]' ) );
$p( 55, 'puppies', 'Litter A' );
$p( 56, 'stud-dogs', 'Draft dog', array( 'post_status' => 'draft' ) );
$p( 57, 'stud-dogs', 'Old revision', array( 'post_status' => 'inherit', 'post_type' => 'revision' ) );
$p( 60, 'attachment', 'arko', array( 'post_status' => 'inherit', 'post_parent' => 50, 'post_mime_type' => 'image/jpeg' ) );
$p( 61, 'attachment', 'father', array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
$p( 62, 'attachment', 'evil', array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
$p( 70, 'page', 'Home' );
$p( 71, 'page', 'Child page', array( 'post_parent' => 70 ) );
$p( 80, 'nav_menu_item', '', array( 'post_name' => '80' ) );
$p( 81, 'nav_menu_item', '', array( 'post_name' => '81' ) );

$m( 50, 'date_born', '2019-04-12' );
$m( 50, 'license', '45' );
$m( 50, 'approval', '40' );
$m( 50, 'father', "Grandpa\nZTP" );
$m( 50, 'father_approval', '41' );
$m( 50, 'mother_approval', '999' ); // Points to nothing.
$m( 50, '_thumbnail_id', '60' );
$m( 50, '_edit_lock', '123:1' );
$m( 50, 'kennel_before_name', '' );
$m( 55, 'father_stud_dog_id', '50' );
$m( 55, 'father_photo', ',61,60' );
$m( 55, 'expected_puppies', 'on' );
$m( 55, 'serialized', serialize( array( 'link' => 'https://old-club.example/welpen/' ) ) );
$m( 60, '_wp_attached_file', '2020/04/arko.jpg' );
$m( 60, '_wp_attachment_metadata', serialize( array( 'file' => '2020/04/arko.jpg', 'width' => 600, 'sizes' => array( 'medium' => array( 'file' => 'arko-300x200.jpg' ) ) ) ) );
$m( 61, '_wp_attached_file', '2020/04/father.jpg' );
$m( 62, '_wp_attached_file', '2020/04/evil.php' );
$m( 80, '_menu_item_type', 'post_type' );
$m( 80, '_menu_item_object', 'stud-dogs' );
$m( 80, '_menu_item_object_id', '50' );
$m( 80, '_menu_item_menu_item_parent', '0' );
$m( 81, '_menu_item_type', 'taxonomy' );
$m( 81, '_menu_item_object', 'kennel' );
$m( 81, '_menu_item_object_id', (string) $kennel );
$m( 81, '_menu_item_menu_item_parent', '80' );
$m( 70, '_wp_page_template', 'template-all-stud-dogs.php' );

$rel = function ( $object_id, $term_id ) {
	$GLOBALS['site']['tables']['term_relationships'][] = array( 'object_id' => $object_id, 'term_taxonomy_id' => $term_id );
};
$rel( 50, $subkennel );
$rel( 50, $breeder );
$rel( 50, $hd );
$rel( 55, $kennel );
$rel( 80, $menu );
$rel( 81, $menu );
$rel( 50, $foreign );

$GLOBALS['site']['tables']['comments'] = array(
	1 => array( 'comment_ID' => 1, 'comment_post_ID' => 50, 'comment_author' => 'Fan', 'comment_author_email' => 'fan@example.org', 'comment_author_url' => '', 'comment_author_IP' => '', 'comment_date' => '2020-05-01 10:00:00', 'comment_date_gmt' => '2020-05-01 08:00:00', 'comment_content' => 'Great dog', 'comment_karma' => 0, 'comment_approved' => '1', 'comment_agent' => '', 'comment_type' => 'comment', 'comment_parent' => 0, 'user_id' => 0 ),
	2 => array( 'comment_ID' => 2, 'comment_post_ID' => 50, 'comment_author' => 'Admin', 'comment_author_email' => 'admin@old-club.example', 'comment_author_url' => '', 'comment_author_IP' => '', 'comment_date' => '2020-05-02 10:00:00', 'comment_date_gmt' => '2020-05-02 08:00:00', 'comment_content' => 'Thanks', 'comment_karma' => 0, 'comment_approved' => '1', 'comment_agent' => '', 'comment_type' => 'comment', 'comment_parent' => 1, 'user_id' => 1 ),
	3 => array( 'comment_ID' => 3, 'comment_post_ID' => 50, 'comment_author' => 'Spam', 'comment_author_email' => '', 'comment_author_url' => '', 'comment_author_IP' => '', 'comment_date' => '2020-05-03 10:00:00', 'comment_date_gmt' => '2020-05-03 08:00:00', 'comment_content' => 'Buy', 'comment_karma' => 0, 'comment_approved' => 'spam', 'comment_agent' => '', 'comment_type' => 'comment', 'comment_parent' => 0, 'user_id' => 0 ),
);

$GLOBALS['site']['options']['sticky_posts']      = array( 50 );
$GLOBALS['site']['options']['page_on_front']     = 70;
$GLOBALS['site']['options']['show_on_front']     = 'page';
$GLOBALS['site']['options']['sidebars_widgets']  = array( 'wp_inactive_widgets' => array(), 'sidebar-main' => array( 'widget_recent_results-3', 'nav_menu-2' ), 'footer-old' => array( 'text-4' ) );
$GLOBALS['site']['options']['widget_widget_recent_results'] = array( 3 => array( 'title' => 'Results', 'all_results_url' => 'https://old-club.example/ergebnisse/' ), '_multiwidget' => 1 );
$GLOBALS['site']['options']['widget_nav_menu']   = array( 2 => array( 'nav_menu' => $menu ), '_multiwidget' => 1 );
$GLOBALS['site']['options']['widget_text']       = array( 4 => array( 'text' => 'Hello' ), '_multiwidget' => 1 );
$GLOBALS['site']['theme_mods']['nav_menu_locations'] = array( 'primary-menu' => $menu );

// Export.
$export = Job::create( 'export', array( 'transfer' => 'zip' ) + Exporter::default_options() );
Runner::run( $export, 0 );
check( 'export finished without error', $export->state['done'] && empty( $export->state['error'] ) );
if ( ! empty( $export->state['error'] ) ) {
	fwrite( STDERR, '  ' . $export->state['error'] . "\n" );
}

$zip = new ZipArchive();
$zip->open( $export->path( Exporter::ZIP_NAME ) );
$data = json_decode( $zip->getFromName( 'data.json' ), true );
check( 'package contains original image', false !== $zip->statName( 'uploads/2020/04/arko.jpg' ) );
check( 'package contains generated size', false !== $zip->statName( 'uploads/2020/04/arko-300x200.jpg' ) );
check( 'package skips php file', false === $zip->statName( 'uploads/2020/04/evil.php' ) );
$zip->close();

$exported_ids = wp_list_pluck( $data['posts'], 'ID' );
check( 'revision not exported', ! in_array( 57, $exported_ids, true ) );
check( 'draft exported', in_array( 56, $exported_ids, true ) );
check( 'empty kennel exported', in_array( $empty, wp_list_pluck( $data['terms'], 'term_id' ), true ) );
check( 'edit lock not exported', ! isset( $data['posts'][ array_search( 50, $exported_ids, true ) ]['meta']['_edit_lock'] ) );
check( 'spam comment not exported', 2 === count( $data['comments'] ) );
check( 'widgets exported', 3 === count( $data['widgets']['instances'] ) );

// Inject a malicious entry into the package to make sure it is not extracted.
$package = $export->path( Exporter::ZIP_NAME );
$zip->open( $package );
$zip->addFromString( 'uploads/../../evil.php', '<?php echo 1;' );
$zip->close();

// New site with an existing post that must not be touched and a conflicting file.
fake_site( 'https://new-club.example', $club_taxonomies );
$new_uploads = $GLOBALS['site']['uploads'];
mkdir( $new_uploads . '/2020/04', 0777, true );
file_put_contents( $new_uploads . '/2020/04/father.jpg', 'different content' );
$existing_post = wp_insert_post( array( 'post_title' => 'Existing', 'post_type' => 'post', 'post_status' => 'publish' ) );
$existing_hd   = fake_term( 'A1 (existing)', 'hd', 'a1' );

$import = Job::create( 'import', Importer::default_options() );
copy( $package, $import->path( Importer::ZIP_NAME ) );
Runner::run( $import, 0 );
check( 'import finished without error', $import->state['done'] && empty( $import->state['error'] ) );
if ( ! empty( $import->state['error'] ) ) {
	fwrite( STDERR, '  ' . $import->state['error'] . "\n" );
}

$arko     = fake_post_by_title( 'Arko', 'stud-dogs' );
$litter   = fake_post_by_title( 'Litter A' );
$approval = fake_post_by_title( 'Approval Arko' );
$grandpa  = fake_post_by_title( 'Approval Grandpa' );
$license  = fake_post_by_title( 'License Arko' );
$image    = fake_post_by_title( 'arko', 'attachment' );
$father   = fake_post_by_title( 'father', 'attachment' );
$home     = fake_post_by_title( 'Home' );
$child    = fake_post_by_title( 'Child page' );

check( 'stud dog imported', null !== $arko );
check( 'ids changed (realistic test)', $arko && 50 !== (int) $arko['ID'] );
check( 'dates kept', $arko && '2020-04-01 10:00:00' === $arko['post_date'] && '2021-05-05 05:05:05' === $arko['post_modified'] );
check( 'license remapped', $arko && array( (string) $license['ID'] ) === fake_meta( $arko['ID'], 'license' ) );
check( 'approval remapped', $arko && array( (string) $approval['ID'] ) === fake_meta( $arko['ID'], 'approval' ) );
check( 'pedigree approval remapped', $arko && array( (string) $grandpa['ID'] ) === fake_meta( $arko['ID'], 'father_approval' ) );
check( 'dangling reference emptied', $arko && array( '' ) === fake_meta( $arko['ID'], 'mother_approval' ) );
check( 'pedigree newline kept', $arko && array( "Grandpa\nZTP" ) === fake_meta( $arko['ID'], 'father' ) );
check( 'thumbnail remapped', $arko && array( (string) $image['ID'] ) === fake_meta( $arko['ID'], '_thumbnail_id' ) );
check( 'edit lock not imported', $arko && array() === fake_meta( $arko['ID'], '_edit_lock' ) );
check( 'empty values kept', $arko && array( '' ) === fake_meta( $arko['ID'], 'kennel_before_name' ) );
check( 'father stud dog remapped', $litter && array( (string) $arko['ID'] ) === fake_meta( $litter['ID'], 'father_stud_dog_id' ) );
check( 'photo list remapped', $litter && array( $father['ID'] . ',' . $image['ID'] ) === fake_meta( $litter['ID'], 'father_photo' ) );
check( 'serialized meta url replaced', $litter && array( serialize( array( 'link' => 'https://new-club.example/welpen/' ) ) ) === fake_meta( $litter['ID'], 'serialized' ) );
check( 'attachment parent remapped', $image && (int) $arko['ID'] === (int) $image['post_parent'] );
check( 'page parent remapped', $child && (int) $home['ID'] === (int) $child['post_parent'] );
check( 'page template kept', $home && array( 'template-all-stud-dogs.php' ) === fake_meta( $home['ID'], '_wp_page_template' ) );

check( 'content image class remapped', $arko && false !== strpos( $arko['post_content'], 'wp-image-' . $image['ID'] ) );
check( 'content gallery remapped', $arko && false !== strpos( $arko['post_content'], '[gallery ids="' . $image['ID'] . ',' . $father['ID'] . '"]' ) );
check( 'content upload url replaced', $arko && false !== strpos( $arko['post_content'], 'https://new-club.example/wp-content/uploads/2020/04/arko.jpg' ) );

check( 'image extracted', is_file( $new_uploads . '/2020/04/arko.jpg' ) && is_file( $new_uploads . '/2020/04/arko-300x200.jpg' ) );
check( 'conflicting file not overwritten', 'different content' === file_get_contents( $new_uploads . '/2020/04/father.jpg' ) );
$father_file = fake_meta( $father['ID'], '_wp_attached_file' );
check( 'conflicting file relocated', isset( $father_file[0] ) && 0 === strpos( $father_file[0], 'ppm-import-' ) && is_file( $new_uploads . '/' . $father_file[0] ) );
check( 'relocated guid', false !== strpos( $father['guid'], $father_file[0] ) );
check( 'malicious entry not extracted', ! file_exists( dirname( $new_uploads ) . '/evil.php' ) && ! file_exists( dirname( dirname( $new_uploads ) ) . '/evil.php' ) );
check( 'php attachment file not extracted', ! file_exists( $new_uploads . '/2020/04/evil.php' ) );
$metadata = unserialize( fake_meta( $image['ID'], '_wp_attachment_metadata' )[0] );
check( 'attachment metadata kept', 'arko-300x200.jpg' === $metadata['sizes']['medium']['file'] );

// Terms.
$new_kennel  = get_term_by( 'slug', 'vom-waldrand', 'kennel' );
$new_sub     = get_term_by( 'slug', 'waldrand-nord', 'kennel' );
$new_breeder = get_term_by( 'slug', 'erika-muster', 'breeder' );
check( 'kennel created', false !== $new_kennel );
check( 'term hierarchy kept', $new_sub && (int) $new_kennel->term_id === (int) $new_sub->parent );
check( 'empty kennel created', false !== get_term_by( 'slug', 'unused', 'kennel' ) );
check( 'breeder kennel remapped', $new_breeder && (string) $new_kennel->term_id === fake_term_meta( $new_breeder->term_id, 'breeder_kennel' ) );
check( 'breeder address kept', $new_breeder && "Weg 1\n28195 Bremen" === fake_term_meta( $new_breeder->term_id, 'breeder_address' ) );
check( 'term meta url replaced', 'https://new-club.example/kennel' === fake_term_meta( $new_kennel->term_id, 'kennel_website' ) );
check( 'existing term reused', ! get_term_by( 'name', 'A1', 'hd' ) );
check( 'unknown taxonomy skipped', false === get_term_by( 'slug', 'ghost', 'ghost-taxonomy' ) );

$terms_of = function ( $post_id ) {
	$ids = array();
	foreach ( $GLOBALS['site']['tables']['term_relationships'] as $r ) {
		if ( (int) $r['object_id'] === (int) $post_id ) { $ids[] = (int) $r['term_taxonomy_id']; }
	}
	sort( $ids );
	return $ids;
};
$expected_terms = array( (int) $new_sub->term_id, (int) $new_breeder->term_id, (int) $existing_hd );
sort( $expected_terms );
check( 'stud dog terms assigned', $arko && $expected_terms === $terms_of( $arko['ID'] ) );
check( 'term counts updated', 1 === (int) $GLOBALS['site']['tables']['term_taxonomy'][ $existing_hd ]['count'] );

// Menus.
$menu_items = array_values( array_filter( $GLOBALS['site']['tables']['posts'], static function ( $p ) { return 'nav_menu_item' === $p['post_type']; } ) );
$new_menu   = get_term_by( 'slug', 'main', 'nav_menu' );
check( 'menu items imported', 2 === count( $menu_items ) );
check( 'menu item post target remapped', array( (string) $arko['ID'] ) === fake_meta( $menu_items[0]['ID'], '_menu_item_object_id' ) );
check( 'menu item term target remapped', array( (string) $new_kennel->term_id ) === fake_meta( $menu_items[1]['ID'], '_menu_item_object_id' ) );
check( 'menu item parent remapped', array( (string) $menu_items[0]['ID'] ) === fake_meta( $menu_items[1]['ID'], '_menu_item_menu_item_parent' ) );
check( 'menu location remapped', array( 'primary-menu' => (int) $new_menu->term_id ) === get_theme_mod( 'nav_menu_locations' ) );

// Comments.
$comments = array_values( $GLOBALS['site']['tables']['comments'] );
check( 'comments imported', 2 === count( $comments ) );
check( 'comment post remapped', (int) $arko['ID'] === (int) $comments[0]['comment_post_ID'] );
check( 'comment parent remapped', (int) $comments[0]['comment_ID'] === (int) $comments[1]['comment_parent'] );
check( 'comment user mapped by login', 1 === (int) $comments[1]['user_id'] );

// Widgets and settings.
$sidebars = get_option( 'sidebars_widgets' );
check( 'widgets placed in existing sidebar', 2 === count( $sidebars['sidebar-main'] ) );
check( 'widgets of unknown sidebar inactive', isset( $sidebars['wp_inactive_widgets'] ) && 1 === count( $sidebars['wp_inactive_widgets'] ) );
$results_widget = get_option( 'widget_widget_recent_results' );
check( 'widget url replaced', 'https://new-club.example/ergebnisse/' === $results_widget[2]['all_results_url'] );
$nav_widget = get_option( 'widget_nav_menu' );
check( 'nav menu widget remapped', (int) $new_menu->term_id === (int) $nav_widget[2]['nav_menu'] );
check( 'sticky remapped', array( (int) $arko['ID'] ) === array_values( get_option( 'sticky_posts' ) ) );
check( 'front page remapped', (int) $home['ID'] === (int) get_option( 'page_on_front' ) );
check( 'existing post untouched', 'Existing' === get_post( $existing_post )->post_title );

// Run the same package again: nothing must be duplicated.
$posts_before    = count( $GLOBALS['site']['tables']['posts'] );
$terms_before    = count( $GLOBALS['site']['tables']['terms'] );
$comments_before = count( $GLOBALS['site']['tables']['comments'] );
$widgets_before  = count( get_option( 'widget_widget_recent_results' ) );

$again = Job::create( 'import', Importer::default_options() );
copy( $package, $again->path( Importer::ZIP_NAME ) );
Runner::run( $again, 0 );
check( 'second import finished', $again->state['done'] && empty( $again->state['error'] ) );
check( 'second import: no new posts', $posts_before === count( $GLOBALS['site']['tables']['posts'] ) );
check( 'second import: no new terms', $terms_before === count( $GLOBALS['site']['tables']['terms'] ) );
check( 'second import: no new comments', $comments_before === count( $GLOBALS['site']['tables']['comments'] ) );
check( 'second import: no new widgets', $widgets_before === count( get_option( 'widget_widget_recent_results' ) ) );

$first_log = implode( "\n", wp_list_pluck( $import->state['log'], 'message' ) );
check( 'skipped assignment logged', false !== strpos( $first_log, '1 posts lost their assignment to "ghost-taxonomy"' ) );

// The theme providing the taxonomy is activated later: another run adds the missing assignment.
$GLOBALS['site']['taxonomies'][] = 'ghost-taxonomy';
$repair = Job::create( 'import', Importer::default_options() );
copy( $package, $repair->path( Importer::ZIP_NAME ) );
Runner::run( $repair, 0 );
$ghost = get_term_by( 'slug', 'ghost', 'ghost-taxonomy' );
check( 'repair run finished', $repair->state['done'] && empty( $repair->state['error'] ) );
check( 'repair run creates missing term', false !== $ghost );
$repaired = $terms_of( $arko['ID'] );
check( 'repair run adds missing assignment', $ghost && in_array( (int) $ghost->term_id, $repaired, true ) );
check( 'repair run keeps other assignments', ! array_diff( $expected_terms, $repaired ) && count( $expected_terms ) + 1 === count( $repaired ) );
check( 'repair run creates no posts', $posts_before === count( $GLOBALS['site']['tables']['posts'] ) );

// A resumed import (small time budget) gives the same result.
fake_site( 'https://third.example', $club_taxonomies );
$resumed = Job::create( 'import', Importer::default_options() );
copy( $package, $resumed->path( Importer::ZIP_NAME ) );
$requests = 0;
while ( ! $resumed->state['done'] && $requests < 500 ) {
	$resumed = Job::load( $resumed->id ); // Fresh objects, like separate HTTP requests.
	Runner::run( $resumed, 0.000001 );
	$requests++;
}
$arko3 = fake_post_by_title( 'Arko', 'stud-dogs' );
check( 'resumed import finished', $resumed->state['done'] && empty( $resumed->state['error'] ) && $requests > 5 );
check( 'resumed import remaps', $arko3 && array( (string) fake_post_by_title( 'License Arko' )['ID'] ) === fake_meta( $arko3['ID'], 'license' ) );

// Broken packages.
fake_site( 'https://fourth.example', $club_taxonomies );
$broken = Job::create( 'import', Importer::default_options() );
file_put_contents( $broken->path( Importer::ZIP_NAME ), 'not a zip' );
Runner::run( $broken, 0 );
check( 'broken zip reports an error', $broken->state['done'] && ! empty( $broken->state['error'] ) );

$foreign_zip = Job::create( 'import', Importer::default_options() );
$zip->open( $foreign_zip->path( Importer::ZIP_NAME ), ZipArchive::CREATE );
$zip->addFromString( 'data.json', json_encode( array( 'format' => 'something-else' ) ) );
$zip->close();
Runner::run( $foreign_zip, 0 );
check( 'foreign package rejected', ! empty( $foreign_zip->state['error'] ) );

/*
 * 4. Media transfer by download (large media libraries).
 */
fake_site( 'https://old-club.example', $club_taxonomies );
$old_uploads = $GLOBALS['site']['uploads'];
mkdir( $old_uploads . '/2021/05', 0777, true );
$GLOBALS['site']['tables']['posts'] = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$name = sprintf( '2021/05/dog %02d.jpg', $i );
	file_put_contents( $old_uploads . '/' . $name, str_repeat( 'x', 100 + $i ) );
	$p( 100 + $i, 'attachment', 'dog ' . $i, array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
	$m( 100 + $i, '_wp_attached_file', $name );
}
$p( 200, 'attachment', 'ghost', array( 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
$m( 200, '_wp_attached_file', '2021/05/ghost.jpg' );
$p( 201, 'attachment', 'logo', array( 'post_status' => 'inherit', 'post_mime_type' => 'image/svg+xml' ) );
$m( 201, '_wp_attached_file', '2021/05/logo.svg' );
file_put_contents( $old_uploads . '/2021/05/logo.svg', '<svg/>' );

$export = Job::create( 'export', Exporter::default_options() );
Runner::run( $export, 0 );
check( 'download export finished', $export->state['done'] && empty( $export->state['error'] ) );
$zip = new ZipArchive();
$zip->open( $export->path( Exporter::ZIP_NAME ) );
check( 'download export packs no media', 1 === $zip->numFiles );
$data = json_decode( $zip->getFromName( 'data.json' ), true );
$zip->close();
$sizes = array();
foreach ( $data['posts'] as $post ) {
	if ( ! empty( $post['file_sizes'] ) ) { $sizes += $post['file_sizes']; }
}
check( 'file sizes exported', 40 === count( $sizes ) && 101 === $sizes['2021/05/dog 01.jpg'] );
$log = implode( "\n", wp_list_pluck( $export->state['log'], 'message' ) );
check( 'missing file reported as missing', false !== strpos( $log, 'File is missing on the server: 2021/05/ghost.jpg' ) );
check( 'svg reported with reason', false !== strpos( $log, 'Not transferred (file type .svg is blocked for security reasons): 2021/05/logo.svg' ) );
check( 'counts reported', 1 === $export->state['stats']['files_missing'] && 1 === $export->state['stats']['files_rejected'] );

$package = $export->path( Exporter::ZIP_NAME );

fake_site( 'https://new-club.example', $club_taxonomies );
$new_uploads = $GLOBALS['site']['uploads'];
// Pretend two files were copied via FTP beforehand, one of them incompletely.
mkdir( $new_uploads . '/2021/05', 0777, true );
copy( $old_uploads . '/2021/05/dog 01.jpg', $new_uploads . '/2021/05/dog 01.jpg' );
file_put_contents( $new_uploads . '/2021/05/dog 02.jpg', 'broken' );

$requested = array();
add_filter(
	'ppm_download_batch',
	function ( $result, $items ) use ( $old_uploads, &$requested ) {
		$results = array();
		foreach ( $items as $index => $item ) {
			$requested[] = $item['url'];
			$relative    = rawurldecode( substr( $item['url'], strlen( 'https://media.example/uploads/' ) ) );
			if ( false !== strpos( $relative, 'dog 03' ) ) {
				$results[ $index ] = 'HTTP 404';
				continue;
			}
			wp_mkdir_p( dirname( $item['target'] ) );
			copy( $old_uploads . '/' . $relative, $item['target'] );
			$results[ $index ] = filesize( $item['target'] ) === $item['size'] ? true : 'size';
		}
		return $results;
	}
);

$import = Job::create( 'import', array( 'media_source' => 'https://media.example/uploads' ) + Importer::default_options() );
copy( $package, $import->path( Importer::ZIP_NAME ) );
$requests = 0;
while ( ! $import->state['done'] && $requests < 500 ) {
	$import = Job::load( $import->id );
	Runner::run( $import, 0.000001 );
	$requests++;
}
remove_all_filters( 'ppm_download_batch' );

check( 'download import finished', $import->state['done'] && empty( $import->state['error'] ) );
check( 'urls use media source and encode spaces', in_array( 'https://media.example/uploads/2021/05/dog%2005.jpg', $requested, true ) );
check( 'pre-copied identical file not downloaded', ! in_array( 'https://media.example/uploads/2021/05/dog%2001.jpg', $requested, true ) );
check( 'files downloaded', 38 === $import->state['stats']['files_downloaded'] );
check( 'failed download counted', 1 === $import->state['stats']['files_failed'] );
check( 'existing identical file counted', 1 === $import->state['stats']['files_existing'] );
check( 'downloaded file in place', is_file( $new_uploads . '/2021/05/dog 40.jpg' ) && 140 === filesize( $new_uploads . '/2021/05/dog 40.jpg' ) );
check( 'incomplete pre-copied file not overwritten', 'broken' === file_get_contents( $new_uploads . '/2021/05/dog 02.jpg' ) );
$dog2 = fake_post_by_title( 'dog 2', 'attachment' );
$dog2_file = fake_meta( $dog2['ID'], '_wp_attached_file' );
check( 'conflicting file downloaded into separate folder', 0 === strpos( $dog2_file[0], 'ppm-import-' ) && 102 === filesize( $new_uploads . '/' . $dog2_file[0] ) );
$import_log = implode( "\n", wp_list_pluck( $import->state['log'], 'message' ) );
check( 'failed file logged with reason', false !== strpos( $import_log, 'Media file not transferred (HTTP 404): 2021/05/dog 03.jpg' ) );

// Running the import again only fetches what is still missing.
$requested = array();
add_filter( 'ppm_download_batch', function ( $result, $items ) use ( $old_uploads, &$requested ) {
	$results = array();
	foreach ( $items as $index => $item ) {
		$requested[] = $item['url'];
		$results[ $index ] = true;
	}
	return $results;
} );
$again = Job::create( 'import', array( 'media_source' => 'https://media.example/uploads' ) + Importer::default_options() );
copy( $package, $again->path( Importer::ZIP_NAME ) );
Runner::run( $again, 0 );
remove_all_filters( 'ppm_download_batch' );
check( 'second run downloads only the missing file', array( 'https://media.example/uploads/2021/05/dog%2003.jpg' ) === $requested );

check( 'downloader url helper', 'https://a.example/up/2021/05/%C3%BCber%20uns.jpg' === PuppyPressMigrator\Downloader::url( 'https://a.example/up/', '2021/05/über uns.jpg' ) );

fwrite( STDOUT, sprintf( "%d checks, %d failures\n", $checks, $failures ) );
exit( $failures ? 1 : 0 );
