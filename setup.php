<?php
/**
 * Builds the Uncle Nene's Kitchen store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Menu photos are read from /wordpress/un-images.
 *
 * Menu and prices are Uncle Nene's own: their 2026 menu board, the August 2026 "new menu" and drinks
 * boards (Instagram @unclenenes, 13 Oct 2026). Their Uber Eats store charges about KSh 150 more per
 * item; this site uses the direct menu prices. Anything marked PLACEHOLDER is ours, to confirm.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function un_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'un_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

if ( defined( 'UN_FAST' ) && UN_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings. Delivery goes to one address (the billing one); pickup is WooCommerce's local pickup.
foreach ( [
	'blogname'                               => 'Uncle Nene\'s Kitchen',
	'blogdescription'                        => 'Nairobi\'s best burgers · grass-fed beef, brioche buns & loaded fries · Ngummo & Garden Estate',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Amani Food Court, Berea Road',
	'woocommerce_store_city'                 => 'Garden Estate, Nairobi',
	'woocommerce_manage_stock'               => 'no',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'specific',
	'woocommerce_specific_ship_to_countries' => [ 'KE' ],
	'woocommerce_ship_to_destination'        => 'billing_only',
	'woocommerce_shipping_cost_requires_address' => 'no',
	'woocommerce_enable_shipping_calc'       => 'no',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_checkout_address_2_field'   => 'optional',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_calc_discounts_sequentially' => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Menu sections (order = order on the menu and in the filters).
$cat = [];
$i   = 0;
foreach ( [
	'beef-burgers'    => [ 'Beef Burgers', 'Grass-fed Laikipia beef on our homemade brioche butter buns. Every burger comes with our signature seasoned fries.' ],
	'chicken-burgers' => [ 'Chicken Burgers', 'Tender, juicy chicken patties in our secret marinade, on brioche, with signature seasoned fries.' ],
	'loaded-fries'    => [ 'Loaded Fries', 'A full box of signature seasoned fries, loaded with beef or chicken, cheese and bacon. Enough for one big appetite, or two.' ],
	'boxes'           => [ 'Brunch Box, Strips & Fish', 'Grilled beef and chicken strips, Nairobi\'s best onion rings and golden fish fingers, all with fries and slaw.' ],
	'family'          => [ 'Family & Office Pack', 'Five burgers, five portions of fries: feeding the family, the squad or the whole office.' ],
	'sides'           => [ 'Sides & Dips', 'Large fries, creamy coleslaw, our burger sauce and homemade tartare sauce.' ],
	'drinks'          => [ 'Sodas, Juice & Water', 'Cold drinks for delivery and pickup.' ],
	'dine-in-drinks'  => [ 'Shakes, Lemonades & Hot Drinks', 'Made fresh at Amani Food Court, Garden Estate: for dine-in and Garden Estate pickup only.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// What's in it (the menu filters).
$tags = [];
foreach ( [
	'beef'       => 'Beef',
	'chicken'    => 'Chicken',
	'fish'       => 'Fish',
	'bacon'      => 'Bacon',
	'cheese'     => 'Cheese',
	'vegetarian' => 'Vegetarian',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$tags[ $slug ] = (int) $t['term_id'];
}

// Payment: nothing is charged online. Their M-Pesa till / paybill isn't public (PLACEHOLDER).
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay on delivery or at pickup (M-Pesa or cash)',
	'description'        => 'Pay the rider, or pay when you collect, by M-Pesa or cash.',
	'instructions'       => 'We will call or WhatsApp to confirm your order. Pay by M-Pesa or cash when it reaches you.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_bacs_settings', [
	'enabled'         => 'yes',
	'title'           => 'Pay now by M-Pesa',
	'description'     => 'We send our M-Pesa till number with your order confirmation. Pay before the rider leaves and skip the wait at the door.',
	'instructions'    => 'We will send our M-Pesa till number on WhatsApp with your confirmation.',
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );
update_option( 'woocommerce_gateway_order', [ 'cod' => 0, 'bacs' => 1 ] );

// Delivery: their own riders from whichever kitchen is nearer, by distance (fees are PLACEHOLDERS;
// Uber Eats charges KSh 220 from Ngummo). Pickup: either kitchen.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->set_zone_order( 1 );
$zone->add_location( 'KE', 'country' );
$zone->save();
foreach ( [
	[ 'Rider delivery – within 5 km of Ngummo or Garden Estate', 200 ],
	[ 'Rider delivery – 5 to 10 km', 350 ],
	[ 'Rider delivery – further across Nairobi', 500 ],
] as [ $title, $cost ] ) {
	$iid = $zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $iid . '_settings', [ 'title' => $title, 'tax_status' => 'none', 'cost' => (string) $cost ] );
}
update_option( 'woocommerce_pickup_location_settings', [ 'enabled' => 'yes', 'title' => 'Pickup', 'tax_status' => 'none', 'cost' => '' ] );
update_option( 'pickup_location_pickup_locations', [
	[
		'name'    => 'Ngummo kitchen, Mbagathi Road',
		'address' => [ 'address_1' => 'Mbagathi Road, Ngummo (MRQ2+C6)', 'city' => 'Nairobi', 'state' => '', 'postcode' => '', 'country' => 'KE' ],
		'details' => 'Pickup window, no seating. Call 0736 713 761 when you\'re outside.',
		'enabled' => true,
	],
	[
		'name'    => 'Garden Estate, Amani Food Court',
		'address' => [ 'address_1' => 'Amani Food Court, Berea Road', 'city' => 'Garden Estate, Nairobi', 'state' => '', 'postcode' => '', 'country' => 'KE' ],
		'details' => 'Our dine-in branch: collect at the counter, or eat in. Shakes, lemonades and hot drinks are made here.',
		'enabled' => true,
	],
] );

// Menu items. 'opts': double = make it a double (+250, an extra patty), extras = burger add-ons, sides = add a side,
// dip = free choice of dip. 'choices' become variations (the price changes). likes = Uber Eats thumbs-up.
// 'dinein': made at Garden Estate for dine-in / Garden Estate pickup only (their rule for drinks).
$fries_note = 'Served with our signature seasoned fries.';
$bun        = [ 'Wheat (gluten)', 'Egg', 'Milk', 'Sesame' ];
$products   = [
	// Beef burgers
	[
		'slug' => 'classic-beef-burger', 'name' => 'Classic Beef Burger', 'line' => 'Grass-fed beef · brioche · fries', 'cats' => [ 'beef-burgers' ],
		'price' => 1100, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'beef' ], 'clip' => 'grill',
		'short' => 'A juicy grass-fed Laikipia beef patty, seared on the grill, with caramelised onions, pickles and our burger sauce on a soft brioche bun. With signature seasoned fries.',
		'desc' => 'Where it all starts. Open-farm, grass-fed Laikipia beef, seasoned and seared to lock in the juices, stacked with caramelised onions, pickles and our burger sauce in a homemade brioche butter bun, toasted on the grill. ' . 'Served with our signature seasoned fries. Make it a double for KSh 250 more.',
		'portion' => 'Burger + fries', 'ingredients' => [ 'Grass-fed Laikipia beef patty', 'Homemade brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Signature seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'beef-cheese-burger', 'name' => 'Beef Cheese Burger', 'line' => 'Grass-fed beef · melted cheese', 'cats' => [ 'beef-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'beef', 'cheese' ], 'likes' => '#3 most liked on Uber Eats', 'clip' => 'grill',
		'short' => 'Our grass-fed beef patty under a blanket of melted cheese, with caramelised onions, pickles and burger sauce on brioche. With signature seasoned fries.',
		'desc' => 'Tender grass-fed beef with melted cheese for that perfect taste: seared, smothered and stacked with caramelised onions, pickles and our burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Grass-fed beef patty', 'Melted cheese', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'beef-bacon-burger', 'name' => 'Beef Bacon Burger', 'line' => 'Grass-fed beef · crispy bacon', 'cats' => [ 'beef-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'beef', 'bacon' ], 'clip' => 'grill',
		'short' => 'Perfectly seasoned grass-fed beef with crispy bacon, caramelised onions and burger sauce on brioche. With signature seasoned fries.',
		'desc' => 'Perfectly seasoned beef, crispy bacon and a whole lot of goodness: our grass-fed patty seared on the grill and topped with crispy bacon, caramelised onions, pickles and our burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Grass-fed beef patty', 'Crispy bacon', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'beef-bacon-cheese-burger', 'name' => 'Beef Bacon Cheese Burger', 'line' => 'Beef · bacon · melted cheese', 'cats' => [ 'beef-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'beef', 'bacon', 'cheese' ], 'likes' => '#1 most liked on Uber Eats · 100%', 'badge' => '#1 most liked', 'clip' => 'grill',
		'short' => 'Juicy beef, crispy bacon and melty cheese, all in one bite, on a toasted brioche bun. With signature seasoned fries.',
		'desc' => 'Juicy beef, crispy bacon and melty cheese, all in one bite. Bacon changes everything: our grass-fed patty, seared and stacked with crispy bacon, melted cheese, caramelised onions, pickles and burger sauce in a toasted brioche butter bun. Our customers\' favourite on Uber Eats. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Grass-fed beef patty', 'Crispy bacon', 'Melted cheese', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'beast-burger', 'name' => 'Beast Burger', 'line' => 'Six layers · not for the faint-hearted', 'cats' => [ 'beef-burgers' ],
		'price' => 1400, 'opts' => [ 'extras', 'sides' ], 'tags' => [ 'beef', 'bacon', 'cheese' ], 'likes' => '#2 most liked on Uber Eats', 'badge' => 'The legend', 'clip' => 'grill',
		'reel' => 'https://www.instagram.com/p/DdI02Mvuj9I/',
		'short' => 'Six layers, fully loaded: seared beef, crispy bacon, a fried egg, melted cheese and pickles on buttery brioche. Can you finish it in one go?',
		'desc' => 'Good things come in layers, and this one has six. Perfectly seared grass-fed beef, crispy bacon, a fried egg, melted cheese, pickles and caramelised onions, stacked high in a buttery brioche bun, with crispy fries on the side. A fan favourite for a reason. Come hungry.',
		'portion' => 'Burger + fries', 'ingredients' => [ 'Grass-fed beef', 'Crispy bacon', 'Fried egg', 'Melted cheese', 'Pickles', 'Caramelised onions', 'Brioche bun', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Egg', 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	// Chicken burgers
	[
		'slug' => 'classic-chicken-burger', 'name' => 'Classic Chicken Burger', 'line' => 'Juicy chicken · secret marinade', 'cats' => [ 'chicken-burgers' ],
		'price' => 1100, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'chicken' ],
		'short' => 'A tender, juicy chicken patty in our secret marinade, with caramelised onions, pickles and burger sauce on brioche. With signature seasoned fries.',
		'desc' => 'Tender, juicy chicken patties for the most flavourful burger: marinated in our secret marinade, grilled, and stacked with caramelised onions, pickles and our burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Chicken patty', 'Secret marinade', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'chicken-cheese-burger', 'name' => 'Chicken Cheese Burger', 'line' => 'Juicy chicken · melty cheese', 'cats' => [ 'chicken-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'chicken', 'cheese' ],
		'short' => 'Our marinated chicken patty, loaded with flavour and topped with melty cheese, on a toasted brioche bun. With signature seasoned fries.',
		'desc' => 'Loaded with flavour, topped with melty cheese and made to satisfy every single time. Our juicy marinated chicken patty with cheese, caramelised onions, pickles and burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Chicken patty', 'Melted cheese', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'chicken-bacon-burger', 'name' => 'Chicken Bacon Burger', 'line' => 'Juicy chicken · crispy bacon', 'cats' => [ 'chicken-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'chicken', 'bacon' ],
		'short' => 'Juicy marinated chicken with crispy bacon, caramelised onions and burger sauce on brioche. With signature seasoned fries.',
		'desc' => 'Our juicy, marinated chicken patty topped with crispy bacon, caramelised onions, pickles and our burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Chicken patty', 'Crispy bacon', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Burger sauce (egg)' ] ),
	],
	[
		'slug' => 'chicken-bacon-cheese-burger', 'name' => 'Chicken Bacon Cheese Burger', 'line' => 'Chicken · bacon · melted cheese', 'cats' => [ 'chicken-burgers' ],
		'price' => 1200, 'opts' => [ 'double', 'extras', 'sides' ], 'tags' => [ 'chicken', 'bacon', 'cheese' ], 'badge' => 'Fan favourite',
		'short' => 'Juicy chicken, crispy bacon and melted cheese, stacked with all the good stuff on a toasted brioche bun. With signature seasoned fries.',
		'desc' => 'Stacked with juicy chicken, crispy bacon, melted cheese and all the good stuff. Warning: may cause extreme satisfaction. Our marinated chicken patty with bacon, cheese, caramelised onions, pickles and burger sauce in a toasted brioche butter bun. ' . $fries_note,
		'portion' => 'Burger + fries', 'ingredients' => [ 'Chicken patty', 'Crispy bacon', 'Melted cheese', 'Brioche bun', 'Caramelised onions', 'Pickles', 'Burger sauce', 'Seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	// Loaded fries (style = variation, same price)
	[
		'slug' => 'beef-loaded-fries', 'name' => 'Beef Loaded Fries', 'line' => 'Seasoned fries · minced beef · toppings', 'cats' => [ 'loaded-fries' ],
		'choices' => [ 'Classic Beef' => 1100, 'Beef Cheese' => 1100, 'Beef Bacon' => 1100, 'Beef Bacon Cheese' => 1100 ], 'choice_name' => 'Style',
		'opts' => [ 'extras', 'sides' ], 'tags' => [ 'beef', 'bacon', 'cheese' ], 'badge' => 'Better than your ex', 'clip' => 'fries',
		'short' => 'A full box of signature seasoned fries loaded with seasoned minced beef, onions and peppers. Go classic, or add cheese, bacon, or both.',
		'desc' => 'Normal fries? No. Our signature seasoned fries, loaded with seasoned minced beef, red onion and green pepper, with melted cheese, crispy bacon or both. Crispy fries, generous toppings and a whole lot of flavour in every bite: one Google reviewer says it\'s "enough for 2 people".',
		'portion' => 'One loaded box', 'ingredients' => [ 'Signature seasoned fries', 'Seasoned minced beef', 'Red onion', 'Green pepper', 'Cheese (Cheese styles)', 'Crispy bacon (Bacon styles)' ], 'allergens' => [ 'Cheese (milk) in Cheese styles', 'Sauce (egg)' ],
	],
	[
		'slug' => 'chicken-loaded-fries', 'name' => 'Chicken Loaded Fries', 'line' => 'Seasoned fries · chicken · toppings', 'cats' => [ 'loaded-fries' ],
		'choices' => [ 'Classic Chicken' => 1100, 'Chicken Cheese' => 1100, 'Chicken Bacon' => 1100, 'Chicken Bacon Cheese' => 1100 ], 'choice_name' => 'Style',
		'opts' => [ 'extras', 'sides' ], 'tags' => [ 'chicken', 'bacon', 'cheese' ], 'clip' => 'fries',
		'short' => 'Signature seasoned fries loaded with chicken and toppings. Go classic, or add melted cheese, crispy bacon, or both.',
		'desc' => 'Why settle for regular fries when you can load them? Our signature seasoned fries topped with chicken, onions and peppers, plus melted cheese, crispy bacon or both. Big enough to share, if you\'re feeling generous.',
		'portion' => 'One loaded box', 'ingredients' => [ 'Signature seasoned fries', 'Chicken', 'Red onion', 'Green pepper', 'Cheese (Cheese styles)', 'Crispy bacon (Bacon styles)' ], 'allergens' => [ 'Cheese (milk) in Cheese styles', 'Sauce (egg)' ],
	],
	// Brunch box, strips, rings & fish
	[
		'slug' => 'brunch-box', 'name' => 'Brunch Box', 'line' => 'Beef & chicken strips · onion rings · fries', 'cats' => [ 'boxes' ],
		'price' => 1800, 'opts' => [ 'dip', 'sides' ], 'tags' => [ 'beef', 'chicken' ], 'badge' => 'Why choose one?',
		'reel' => 'https://www.instagram.com/p/Db2bHAFO2Ug/',
		'short' => 'Juicy beef strips, tender chicken strips, Nairobi\'s best onion rings, seasoned fries and creamy slaw, with a dip of your choice.',
		'desc' => 'Why choose one when you can have it all? Juicy grilled beef strips, tender chicken strips, golden onion rings and our signature seasoned fries, served with fresh creamy slaw and a dip of your choice. Big on flavour, generous on portions.',
		'portion' => 'One box, made to share', 'ingredients' => [ 'Grilled beef strips', 'Grilled chicken strips', 'Onion rings', 'Seasoned fries', 'Creamy coleslaw', 'Dip of your choice' ], 'allergens' => [ 'Wheat (gluten) in the onion-ring batter', 'Coleslaw & dips (egg)' ],
	],
	[
		'slug' => 'onion-rings', 'name' => 'Onion Rings', 'line' => 'Golden & crispy · fries · slaw', 'cats' => [ 'boxes' ],
		'price' => 1200, 'opts' => [ 'dip', 'sides' ], 'tags' => [ 'vegetarian' ],
		'short' => 'Nairobi\'s best onion rings: golden, crispy and seasoned to perfection, with seasoned fries, creamy slaw and a dip of your choice.',
		'desc' => 'Golden, crispy and impossible to resist. Our onion rings are seasoned to perfection and served with seasoned fries, creamy slaw and a dip of your choice. Every bite is worth it.',
		'portion' => 'One box', 'ingredients' => [ 'Battered onion rings', 'Seasoned fries', 'Creamy coleslaw', 'Dip of your choice' ], 'allergens' => [ 'Wheat (gluten)', 'Coleslaw & dips (egg)' ],
	],
	[
		'slug' => 'grilled-beef-strips', 'name' => 'Grilled Beef Strips', 'line' => 'Seared beef · fries · slaw', 'cats' => [ 'boxes' ],
		'price' => 1100, 'opts' => [ 'sides' ], 'tags' => [ 'beef' ],
		'short' => 'Juicy beef strips, seared on the grill for maximum flavour, with crispy seasoned fries and fresh slaw.',
		'desc' => 'Our perfectly grilled beef strips, seared for maximum flavour and served with crispy seasoned fries and fresh creamy slaw.',
		'portion' => 'One box', 'ingredients' => [ 'Grilled beef strips', 'Seasoned fries', 'Creamy coleslaw' ], 'allergens' => [ 'Coleslaw (egg)' ],
	],
	[
		'slug' => 'grilled-chicken-strips', 'name' => 'Grilled Chicken Strips', 'line' => 'Tender chicken · fries · slaw', 'cats' => [ 'boxes' ],
		'price' => 1100, 'opts' => [ 'sides' ], 'tags' => [ 'chicken' ],
		'short' => 'Tender chicken strips, grilled and seared for maximum flavour, with crispy seasoned fries and fresh slaw.',
		'desc' => 'Tender chicken strips, grilled and seared for maximum flavour, served with crispy seasoned fries and fresh creamy slaw.',
		'portion' => 'One box', 'ingredients' => [ 'Grilled chicken strips', 'Seasoned fries', 'Creamy coleslaw' ], 'allergens' => [ 'Coleslaw (egg)' ],
	],
	[
		'slug' => 'fish-fingers', 'name' => 'Fish Fingers & Fries', 'line' => 'Golden fried · homemade tartare', 'cats' => [ 'boxes' ],
		'price' => 1300, 'opts' => [ 'sides' ], 'tags' => [ 'fish' ], 'badge' => 'Don\'t sleep on these',
		'reel' => 'https://www.instagram.com/p/Dd_IImqOn9b/',
		'short' => 'Golden fried fish fingers, crispy outside and soft inside, with our homemade tartare sauce and seasoned fries.',
		'desc' => 'When the fish fingers are golden brown, you know it\'s going to be good. Perfectly seasoned, coated with care and fried crispy on the outside, soft inside, served with our homemade tartare sauce for dipping (a little or a lot) and our signature seasoned fries.',
		'portion' => 'Fish fingers + fries + tartare', 'ingredients' => [ 'Fish fingers', 'Seasoned crumb coating', 'Homemade tartare sauce', 'Seasoned fries' ], 'allergens' => [ 'Fish', 'Wheat (gluten)', 'Tartare sauce (egg)' ],
	],
	// Family pack
	[
		'slug' => 'patty-party-pack', 'name' => 'Patty Party Pack', 'line' => '5 burgers + 5 fries · free delivery', 'cats' => [ 'family' ],
		'price' => 5000, 'tags' => [ 'beef', 'chicken', 'bacon', 'cheese' ], 'badge' => 'Free delivery',
		'short' => 'Why choose one when you can have five? Classic Beef, Classic Chicken, Beef Cheese, Chicken Cheese and Beef Bacon Cheese burgers, each with fries. Delivered free.',
		'desc' => 'Five different burgers for the table: Classic Beef, Classic Chicken, Beef Cheese, Chicken Cheese and Beef Bacon Cheese, each with our signature seasoned fries, and free delivery to top it off. Perfect for family night, the squad or the office. Dear Boss: your team called, they want burgers.',
		'portion' => '5 burgers + 5 fries', 'ingredients' => [ 'Classic Beef Burger', 'Classic Chicken Burger', 'Beef Cheese Burger', 'Chicken Cheese Burger', 'Beef Bacon Cheese Burger', '5 × signature seasoned fries' ], 'allergens' => array_merge( $bun, [ 'Cheese (milk)', 'Burger sauce (egg)' ] ),
	],
	// Sides & dips
	[
		'slug' => 'large-fries', 'name' => 'Large Fries', 'line' => 'Signature seasoned fries', 'cats' => [ 'sides' ],
		'price' => 400, 'tags' => [ 'vegetarian' ],
		'short' => 'A large box of our signature seasoned fries, cut from fresh potatoes.', 'desc' => 'A large portion of our signature seasoned fries, cut from fresh potatoes and fried golden.',
		'portion' => 'Large', 'ingredients' => [ 'Fresh potatoes', 'House seasoning' ], 'allergens' => [],
	],
	[
		'slug' => 'coleslaw', 'name' => 'Coleslaw', 'line' => 'Fresh & creamy', 'cats' => [ 'sides' ],
		'price' => 200, 'tags' => [ 'vegetarian' ],
		'short' => 'Fresh, creamy slaw. No fries? No problem.', 'desc' => 'Fresh cabbage and carrot in a creamy dressing. Enjoy it with your burger instead of fries, or as well as.',
		'portion' => 'One tub', 'ingredients' => [ 'Cabbage', 'Carrot', 'Creamy dressing' ], 'allergens' => [ 'Dressing (egg)' ],
	],
	[
		'slug' => 'burger-sauce', 'name' => 'Burger Sauce (dip)', 'line' => 'Our signature sauce', 'cats' => [ 'sides' ],
		'price' => 150, 'tags' => [ 'vegetarian' ],
		'short' => 'An extra tub of our signature burger sauce for dipping.', 'desc' => 'An extra tub of the burger sauce that goes in every Uncle Nene\'s burger. For fries, strips and everything else.',
		'portion' => 'One tub', 'ingredients' => [ 'Burger sauce' ], 'allergens' => [ 'Egg' ],
	],
	[
		'slug' => 'tartar-sauce', 'name' => 'Tartare Sauce (dip)', 'line' => 'Homemade', 'cats' => [ 'sides' ],
		'price' => 150, 'tags' => [ 'vegetarian' ],
		'short' => 'Our homemade tartare sauce: made for fish fingers, great with fries.', 'desc' => 'An extra tub of our homemade tartare sauce. Dip a little or dip a lot.',
		'portion' => 'One tub', 'ingredients' => [ 'Homemade tartare sauce' ], 'allergens' => [ 'Egg' ],
	],
	// Drinks for delivery & pickup
	[
		'slug' => 'soft-drinks', 'name' => 'Sodas, Juice & Water', 'line' => 'Ice cold', 'cats' => [ 'drinks' ],
		'choices' => [ 'Soda 350 ml' => 100, 'Soda 500 ml' => 120, 'Minute Maid 400 ml' => 150, 'Water 500 ml' => 120 ], 'choice_name' => 'Drink',
		'tags' => [ 'vegetarian' ], 'drawn' => true,
		'short' => 'A cold soda, Minute Maid juice or bottled water with your order.', 'desc' => 'Cold drinks to go with your burger: soda (350 ml or 500 ml), Minute Maid (400 ml) or still water (500 ml). Tell us your soda flavour in the note.',
		'portion' => 'One bottle', 'ingredients' => [ 'Soda, juice or water' ], 'allergens' => [],
	],
	// Dine-in drinks (Garden Estate)
	[
		'slug' => 'milkshake', 'name' => 'Milkshakes', 'line' => 'Thick & creamy · Garden Estate', 'cats' => [ 'dine-in-drinks' ],
		'choices' => [ 'Chocolate Chip Cookie' => 600, 'Chocolate' => 600, 'Strawberry' => 600, 'Coffee' => 600, 'Vanilla' => 600 ], 'choice_name' => 'Flavour',
		'tags' => [ 'vegetarian' ], 'dinein' => true, 'badge' => 'Dine-in · Garden Estate', 'clip' => 'shake',
		'short' => 'Thick, creamy milkshakes: Chocolate Chip Cookie (our favourite), Chocolate, Strawberry, Coffee or Vanilla.',
		'desc' => 'Thick, creamy and guaranteed to hit the spot. Chocolate Chip Cookie is the one everyone posts about; Chocolate, Strawberry, Coffee and Vanilla are just as hard to resist. Made fresh at our Amani Food Court branch in Garden Estate, for dine-in and Garden Estate pickup.',
		'portion' => 'One glass', 'ingredients' => [ 'Milk', 'Ice cream', 'Your flavour' ], 'allergens' => [ 'Milk', 'Wheat & egg (cookie)' ],
	],
	[
		'slug' => 'lemonade', 'name' => 'Lemonade', 'line' => 'Baridi baridi · Garden Estate', 'cats' => [ 'dine-in-drinks' ],
		'choices' => [ 'Classic Lemonade' => 400, 'Strawberry Lemonade' => 450 ], 'choice_name' => 'Flavour',
		'tags' => [ 'vegetarian' ], 'dinein' => true, 'badge' => 'Dine-in · Garden Estate',
		'short' => 'Freshly made, ice-cold lemonade: Classic, or sweet and tangy Strawberry.',
		'desc' => 'Our sweet, tangy, freshly made lemonade: exactly what you need to beat the heat. Classic, or Strawberry with real strawberries. Made at our Amani Food Court branch in Garden Estate, for dine-in and Garden Estate pickup.',
		'portion' => 'One glass', 'ingredients' => [ 'Fresh lemons', 'Strawberries (Strawberry)', 'Ice' ], 'allergens' => [],
	],
	[
		'slug' => 'hot-drinks', 'name' => 'Hot Drinks', 'line' => 'Moto moto · Garden Estate', 'cats' => [ 'dine-in-drinks' ],
		'choices' => [ 'Tea' => 300, 'Coffee' => 350, 'Hot Chocolate' => 300, 'Mocha' => 350, 'Orange Ginger Dawa' => 380 ], 'choice_name' => 'Drink',
		'tags' => [ 'vegetarian' ], 'dinein' => true, 'badge' => 'Dine-in · Garden Estate',
		'short' => 'A comforting cup: tea, coffee, hot chocolate, mocha, or our orange ginger dawa.',
		'desc' => 'Moto moto: tea, coffee (white or black), hot chocolate, mocha, or an orange ginger dawa for when you need it. Served at our Amani Food Court branch in Garden Estate.',
		'portion' => 'One cup', 'ingredients' => [ 'Tea, coffee or cocoa', 'Milk (optional)', 'Orange, ginger & honey (dawa)' ], 'allergens' => [ 'Milk' ],
	],
];

function un_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/un-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = un_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

function un_local_attr( $name, array $options ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_options( $options );
	$a->set_position( 0 );
	$a->set_visible( true );
	$a->set_variation( true );
	return $a;
}

// The home page shows these first: their best sellers and what their posts push hardest.
$signature = [ 'beef-bacon-cheese-burger', 'beast-burger', 'chicken-bacon-cheese-burger', 'classic-chicken-burger', 'beef-loaded-fries', 'brunch-box', 'fish-fingers', 'patty-party-pack' ];

foreach ( $products as $order => $d ) {
	$choices = $d['choices'] ?? null;
	$p = $choices ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['short'] );
	$p->set_category_ids( array_map( fn( $c ) => $cat[ $c ], $d['cats'] ) );
	$p->set_tag_ids( array_map( fn( $t ) => $tags[ $t ], $d['tags'] ?? [] ) );
	$p->set_featured( in_array( $d['slug'], $signature, true ) );
	$meta = [
		'line'        => $d['line'] ?? '',
		'opts'        => implode( ',', $d['opts'] ?? [] ),
		'likes'       => $d['likes'] ?? '',
		'badge'       => $d['badge'] ?? '',
		'portion'     => $d['portion'] ?? '',
		'ingredients' => implode( "\n", $d['ingredients'] ?? [] ),
		'allergens'   => implode( "\n", $d['allergens'] ?? [] ),
		'clip'        => $d['clip'] ?? '',
		'reel'        => $d['reel'] ?? '',
	];
	foreach ( $meta as $k => $v ) {
		if ( $v !== '' ) $p->update_meta_data( '_un_' . $k, $v );
	}
	if ( ! empty( $d['drawn'] ) ) $p->update_meta_data( '_un_drawn', 'yes' );
	if ( ! empty( $d['dinein'] ) ) $p->update_meta_data( '_un_dinein', 'yes' );

	if ( $choices ) {
		$attr = $d['choice_name'];
		$p->set_attributes( [ un_local_attr( $attr, array_keys( $choices ) ) ] );
		$p->set_default_attributes( [ sanitize_title( $attr ) => array_key_first( $choices ) ] );
	} else {
		$p->set_regular_price( (string) $d['price'] );
	}
	$pid = $p->save();

	$imgs = un_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}

	if ( $choices ) {
		$n = 0;
		foreach ( $choices as $label => $amount ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ sanitize_title( $attr ) => $label ] );
			$v->set_regular_price( (string) $amount );
			$v->set_menu_order( $n++ );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// Pages.
function un_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
un_page( 'menu', 'Order online', "<!-- wp:paragraph -->\n<p>Every burger is seared to order on brioche we bake ourselves, with signature seasoned fries. Filter by beef, chicken or budget, make it a double, add bacon or an egg, and order for delivery or pickup at Ngummo or Garden Estate, or straight on WhatsApp.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[un_menu]\n<!-- /wp:shortcode -->" );
un_page( 'about', 'About us', '' ); // content lives in the theme: templates/page-about.html
un_page( 'faqs', 'FAQs', '' ); // templates/page-faqs.html
un_page( 'group-orders', 'Office & group orders', "<!-- wp:paragraph -->\n<p>Team lunch, a birthday, a watch party or a meeting that needs feeding? Tell us the date, how many and where, and we'll put together burgers, loaded fries and Brunch Boxes for the group, with a price and a delivery time.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[un_event_form]\n<!-- /wp:shortcode -->" );

// Cart and checkout read as an order.
foreach ( [ 'shop' => 'Full menu', 'cart' => 'Your order', 'checkout' => 'Checkout' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
