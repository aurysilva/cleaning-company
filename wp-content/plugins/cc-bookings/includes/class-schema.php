<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Custom tables (prefixed wp_cc_). Money is stored in pence, timestamps in UTC,
 * job_date + slot in site time. Bump VERSION after changing a definition; dbDelta applies it.
 */
final class Schema {
	const VERSION = '1';
	const OPTION  = 'cc_bookings_db_version';

	const TABLES = [ 'quotes', 'bookings', 'booking_items', 'cleaners', 'cleaner_availability', 'job_offers', 'payments', 'events' ];

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		foreach ( self::definitions() as $name => $columns ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n" . $columns . "\n) {$collate};" );
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	private static function definitions(): array {
		$customer = "customer_name varchar(200) NOT NULL DEFAULT '',
email varchar(200) NOT NULL DEFAULT '',
phone varchar(40) NOT NULL DEFAULT '',
address_1 varchar(200) NOT NULL DEFAULT '',
address_2 varchar(200) NOT NULL DEFAULT '',
town varchar(100) NOT NULL DEFAULT '',
postcode varchar(10) NOT NULL DEFAULT '',
district varchar(6) NOT NULL DEFAULT '',
access_notes text NULL,
marketing_opt_in tinyint(1) NOT NULL DEFAULT 0,";

		$job = "service_id bigint(20) unsigned NOT NULL DEFAULT 0,
service_slug varchar(100) NOT NULL DEFAULT '',
service_name varchar(200) NOT NULL DEFAULT '',
answers longtext NULL,
job_date date NULL,
slot varchar(20) NOT NULL DEFAULT '',
hours decimal(5,2) NOT NULL DEFAULT 0,
cleaners tinyint(3) unsigned NOT NULL DEFAULT 1,";

		return [
			'quotes' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ref varchar(20) NOT NULL,
token_hash char(64) NOT NULL DEFAULT '',
token_hashes text NULL,
status varchar(20) NOT NULL DEFAULT 'draft',
is_bespoke tinyint(1) NOT NULL DEFAULT 0,
bespoke_reasons text NULL,
{$job}
items longtext NULL,
total_pence int(11) NULL,
{$customer}
covered tinyint(1) NOT NULL DEFAULT 1,
photos text NULL,
consent text NULL,
admin_note text NULL,
expires_at datetime NULL,
sent_at datetime NULL,
viewed_at datetime NULL,
accepted_at datetime NULL,
hold_expires_at datetime NULL,
stripe_session_id varchar(191) NOT NULL DEFAULT '',
booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
replaces_id bigint(20) unsigned NOT NULL DEFAULT 0,
followups_off tinyint(1) NOT NULL DEFAULT 0,
is_test tinyint(1) NOT NULL DEFAULT 0,
ip_hash char(64) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY ref (ref),
KEY status (status),
KEY job_slot (job_date,slot),
KEY email (email),
KEY hold_expires_at (hold_expires_at)",

			'bookings' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ref varchar(20) NOT NULL,
quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
token_hash char(64) NOT NULL DEFAULT '',
token_hashes text NULL,
status varchar(20) NOT NULL DEFAULT 'confirmed',
payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
{$job}
start_at datetime NULL,
end_at datetime NULL,
{$customer}
total_pence int(11) NOT NULL DEFAULT 0,
paid_pence int(11) NOT NULL DEFAULT 0,
refunded_pence int(11) NOT NULL DEFAULT 0,
currency char(3) NOT NULL DEFAULT 'gbp',
stripe_session_id varchar(191) NOT NULL DEFAULT '',
stripe_payment_intent varchar(191) NOT NULL DEFAULT '',
parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
series_id bigint(20) unsigned NOT NULL DEFAULT 0,
meta longtext NULL,
admin_note text NULL,
is_test tinyint(1) NOT NULL DEFAULT 0,
confirmed_at datetime NULL,
completed_at datetime NULL,
cancelled_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY ref (ref),
KEY quote_id (quote_id),
KEY status (status),
KEY job_slot (job_date,slot),
KEY start_at (start_at),
KEY stripe_session_id (stripe_session_id),
KEY stripe_payment_intent (stripe_payment_intent),
KEY series_id (series_id)",

			'booking_items' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
type varchar(20) NOT NULL DEFAULT 'base',
label varchar(255) NOT NULL DEFAULT '',
quantity decimal(8,2) NOT NULL DEFAULT 1,
unit_pence int(11) NOT NULL DEFAULT 0,
amount_pence int(11) NOT NULL DEFAULT 0,
sort smallint(5) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id)",

			'cleaners' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
name varchar(200) NOT NULL DEFAULT '',
email varchar(200) NOT NULL DEFAULT '',
phone varchar(40) NOT NULL DEFAULT '',
type varchar(20) NOT NULL DEFAULT 'employed',
status varchar(20) NOT NULL DEFAULT 'active',
districts text NULL,
services text NULL,
can_lead tinyint(1) NOT NULL DEFAULT 0,
max_jobs_per_day tinyint(3) unsigned NOT NULL DEFAULT 3,
pay_type varchar(20) NOT NULL DEFAULT 'hourly',
pay_rate_pence int(11) NOT NULL DEFAULT 0,
has_car tinyint(1) NOT NULL DEFAULT 0,
token_hash char(64) NOT NULL DEFAULT '',
notes text NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY status (status),
KEY type (type),
KEY user_id (user_id)",

			'cleaner_availability' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
cleaner_id bigint(20) unsigned NOT NULL,
kind varchar(20) NOT NULL DEFAULT 'weekly',
weekday tinyint(1) unsigned NULL,
date_from date NULL,
date_to date NULL,
start_time time NULL,
end_time time NULL,
note varchar(255) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY cleaner_id (cleaner_id),
KEY kind_dates (kind,date_from,date_to)",

			'job_offers' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
cleaner_id bigint(20) unsigned NOT NULL,
status varchar(20) NOT NULL DEFAULT 'offered',
source varchar(20) NOT NULL DEFAULT 'manual',
role varchar(20) NOT NULL DEFAULT 'member',
token_hash char(64) NOT NULL DEFAULT '',
pay_pence int(11) NOT NULL DEFAULT 0,
offered_at datetime NULL,
responded_at datetime NULL,
expires_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id),
KEY cleaner_id (cleaner_id),
KEY status (status)",

			'payments' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
type varchar(20) NOT NULL DEFAULT 'charge',
status varchar(20) NOT NULL DEFAULT 'pending',
provider varchar(20) NOT NULL DEFAULT 'stripe',
amount_pence int(11) NOT NULL DEFAULT 0,
currency char(3) NOT NULL DEFAULT 'gbp',
stripe_session_id varchar(191) NOT NULL DEFAULT '',
stripe_payment_intent varchar(191) NOT NULL DEFAULT '',
stripe_refund_id varchar(191) NOT NULL DEFAULT '',
stripe_payment_link_id varchar(191) NOT NULL DEFAULT '',
url text NULL,
note varchar(255) NOT NULL DEFAULT '',
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id),
KEY quote_id (quote_id),
KEY stripe_session_id (stripe_session_id),
KEY stripe_payment_link_id (stripe_payment_link_id)",

			'events' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
event varchar(60) NOT NULL DEFAULT '',
message text NULL,
data longtext NULL,
actor varchar(60) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY object (object_type,object_id),
KEY event (event),
KEY created_at (created_at)",
		];
	}
}
