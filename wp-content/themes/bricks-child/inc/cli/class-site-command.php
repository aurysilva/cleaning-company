<?php
namespace CC\Cli;

defined( 'ABSPATH' ) || exit;

use CC\Cli\Tree as T;
use WP_CLI;

/**
 * Builds the site: seeds content, creates Bricks templates, pages and menus.
 *
 *     wp cc setup            # everything, in the right order
 *     wp cc seed [--force]   # business settings, services, areas, local services
 *     wp cc images [--force] # import placeholder photos from seed/images.php
 *     wp cc guides           # import guides, categories and guide photos from seed/guides/
 *     wp cc guides_republish # rewrite 13 draft guides with new topics from seed/guides/republished.php
 *     wp cc rank_math        # re-sync Rank Math focus keywords and on-page SEO for all guides
 *     wp cc local            # (re)build service × area pages from seed/local-services/
 *     wp cc pages            # (re)build Bricks pages
 *     wp cc menus            # (re)build the primary menu
 *     wp cc templates        # (re)build header, footer and single templates
 *
 * Re-running is safe: posts are matched by slug and Bricks data is overwritten.
 */
final class Site_Command {

	private const TEMPLATE_META = '_cc_template_key';
	private const PAGE_META     = '_cc_page_key';
	private const MENU_NAME     = 'Primary';

	/**
	 * Runs settings, seed, pages, menus and templates.
	 *
	 * [--force]
	 * : Overwrite Business Settings even if already filled in.
	 */
	public function setup( $args, $assoc ) {
		$this->settings();
		$this->seed( $args, $assoc );
		$this->pages();
		$this->images( $args, [] );
		$this->guides( $args, [] );
		$this->menus();
		$this->templates();
		WP_CLI::success( 'Site built.' );
	}

	/**
	 * Bricks and WordPress settings.
	 */
	public function settings() {
		$settings = get_option( 'bricks_global_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$settings['postTypes']          = [ 'page', 'post' ];
		$settings['bricksCascadeLayer'] = 'on';
		$settings['disableGoogleFonts'] = 'on';
		$settings['disableEmojis']      = 'on';
		$settings['disableEmbed']       = 'on';

		update_option( 'bricks_global_settings', $settings );
		update_option( 'permalink_structure', '/guides/%postname%/' );

		foreach ( [ 'hello-world' => 'post', 'sample-page' => 'page' ] as $slug => $type ) {
			$post = get_page_by_path( $slug, OBJECT, $type );
			if ( $post && $post->post_status !== 'trash' ) {
				wp_trash_post( $post->ID );
				WP_CLI::log( "Trashed default {$type}: {$slug}" );
			}
		}

		WP_CLI::success( 'Settings updated.' );
	}

	/**
	 * Seeds Business Settings, services, areas and local services from seed/.
	 *
	 * [--force]
	 * : Overwrite Business Settings even if already filled in.
	 */
	public function seed( $args, $assoc ) {
		$this->as_admin();
		$this->seed_business( ! empty( $assoc['force'] ) );
		$services = $this->seed_services();
		$areas    = $this->seed_areas( $services );
		$this->seed_local_services( $services, $areas );
		flush_rewrite_rules( false );
		WP_CLI::success( 'Content seeded.' );
	}

	private function seed_business( bool $force ): void {
		if ( ! $force && get_field( 'brand_name', 'option' ) ) {
			WP_CLI::log( 'Business Settings already filled in — skipped (use --force to overwrite).' );
			return;
		}
		foreach ( $this->load_seed( 'business' ) as $name => $value ) {
			update_field( 'field_cc_business_' . $name, $value, 'option' );
		}
		WP_CLI::log( 'Seeded Business Settings.' );
	}

	/**
	 * @return array<string,int> slug => post ID
	 */
	private function seed_services(): array {
		$data = $this->load_seed( 'services' );
		$ids  = [];

		foreach ( $data as $slug => $service ) {
			$ids[ $slug ] = $this->upsert_post( 'cc_service', $slug, $service['title'], $service['order'] ?? 0 );
		}

		foreach ( $data as $slug => $service ) {
			$id = $ids[ $slug ];
			wp_set_object_terms( $id, $service['group'] ?? [], 'cc_service_group' );
			wp_set_object_terms( $id, $service['customer'] ?? [], 'cc_customer_type' );

			$fields                         = $service['fields'];
			$fields['use_global_guarantee'] = 1;
			$fields['related_services']     = array_values( array_filter( array_map( fn( $s ) => $ids[ $s ] ?? 0, $service['related'] ?? [] ) ) );

			foreach ( $fields as $name => $value ) {
				update_field( 'field_cc_service_' . $name, $value, $id );
			}
			WP_CLI::log( "Seeded service: {$service['title']}" );
		}
		return $ids;
	}

	private function seed_areas( array $services ): array {
		$ids = [];
		foreach ( $this->load_seed( 'areas' ) as $slug => $area ) {
			$id           = $this->upsert_post( 'cc_area', $slug, $area['title'], $area['order'] ?? 0 );
			$ids[ $slug ] = $id;

			$fields                     = $area['fields'];
			$fields['neighbourhoods']   = array_map( fn( $n ) => [ 'name' => $n ], $fields['neighbourhoods'] ?? [] );
			$fields['popular_services'] = array_values( array_filter( array_map( fn( $s ) => $services[ $s ] ?? 0, $area['popular'] ?? [] ) ) );

			foreach ( $fields as $name => $value ) {
				update_field( 'field_cc_area_' . $name, $value, $id );
			}
			WP_CLI::log( "Seeded area: {$area['title']}" );
		}
		return $ids;
	}

	/**
	 * (Re)builds Local Service pages (service × area) from seed/local-services/*.php.
	 */
	public function local( $args = [], $assoc = [] ) {
		$this->as_admin();
		$slugs = fn( string $type ) => array_column( array_map( fn( $p ) => [ $p->post_name, $p->ID ], get_posts( [ 'post_type' => $type, 'posts_per_page' => -1, 'post_status' => 'publish' ] ) ), 1, 0 );
		$this->seed_local_services( $slugs( 'cc_service' ), $slugs( 'cc_area' ) );
		flush_rewrite_rules( false );
		WP_CLI::success( 'Local service pages ready.' );
	}

	private function seed_local_services( array $services, array $areas ): void {
		$rows = [];
		foreach ( glob( CC_THEME_DIR . '/seed/local-services/*.php' ) as $file ) {
			$rows = array_merge( $rows, (array) require $file );
		}
		foreach ( $rows as $row ) {
			$service_id = $services[ $row['service'] ] ?? 0;
			$area_id    = $areas[ $row['area'] ] ?? 0;
			if ( ! $service_id || ! $area_id ) {
				WP_CLI::warning( "Skipped local service {$row['service']} / {$row['area']} (missing service or area)." );
				continue;
			}

			$existing = get_posts( [
				'post_type'      => 'cc_local_service',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[ 'key' => 'service', 'value' => $service_id ],
					[ 'key' => 'area', 'value' => $area_id ],
				],
			] );

			$title = get_the_title( $service_id ) . ' in ' . get_the_title( $area_id );
			$id    = wp_insert_post( [
				'ID'          => $existing ? (int) $existing[0] : 0,
				'post_type'   => 'cc_local_service',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => sanitize_title( $row['service'] . '-' . $row['area'] ),
			], true );

			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( $id->get_error_message() );
				continue;
			}

			update_field( 'field_cc_local_service_service', $service_id, $id );
			update_field( 'field_cc_local_service_area', $area_id, $id );
			foreach ( $row['fields'] as $name => $value ) {
				update_field( 'field_cc_local_service_' . $name, $value, $id );
			}
			WP_CLI::log( "Seeded local service: {$title}" );
		}
	}

	private function upsert_post( string $type, string $slug, string $title, int $order ): int {
		$existing = get_page_by_path( $slug, OBJECT, $type );
		$id       = wp_insert_post( [
			'ID'          => $existing ? $existing->ID : 0,
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
			'menu_order'  => $order,
		], true );

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Images
	 * ------------------------------------------------------------------ */

	/**
	 * Imports placeholder photos from seed/images.php into the Media Library (once each) and assigns them.
	 *
	 * [--force]
	 * : Replace featured images and Business Settings images that are already set.
	 */
	public function images( $args = [], $assoc = [] ) {
		$this->as_admin();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$force = ! empty( $assoc['force'] );

		foreach ( $this->load_seed( 'images' ) as $photo ) {
			$attachment = $this->unsplash_attachment( $photo );
			if ( ! $attachment ) {
				continue;
			}

			foreach ( $photo['targets'] as $target ) {
				[ $type, $key ] = explode( ':', $target, 2 );

				if ( 'option' === $type ) {
					if ( $force || ! get_field( $key, 'option' ) ) {
						update_field( 'field_cc_business_' . $key, $attachment, 'option' );
					}
					continue;
				}

				$post = get_page_by_path( $key, OBJECT, $type );
				if ( ! $post ) {
					WP_CLI::warning( "No {$type} with slug {$key}." );
					continue;
				}
				if ( $force || ! has_post_thumbnail( $post ) ) {
					set_post_thumbnail( $post, $attachment );
				}
			}
			WP_CLI::log( "Image ready: {$photo['alt']}" );
		}

		WP_CLI::success( 'Images ready.' );
	}

	private function unsplash_attachment( array $photo ): int {
		$existing = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_cc_unsplash_id',
			'meta_value'     => $photo['id'],
		] );
		if ( $existing ) {
			return (int) $existing[0];
		}

		$tmp = download_url( $photo['url'] . '?w=2400&q=75&fm=webp&fit=max', 120 );
		if ( is_wp_error( $tmp ) ) {
			WP_CLI::warning( "Download failed for {$photo['id']}: " . $tmp->get_error_message() );
			return 0;
		}

		$id = media_handle_sideload( [ 'name' => sanitize_title( $photo['alt'] ) . '.webp', 'tmp_name' => $tmp ], 0, $photo['alt'] );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			WP_CLI::warning( "Import failed for {$photo['id']}: " . $id->get_error_message() );
			return 0;
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $photo['alt'] );
		update_post_meta( $id, '_cc_unsplash_id', $photo['id'] );
		wp_update_post( [
			'ID'           => $id,
			'post_excerpt' => sprintf( 'Photo by %s on Unsplash', $photo['by'] ),
			'post_content' => sprintf( 'https://unsplash.com/photos/%s — https://unsplash.com/@%s', $photo['id'], $photo['user'] ),
		] );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Guides
	 * ------------------------------------------------------------------ */

	/**
	 * Imports guides from seed/guides/*.php: categories, posts, SEO fields, FAQs, internal links and photos.
	 *
	 * [--only=<slugs>]
	 * : Comma-separated guide slugs.
	 *
	 * [--force-images]
	 * : Replace featured images that are already set.
	 */
	public function guides( $args = [], $assoc = [] ) {
		$this->as_admin();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		update_option( 'permalink_structure', '/guides/%postname%/' );
		flush_rewrite_rules( false );

		$categories = $this->seed_guide_categories();
		$photos     = file_exists( CC_THEME_DIR . '/seed/guide-images.php' ) ? $this->load_seed( 'guide-images' ) : [];
		$only       = ! empty( $assoc['only'] ) ? array_map( 'trim', explode( ',', $assoc['only'] ) ) : [];

		$files = array_filter(
			glob( CC_THEME_DIR . '/seed/guides/*.php' ) ?: [],
			static fn( $f ) => ! in_array( basename( $f ), [ 'republished.php', 'republished-meta.php' ], true )
		);
		$order = array_flip( [ 'tenancy', 'moving-landlords', 'home-cleaning-1', 'home-cleaning-2', 'ovens-carpets', 'upholstery-builders', 'holiday-lets', 'business', 'hiring' ] );
		usort( $files, fn( $a, $b ) => ( $order[ basename( $a, '.php' ) ] ?? 99 ) <=> ( $order[ basename( $b, '.php' ) ] ?? 99 ) );

		$republish_map = is_readable( CC_THEME_DIR . '/seed/guides/republished-meta.php' )
			? (array) require CC_THEME_DIR . '/seed/guides/republished-meta.php'
			: [];
		$skip_slugs = array_values( array_unique( array_merge( array_keys( $republish_map ), array_values( $republish_map ) ) ) );

		$guides = [];
		foreach ( $files as $file ) {
			foreach ( (array) require $file as $guide ) {
				if ( empty( $guide['slug'] ) || in_array( $guide['slug'], $skip_slugs, true ) ) {
					continue;
				}
				$guides[ $guide['slug'] ] = $guide;
			}
		}
		if ( ! $guides ) {
			WP_CLI::error( 'No guides found in seed/guides/.' );
		}

		// Topic order, so the newest guides (and the home page list) lead with the core services
		$rank = array_flip( array_keys( $categories ) );
		$seq  = array_flip( array_keys( $guides ) );
		uksort( $guides, fn( $a, $b ) => [ $rank[ $guides[ $a ]['category'] ] ?? 99, $seq[ $a ] ] <=> [ $rank[ $guides[ $b ]['category'] ] ?? 99, $seq[ $b ] ] );

		// Pass 1: every guide must exist before links between guides can be resolved
		$ids   = [];
		$index = 0;
		foreach ( $guides as $slug => $guide ) {
			$existing = get_page_by_path( $slug, OBJECT, 'post' );
			if ( $only && ! in_array( $slug, $only, true ) ) {
				if ( $existing ) {
					$ids[ $slug ] = $existing->ID;
				}
				continue;
			}

			$post = [
				'ID'            => $existing ? $existing->ID : 0,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $guide['title'],
				'post_name'     => $slug,
				'post_excerpt'  => $guide['excerpt'],
				'post_category' => array_filter( [ $categories[ $guide['category'] ] ?? 0 ] ),
			];
			if ( ! $existing ) {
				// Stagger publish times so archives keep the seed order
				$post['post_date'] = wp_date( 'Y-m-d H:i:s', time() - ( ++$index * 7 * MINUTE_IN_SECONDS ) );
			}

			$id = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "{$slug}: " . $id->get_error_message() );
				continue;
			}
			$ids[ $slug ] = (int) $id;
		}

		// Pass 2: content, SEO fields, FAQs and photos
		foreach ( $guides as $slug => $guide ) {
			if ( empty( $ids[ $slug ] ) || ( $only && ! in_array( $slug, $only, true ) ) ) {
				continue;
			}
			$id      = $ids[ $slug ];
			$service = get_page_by_path( $guide['service'], OBJECT, 'cc_service' );

			wp_update_post( wp_slash( [
				'ID'           => $id,
				'post_content' => $this->resolve_links( $guide['content'], $slug ),
			] ) );

			update_field( 'field_cc_guide_seo_title', $guide['seo_title'] ?? '', $id );
			update_field( 'field_cc_guide_meta_description', $guide['description'] ?? '', $id );
			if ( function_exists( 'cc_rank_math_sync_guide' ) ) {
				cc_rank_math_sync_guide( $id, true );
				update_post_meta( $id, 'rank_math_seo_score', cc_rank_math_compute_score( $id ) );
			}
			update_field( 'field_cc_guide_pillar_service', $service ? $service->ID : null, $id );
			update_field( 'field_cc_guide_related_guides', array_values( array_filter( array_map( fn( $s ) => $ids[ $s ] ?? 0, $guide['related'] ?? [] ) ) ), $id );
			update_field( 'field_cc_guide_faqs', array_map( fn( $faq ) => [ 'question' => $faq[0], 'answer' => $faq[1] ], $guide['faqs'] ?? [] ), $id );

			if ( ! has_post_thumbnail( $id ) || ! empty( $assoc['force-images'] ) ) {
				$attachment = ! empty( $photos[ $slug ] ) ? $this->unsplash_attachment( $photos[ $slug ] ) : 0;
				if ( ! $attachment && $service ) {
					$attachment = (int) get_post_thumbnail_id( $service );
				}
				if ( $attachment ) {
					set_post_thumbnail( $id, $attachment );
				}
			}

			WP_CLI::log( "Guide ready: {$guide['title']}" );
		}

		WP_CLI::success( sprintf( '%d guides ready.', count( $ids ) ) );
	}

	/**
	 * Repurpose merge/hold-back draft guides: same post IDs, new slugs and content.
	 *
	 * [--dry-run]
	 * : List changes without writing to the database.
	 *
	 * [--only=<slugs>]
	 * : Comma-separated new guide slugs to process (default: all in republished.php).
	 */
	public function guides_republish( $args = [], $assoc = [] ) {
		$this->as_admin();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$map_file = CC_THEME_DIR . '/seed/guides/republished-meta.php';
		$guides_file = CC_THEME_DIR . '/seed/guides/republished.php';
		if ( ! is_readable( $map_file ) || ! is_readable( $guides_file ) ) {
			WP_CLI::error( 'Missing seed/guides/republished-meta.php or republished.php.' );
		}

		/** @var array<string,string> $replace_to_slug */
		$replace_to_slug = require $map_file;
		/** @var list<array> $guides */
		$guides = require $guides_file;
		$by_slug = [];
		foreach ( $guides as $guide ) {
			if ( empty( $guide['slug'] ) ) {
				continue;
			}
			$by_slug[ $guide['slug'] ] = $guide;
		}

		$only = ! empty( $assoc['only'] ) ? array_map( 'trim', explode( ',', $assoc['only'] ) ) : [];
		$dry  = ! empty( $assoc['dry-run'] );
		$categories = $this->seed_guide_categories();
		$photos     = file_exists( CC_THEME_DIR . '/seed/guide-images.php' ) ? $this->load_seed( 'guide-images' ) : [];
		$slug_to_id = [];
		foreach ( get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $pid ) {
			$post = get_post( $pid );
			if ( $post && $post->post_name ) {
				$slug_to_id[ $post->post_name ] = (int) $pid;
			}
		}

		$done = 0;
		foreach ( $replace_to_slug as $old_slug => $new_slug ) {
			if ( $only && ! in_array( $new_slug, $only, true ) ) {
				continue;
			}
			if ( empty( $by_slug[ $new_slug ] ) ) {
				WP_CLI::warning( "No seed content for new slug \"{$new_slug}\"." );
				continue;
			}
			$guide = $by_slug[ $new_slug ];
			$existing = get_page_by_path( $old_slug, OBJECT, 'post' );
			if ( ! $existing ) {
				$existing = get_page_by_path( $new_slug, OBJECT, 'post' );
			}
			if ( ! $existing ) {
				WP_CLI::warning( "No post found for \"{$old_slug}\" or \"{$new_slug}\" — skipped." );
				continue;
			}

			if ( $dry ) {
				WP_CLI::log( sprintf( 'Would republish #%d: %s → %s', $existing->ID, $old_slug, $new_slug ) );
				++$done;
				continue;
			}

			$service = get_page_by_path( $guide['service'], OBJECT, 'cc_service' );
			$post_id = (int) $existing->ID;
			wp_update_post( wp_slash( [
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_title'    => $guide['title'],
				'post_name'     => $new_slug,
				'post_excerpt'  => $guide['excerpt'],
				'post_content'  => $this->resolve_links( $guide['content'], $new_slug ),
				'post_category' => array_filter( [ $categories[ $guide['category'] ] ?? 0 ] ),
			] ) );

			update_field( 'field_cc_guide_seo_title', $guide['seo_title'] ?? '', $post_id );
			update_field( 'field_cc_guide_meta_description', $guide['description'] ?? '', $post_id );
			update_field( 'field_cc_guide_pillar_service', $service ? $service->ID : null, $post_id );
			$related_ids = array_values( array_filter( array_map(
				function ( $s ) use ( $slug_to_id ) {
					if ( isset( $slug_to_id[ $s ] ) ) {
						return $slug_to_id[ $s ];
					}
					$related = get_page_by_path( $s, OBJECT, 'post' );
					return $related ? (int) $related->ID : 0;
				},
				$guide['related'] ?? []
			) ) );
			update_field( 'field_cc_guide_related_guides', $related_ids, $post_id );
			update_field( 'field_cc_guide_faqs', array_map( fn( $faq ) => [ 'question' => $faq[0], 'answer' => $faq[1] ], $guide['faqs'] ?? [] ), $post_id );

			if ( function_exists( 'cc_rank_math_sync_guide' ) ) {
				cc_rank_math_sync_guide( $post_id, true );
				update_post_meta( $post_id, 'rank_math_seo_score', cc_rank_math_compute_score( $post_id ) );
			}

			$photo_key = $photos[ $new_slug ] ?? $photos[ $old_slug ] ?? null;
			if ( $photo_key ) {
				$attachment = $this->unsplash_attachment( $photo_key );
				if ( $attachment ) {
					set_post_thumbnail( $post_id, $attachment );
				}
			}

			$slug_to_id[ $new_slug ] = $post_id;
			unset( $slug_to_id[ $old_slug ] );
			WP_CLI::log( sprintf( 'Republished #%d as %s', $post_id, $new_slug ) );
			++$done;
		}

		if ( ! $dry ) {
			flush_rewrite_rules( false );
		}
		WP_CLI::success( $dry ? "{$done} guides would be republished." : "{$done} guides republished." );
	}

	/**
	 * Re-sync Rank Math fields and on-page fixes for every published guide.
	 */
	public function rank_math() {
		$this->as_admin();
		if ( ! function_exists( 'cc_rank_math_sync_all_guides' ) ) {
			WP_CLI::error( 'Rank Math guide helpers are not loaded.' );
		}
		$result = cc_rank_math_sync_all_guides( true );
		$low    = [];
		foreach ( get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $id ) {
			$score = (int) get_post_meta( (int) $id, 'rank_math_seo_score', true );
			if ( $score < 90 ) {
				$low[] = sprintf( '%d  %s', $score, get_post_field( 'post_name', $id ) );
			}
		}
		if ( $low ) {
			WP_CLI::log( 'Still below 90:' );
			foreach ( $low as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}
		WP_CLI::success( sprintf( 'Updated %d guides. Average score: %.1f.', $result['updated'], $result['avg_score'] ) );
	}

	/**
	 * @return array<string,int> category slug => term ID
	 */
	private function seed_guide_categories(): array {
		$ids = [];
		foreach ( $this->load_seed( 'guide-categories' ) as $slug => $category ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			$args = [ 'name' => $category['name'], 'slug' => $slug, 'description' => $category['description'] ];
			$result = $term ? wp_update_term( $term->term_id, 'category', $args ) : wp_insert_term( $category['name'], 'category', $args );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "Category {$slug}: " . $result->get_error_message() );
				continue;
			}
			$ids[ $slug ] = (int) $result['term_id'];
			update_term_meta( $ids[ $slug ], 'cc_service', $category['service'] ?? '' );
			update_term_meta( $ids[ $slug ], 'cc_order', count( $ids ) );
		}
		update_option( 'default_category', reset( $ids ) ?: 1 );
		return $ids;
	}

	/**
	 * Turns href="{{type:slug}}" tokens into root-relative URLs (so links survive a domain change).
	 * Links to anything that doesn't exist are reduced to their text.
	 */
	private function resolve_links( string $html, string $context ): string {
		$types = [ 'guide' => 'post', 'service' => 'cc_service', 'area' => 'cc_area', 'local' => 'cc_local_service', 'page' => 'page' ];

		return preg_replace_callback(
			'#<a\s([^>]*?)href="\{\{(\w+):([a-z0-9-]+)\}\}"([^>]*)>(.*?)</a>#s',
			function ( $m ) use ( $types, $context ) {
				$post = isset( $types[ $m[2] ] ) ? get_page_by_path( $m[3], OBJECT, $types[ $m[2] ] ) : null;
				if ( ! $post || 'publish' !== $post->post_status ) {
					WP_CLI::warning( "{$context}: no {$m[2]} \"{$m[3]}\" — link removed." );
					return $m[5];
				}
				return sprintf( '<a %shref="%s"%s>%s</a>', $m[1], esc_url( wp_make_link_relative( get_permalink( $post ) ) ), $m[4], $m[5] );
			},
			$html
		);
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	private function page_manifest(): array {
		return [
			'home'                  => [ 'Home', [ Sections::class, 'home' ] ],
			'services'              => [ 'Cleaning Services', [ Sections::class, 'services_hub' ] ],
			'areas'                 => [ 'Areas We Cover', [ Sections::class, 'areas_hub' ] ],
			'about'                 => [ 'About Us', [ Sections::class, 'about' ] ],
			'pricing'               => [ 'Pricing', [ Sections::class, 'pricing' ] ],
			'contact'               => [ 'Contact', [ Sections::class, 'contact' ] ],
			'landlords'             => [ 'Landlords & Letting Agents', [ Sections::class, 'landlords' ] ],
			'business'              => [ 'Business Cleaning', [ Sections::class, 'business' ] ],
			'our-guarantee'         => [ 'Our Guarantee', [ Sections::class, 'guarantee' ] ],
			'reviews'               => [ 'Reviews', [ Sections::class, 'reviews' ] ],
			'faq'                   => [ 'FAQs', [ Sections::class, 'faq' ] ],
			'careers'               => [ 'Careers', [ Sections::class, 'careers' ] ],
			'guides'                => [ 'Cleaning Guides', [ Sections::class, 'guides' ] ],
			'privacy-policy'        => [ 'Privacy Policy', [ Sections::class, 'legal' ], [ $this->legal_text( 'privacy' ) ] ],
			'terms'                 => [ 'Terms & Conditions', [ Sections::class, 'legal' ], [ $this->legal_text( 'terms' ) ] ],
			'cookie-policy'         => [ 'Cookie Policy', [ Sections::class, 'legal' ], [ $this->legal_text( 'cookies' ) ] ],
		];
	}

	/**
	 * (Re)builds Bricks pages.
	 *
	 * [--only=<slugs>]
	 * : Comma-separated page slugs.
	 */
	public function pages( $args = [], $assoc = [] ) {
		$this->as_admin();
		$only = ! empty( $assoc['only'] ) ? array_map( 'trim', explode( ',', $assoc['only'] ) ) : [];

		foreach ( $this->page_manifest() as $slug => $def ) {
			if ( $only && ! in_array( $slug, $only, true ) ) {
				continue;
			}
			[ $title, $callback ] = $def;
			$existing             = get_page_by_path( $slug, OBJECT, 'page' );

			$id = wp_insert_post( [
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_content' => '',
			], true );

			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id->get_error_message() );
			}

			update_post_meta( $id, self::PAGE_META, $slug );
			update_post_meta( $id, BRICKS_DB_EDITOR_MODE, 'bricks' );
			update_post_meta( $id, BRICKS_DB_PAGE_CONTENT, wp_slash( T::flatten( call_user_func_array( $callback, $def[2] ?? [] ) ) ) );

			WP_CLI::log( ( $existing ? 'Rebuilt' : 'Created' ) . " page: {$title}" );
		}

		$home = get_page_by_path( 'home', OBJECT, 'page' );
		if ( $home ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $home->ID );
		}
		$privacy = get_page_by_path( 'privacy-policy', OBJECT, 'page' );
		if ( $privacy ) {
			update_option( 'wp_page_for_privacy_policy', $privacy->ID );
		}

		WP_CLI::success( 'Pages ready.' );
	}

	/* ---------------------------------------------------------------------
	 * Menus
	 * ------------------------------------------------------------------ */

	/**
	 * (Re)builds the primary menu: Services and Areas with dropdowns, then key pages.
	 */
	public function menus() {
		$menu = wp_get_nav_menu_object( self::MENU_NAME );
		$id   = $menu ? $menu->term_id : wp_create_nav_menu( self::MENU_NAME );

		foreach ( (array) wp_get_nav_menu_items( $id ) as $item ) {
			wp_delete_post( $item->ID, true );
		}

		$add_page = function ( string $slug, string $label = '', int $parent = 0 ) use ( $id ): int {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $page ) {
				return 0;
			}
			return (int) wp_update_nav_menu_item( $id, 0, [
				'menu-item-title'     => $label ?: $page->post_title,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page->ID,
				'menu-item-type'      => 'post_type',
				'menu-item-parent-id' => $parent,
				'menu-item-status'    => 'publish',
			] );
		};

		$add_post = function ( \WP_Post $post, int $parent ) use ( $id ): void {
			wp_update_nav_menu_item( $id, 0, [
				'menu-item-title'     => $post->post_title,
				'menu-item-object'    => $post->post_type,
				'menu-item-object-id' => $post->ID,
				'menu-item-type'      => 'post_type',
				'menu-item-parent-id' => $parent,
				'menu-item-status'    => 'publish',
			] );
		};

		$services = $add_page( 'services', 'Services' );
		foreach ( cc_services() as $post ) {
			$add_post( $post, $services );
		}

		$areas = $add_page( 'areas', 'Areas' );
		foreach ( cc_featured_areas() as $post ) {
			$add_post( $post, $areas );
		}
		if ( count( cc_featured_areas() ) < count( cc_areas() ) ) {
			$add_page( 'areas', 'All areas', $areas );
		}

		$add_page( 'pricing' );
		$add_page( 'landlords', 'Landlords' );
		$add_page( 'business', 'Business' );
		$add_page( 'about', 'About' );
		$add_page( 'guides', 'Blog' );
		$add_page( 'contact' );

		WP_CLI::success( 'Menu ready.' );
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	private function template_manifest(): array {
		$menu    = wp_get_nav_menu_object( self::MENU_NAME );
		$menu_id = $menu ? (int) $menu->term_id : 0;

		return [
			'header'               => [ 'Header', 'header', [ Sections::class, 'header' ], [ [ 'main' => 'any' ] ], [ $menu_id ] ],
			'footer'               => [ 'Footer', 'footer', [ Sections::class, 'footer' ], [ [ 'main' => 'any' ] ] ],
			'single-service'       => [ 'Single — Service', 'content', [ Sections::class, 'service_single' ], [ [ 'main' => 'postType', 'postType' => [ 'cc_service' ] ] ] ],
			'single-local-service' => [ 'Single — Local service', 'content', [ Sections::class, 'local_service_single' ], [ [ 'main' => 'postType', 'postType' => [ 'cc_local_service' ] ] ] ],
			'single-area'          => [ 'Single — Area', 'content', [ Sections::class, 'area_single' ], [ [ 'main' => 'postType', 'postType' => [ 'cc_area' ] ] ] ],
			'single-post'          => [ 'Single — Guide', 'content', [ Sections::class, 'post_single' ], [ [ 'main' => 'postType', 'postType' => [ 'post' ] ] ] ],
			'error-404'            => [ 'Error — 404', 'error', [ Sections::class, 'error_404' ], [ [ 'main' => 'error' ] ] ],
		];
	}

	/**
	 * (Re)builds Bricks templates.
	 *
	 * [--only=<keys>]
	 * : Comma-separated template keys.
	 */
	public function templates( $args = [], $assoc = [] ) {
		$this->as_admin();
		$only = ! empty( $assoc['only'] ) ? array_map( 'trim', explode( ',', $assoc['only'] ) ) : [];

		foreach ( $this->template_manifest() as $key => $def ) {
			if ( $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			[ $title, $type, $callback, $conditions ] = $def;

			$existing = get_posts( [
				'post_type'      => 'bricks_template',
				'meta_key'       => self::TEMPLATE_META,
				'meta_value'     => $key,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			] );

			$id = wp_insert_post( [
				'ID'          => $existing ? (int) $existing[0] : 0,
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => 'cc-' . $key,
			], true );

			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id->get_error_message() );
			}

			$meta_key = 'header' === $type ? BRICKS_DB_PAGE_HEADER : ( 'footer' === $type ? BRICKS_DB_PAGE_FOOTER : BRICKS_DB_PAGE_CONTENT );

			update_post_meta( $id, self::TEMPLATE_META, $key );
			update_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, $type );
			update_post_meta( $id, BRICKS_DB_EDITOR_MODE, 'bricks' );
			update_post_meta( $id, $meta_key, wp_slash( T::flatten( call_user_func_array( $callback, $def[4] ?? [] ) ) ) );

			foreach ( $conditions as &$condition ) {
				$condition['id'] = T::id();
			}
			unset( $condition );

			$settings = [ 'templateConditions' => $conditions ];
			if ( 'content' === $type ) {
				$preview = get_posts( [ 'post_type' => $conditions[0]['postType'][0], 'posts_per_page' => 1, 'fields' => 'ids' ] );
				$settings['templatePreviewType']   = 'single';
				$settings['templatePreviewPostId'] = $preview ? (string) $preview[0] : '';
			}
			update_post_meta( $id, BRICKS_DB_TEMPLATE_SETTINGS, $settings );

			WP_CLI::log( ( $existing ? 'Rebuilt' : 'Created' ) . " template: {$title}" );
		}

		if ( class_exists( '\Bricks\Templates' ) && method_exists( '\Bricks\Templates', 'generate_templates_css_files' ) ) {
			\Bricks\Templates::generate_templates_css_files();
		}

		WP_CLI::success( 'Templates ready.' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function load_seed( string $name ): array {
		$file = CC_THEME_DIR . '/seed/' . $name . '.php';
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( "Seed file missing: seed/{$name}.php" );
		}
		return (array) require $file;
	}

	/** Bricks only saves page/template element data for users who can use the builder. */
	private function as_admin(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( $admins ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	private function legal_text( string $which ): string {
		switch ( $which ) {
			case 'privacy':
				return cc_legal_privacy_html();
			case 'terms':
				return cc_legal_terms_html();
			case 'cookies':
				return cc_legal_cookies_html();
		}
		return '';
	}
}
