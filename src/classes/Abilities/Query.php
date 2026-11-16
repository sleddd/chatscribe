<?php namespace ChatScribe\Abilities;

use ChatScribe\Admin\SettingsPage;

/**
 * What the two content search abilities share: the search itself, the checks that a
 * match is something this user could see, and how each match is described.
 *   FrontendQuery - chatscribe/frontend-query, for site visitors and the frontend chat.
 *   BackendQuery  - chatscribe/backend-query, for signed-in users in the admin.
 *
 * What each one can search (post types, taxonomies, statuses) and who can use it
 * (roles) come from Settings > ChatScribe. Those settings only ever narrow things down:
 * whatever is ticked, a match is only returned if the signed-in user (or visitor)
 * could already see it themselves. See user_can_see().
 */
abstract class Query {

	const FRONTEND = 'chatscribe/frontend-query';
	const BACKEND  = 'chatscribe/backend-query';

	// 'frontend' or 'backend', set by each ability.
	const CONTEXT = '';

	// The most matches checked one by one against the user's own permissions.
	const MAX_CHECKED = 1000;

	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/*
	------------------------------------------------------------------ *
	 * What each ability sets for itself
	 * ------------------------------------------------------------------ */

	/**
	 * The ability's label, as shown in the Abilities API.
	 */
	abstract protected function label(): string;

	/**
	 * How the "source" input describes one kind of other data, such as
	 * ', "orders" for ...'. Empty for sources this ability does not offer.
	 */
	abstract protected function describe_source( string $source ): string;

	/**
	 * The error when the user may not search a kind of other data.
	 */
	abstract protected function source_denied(): \WP_Error;

	/**
	 * Query arguments this ability always adds, to content and artwork searches alike.
	 */
	protected function query_args(): array {
		return array();
	}

	/**
	 * Extra tax_query clauses this ability always adds.
	 */
	protected function extra_tax_query( array $post_types, string $search ): array {
		return array();
	}

	/**
	 * Extra details this ability adds to each match.
	 */
	protected function item_extras( \WP_Post $post ): array {
		return array();
	}

	/**
	 * The ability's name, such as 'chatscribe/backend-query'.
	 */
	protected function ability_name(): string {
		return self::name( static::CONTEXT );
	}

	/**
	 * The post statuses this ability can search.
	 */
	protected function statuses(): array {
		return SettingsPage::allowed_statuses( static::CONTEXT );
	}

	/**
	 * The other data (users, options, orders...) this ability can search.
	 */
	protected function sources(): array {
		return DataSources::searchable( static::CONTEXT );
	}

	/**
	 * Whether the current user may use this ability.
	 */
	protected function user_can_use(): bool {
		return SettingsPage::user_can_use( static::CONTEXT );
	}

	/**
	 * The ability's meta: where else it is offered.
	 */
	protected function ability_meta(): array {
		return array(
			'mcp' => array(
				'public' => true,
			),
		);
	}

	/*
	------------------------------------------------------------------ *
	 * Shared
	 * ------------------------------------------------------------------ */

	public static function name( string $context ): string {
		return 'backend' === $context ? self::BACKEND : self::FRONTEND;
	}

	/**
	 * The post types this ability can search, as [ post type => [ taxonomies ] ].
	 */
	public function content(): array {
		return SettingsPage::allowed_content( static::CONTEXT . '_access', static::CONTEXT );
	}

	/**
	 * Whether this is the art site, where products are artworks made by artists.
	 */
	public static function is_art_site(): bool {
		return post_type_exists( 'artist' );
	}

	/**
	 * Other names the assistant uses for post types: on the art site, "artwork" for products.
	 */
	public static function aliases(): array {
		return self::is_art_site() ? array( 'artwork' => 'product' ) : array();
	}

	/**
	 * The post_type value the assistant uses: "artwork" for products on the art site,
	 * otherwise the post type itself.
	 */
	public static function public_type( string $post_type ): string {
		$alias = array_search( $post_type, self::aliases(), true );
		return false === $alias ? $post_type : $alias;
	}

	public function register_ability() {
		$content    = $this->content();
		$types      = array_map( array( self::class, 'public_type' ), array_keys( $content ) );
		$taxonomies = array_values( array_unique( array_merge( array(), ...array_values( $content ) ) ) );

		$post_type = array(
			'type'        => 'string',
			'description' => 'The kind of content. Leave out to search everything.'
				. ( isset( $content['product'] ) && self::is_art_site() ? ' "artwork" and "product" are the same thing.' : '' ),
		);
		if ( $types ) {
			$post_type['enum'] = $types;
		}

		$properties = array(
			'post_type'  => $post_type,
			'search'     => array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'Key words to match, such as a title, topic or name. Leave out to list everything of the post type.',
			),
			'orderby'    => array(
				'type' => 'string',
				'enum' => array( 'date', 'title' ),
			),
			'limit'      => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 50,
			),
			'offset'     => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'How many matches to skip, to get the next part of a list.',
			),
			'count_only' => array( 'type' => 'boolean' ),
		);
		if ( $taxonomies ) {
			$properties['taxonomy'] = array(
				'type'        => 'string',
				'enum'        => $taxonomies,
				'description' => 'To filter by a category, tag or other grouping: the taxonomy it belongs to, such as "category". Use with "term".',
			);
			$properties['term']     = array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'The slug of the category, tag or other term within "taxonomy".',
			);
		}

		// Other data, when ticked in the settings: backend users, options, comments and orders;
		// frontend only the signed-in customer's own orders.
		$sources = $this->sources();
		if ( $sources ) {
			$properties['source'] = array(
				'type'        => 'string',
				'enum'        => array_merge( array( 'content' ), $sources ),
				'description' => 'What to search: "content" (the default) for posts, pages, products and other content'
					. implode( '', array_map( array( $this, 'describe_source' ), $sources ) )
					. '. post_type, taxonomy and term only apply to content.',
			);
			if ( in_array( 'users', $sources, true ) ) {
				$properties['role'] = array(
					'type'        => 'string',
					'enum'        => array_keys( wp_roles()->get_names() ),
					'description' => 'With source "users": only users with this role.',
				);
			}
			if ( in_array( 'orders', $sources, true ) ) {
				$properties['order_status'] = array(
					'type'        => 'string',
					'enum'        => array_keys( WooCommerce::order_statuses() ),
					'description' => 'With source "orders": only orders with this status.',
				);
			}
		}

		wp_register_ability(
			$this->ability_name(),
			array(
				'label'               => $this->label(),
				'description'         => $this->describe( $content ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $properties,
				),
				'execute_callback'    => function ( $input = array() ) {
					return $this->run_query( (array) $input );
				},
				'permission_callback' => function () {
					return $this->user_can_use();
				},
				'meta'                => $this->ability_meta(),
			)
		);
	}

	private function describe( array $content ): string {
		$sources = $this->sources();
		$extra   = $sources ? ' It can also search ' . implode( ', ', $sources ) . ' (set "source").' : '';
		return $this->describe_content( $content ) . $extra;
	}

	private function describe_content( array $content ): string {
		$labels = array();
		foreach ( array_keys( $content ) as $type ) {
			$object   = get_post_type_object( $type );
			$labels[] = $object ? strtolower( $object->labels->name ) : $type;
		}

		$text  = $labels
			? 'Searches or lists this site\'s ' . implode( ', ', $labels ) . '.'
			: 'Searches this site\'s content. No content is available to search right now.';
		$text .= ' Returns the total number of matches and, for each match, its title, link, date and text.';
		if ( isset( $content['product'], $content['artist'] ) ) {
			$text .= ' Searching for an artist\'s name also returns the artworks by that artist.';
		}
		return $text . ' Leave "search" empty to list everything of a post type.';
	}

	/**
	 * Runs the search and returns only what the assistant needs: how many
	 * matches there are, and each match's title, link, date and text.
	 */
	private function run_query( array $input ) {
		$content  = $this->content();
		$statuses = $this->statuses();

		// Other data: only when ticked for this context, and only if this user could see it.
		$source = (string) ( $input['source'] ?? 'content' );
		if ( '' !== $source && 'content' !== $source ) {
			if ( ! in_array( $source, $this->sources(), true ) ) {
				return new \WP_Error( 'source_not_allowed', 'That kind of data cannot be searched.' );
			}
			if ( ! DataSources::user_can( $source, static::CONTEXT ) ) {
				return $this->source_denied();
			}
			return DataSources::search( $source, $input, array_keys( $content ), static::CONTEXT );
		}

		if ( ! $content || ! $statuses ) {
			return new \WP_Error( 'no_content', 'No content is available to search.' );
		}

		$requested = (string) ( $input['post_type'] ?? '' );
		$requested = self::aliases()[ $requested ] ?? $requested;
		if ( '' !== $requested && ! isset( $content[ $requested ] ) ) {
			return new \WP_Error( 'post_type_not_allowed', 'That kind of content cannot be searched.' );
		}
		$post_types = '' === $requested ? array_keys( $content ) : array( $requested );

		$search  = trim( (string) ( $input['search'] ?? '' ) );
		$orderby = $input['orderby'] ?? ( '' === $search ? 'date' : 'relevance' );
		$count   = ! empty( $input['count_only'] );
		$limit   = min( 50, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
		$offset  = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$args = array(
			'post_status'         => $statuses,
			'ignore_sticky_posts' => true,
			'orderby'             => $orderby,
			'order'               => 'title' === $orderby ? 'ASC' : 'DESC',
		) + $this->query_args();
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// Filter by a term, limited to the post types allowed to use that taxonomy.
		$tax_query = array();
		$taxonomy  = (string) ( $input['taxonomy'] ?? '' );
		$term      = trim( (string) ( $input['term'] ?? '' ) );
		if ( '' !== $taxonomy && '' !== $term ) {
			$post_types = array_values(
				array_filter(
					$post_types,
					function ( $type ) use ( $content, $taxonomy ) {
						return in_array( $taxonomy, $content[ $type ], true );
					}
				)
			);
			if ( ! $post_types ) {
				return new \WP_Error( 'taxonomy_not_allowed', 'That grouping cannot be used to filter this content.' );
			}
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => sanitize_title( $term ),
			);
		}

		$tax_query = array_merge( $tax_query, $this->extra_tax_query( $post_types, $search ) );
		if ( $tax_query ) {
			$args['tax_query'] = count( $tax_query ) > 1 ? array_merge( array( 'relation' => 'AND' ), $tax_query ) : $tax_query;
		}
		$args['post_type'] = $post_types;

		// One search covers artworks by their artist too: when the words match an artist's
		// name, that artist's artworks are added to the ordinary matches.
		if ( '' !== $search && in_array( 'product', $post_types, true ) && isset( $content['artist'] ) ) {
			$by_artist = $this->artworks_by_artist_named( $search, $statuses );

			if ( $by_artist ) {
				$matches          = get_posts(
					array(
						'fields'         => 'ids',
						'posts_per_page' => 200,
						'offset'         => 0,
					) + $args
				);
				$args['post__in'] = array_values( array_unique( array_merge( $matches, $by_artist ) ) );
				unset( $args['s'] );
				if ( 'relevance' === $orderby ) {
					// Ordinary matches first, then the artist's artworks.
					$args['orderby'] = 'post__in';
				}
			}
		}

		[ $total, $posts ] = $this->needs_post_checks( $post_types, $statuses )
			? $this->fetch_checked( $args, $count, $limit, $offset )
			: $this->fetch( $args, $count, $limit, $offset );

		$result = array( 'total' => $total );

		// Where a visitor can browse the whole list, such as the shop page for artworks.
		if ( 1 === count( $post_types ) && get_post_type_archive_link( $post_types[0] ) ) {
			$result['browse_all_link'] = get_post_type_archive_link( $post_types[0] );
		}
		if ( $count ) {
			return $result;
		}

		$result['items'] = array();
		foreach ( $posts as $post ) {
			$result['items'][] = $this->item( $post );
		}

		return $result;
	}

	/**
	 * Published content of a public post type is visible to anyone, so it needs no
	 * checks. Anything else is checked post by post with user_can_see().
	 */
	protected function needs_post_checks( array $post_types, array $statuses ): bool {
		if ( array_diff( $statuses, array( 'publish' ) ) ) {
			return true;
		}
		foreach ( $post_types as $type ) {
			if ( ! is_post_type_viewable( $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user (or visitor) could see this post themselves, without the AI.
	 * The settings never give the AI more than this.
	 * - Published content of a public post type: anyone can see it on the site.
	 * - Private, draft, pending or scheduled content: only if WordPress lets this user read it.
	 * - Post types with no public view (admin-only): only if this user can edit it.
	 */
	public static function user_can_see( int $post_id ): bool {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return false;
		}
		// Admin-only content, and password-protected content (whose text the AI would reveal).
		if ( ! is_post_type_viewable( $post_type ) || '' !== (string) get_post_field( 'post_password', $post_id ) ) {
			return current_user_can( 'edit_post', $post_id );
		}

		$status = get_post_status_object( get_post_status( $post_id ) );
		if ( $status && $status->public ) {
			return true;
		}
		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * @return array [ total, posts ]
	 */
	private function fetch( array $args, bool $count, int $limit, int $offset ): array {
		$args += array(
			'posts_per_page' => $count ? 1 : $limit,
			'offset'         => $count ? 0 : $offset,
			'fields'         => $count ? 'ids' : '',
		);
		$query = new \WP_Query( $args );

		return array( (int) $query->found_posts, $count ? array() : $query->posts );
	}

	/**
	 * Like fetch(), but keeps only the matches the current user could see themselves.
	 *
	 * @return array [ total, posts ]
	 */
	private function fetch_checked( array $args, bool $count, int $limit, int $offset ): array {
		$ids = get_posts(
			array(
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_CHECKED,
				'offset'         => 0,
			) + $args
		);
		$ids = array_values( array_filter( array_map( 'intval', $ids ), array( self::class, 'user_can_see' ) ) );

		$page = $count ? array() : array_slice( $ids, $offset, $limit );
		if ( ! $page ) {
			return array( count( $ids ), array() );
		}

		$posts = get_posts(
			array(
				'post__in'       => $page,
				'orderby'        => 'post__in',
				'post_type'      => $args['post_type'],
				'post_status'    => $args['post_status'],
				'posts_per_page' => count( $page ),
			)
		);

		return array( count( $ids ), $posts );
	}

	private function item( \WP_Post $post ): array {
		$item = array(
			'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'link'  => get_permalink( $post ),
			'type'  => self::public_type( $post->post_type ),
			'date'  => get_the_date( 'F j, Y', $post ),
		);

		if ( 'publish' !== $post->post_status ) {
			$item['status'] = $post->post_status;
		}
		$item += $this->item_extras( $post );

		if ( 'product' === $post->post_type ) {
			// Price, stock, categories, attributes and so on.
			if ( WooCommerce::active() ) {
				$item += WooCommerce::product_details( $post->ID, static::CONTEXT );
			}
			// On the art site: who made the artwork, and whether it can be bought.
			if ( self::is_art_site() ) {
				$artists = $this->artist_names( $post->ID );
				if ( $artists ) {
					$item['artists'] = $artists;
				}
				$availability = $this->availability( $post->ID );
				if ( $availability ) {
					$item['availability'] = $availability;
				}
			}
		}

		$text = wp_strip_all_tags( strip_shortcodes( $post->post_excerpt . "\n" . $post->post_content ), true );
		if ( '' !== $text ) {
			$item['content'] = wp_trim_words( $text, 300 );
		}

		return $item;
	}

	/**
	 * The IDs of the artworks by any artist whose name matches the search.
	 */
	private function artworks_by_artist_named( string $name, array $statuses ): array {
		$artist_ids = get_posts(
			array(
				'post_type'      => 'artist',
				'post_status'    => $statuses,
				's'              => $name,
				'search_columns' => array( 'post_title' ),
				'posts_per_page' => 5,
				'fields'         => 'ids',
			)
		);

		if ( ! $artist_ids ) {
			return array();
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => $statuses,
			'meta_query'     => $this->artist_meta_query( $artist_ids ),
			'posts_per_page' => 500,
			'fields'         => 'ids',
		) + $this->query_args();

		return get_posts( $args );
	}

	/**
	 * Matches artworks by any of the given artists. An artwork keeps its artists as a
	 * saved list of IDs, so each ID is matched in quotes (which stops 12 matching 120).
	 * Older artworks keep a single ID under product_artist.
	 */
	private function artist_meta_query( array $artist_ids ) {
		$query = array( 'relation' => 'OR' );

		foreach ( $artist_ids as $id ) {
			$query[] = array(
				'key'     => 'artwork_artist',
				'value'   => '"' . (int) $id . '"',
				'compare' => 'LIKE',
			);
			$query[] = array(
				'key'   => 'product_artist',
				'value' => (int) $id,
			);
		}

		return $query;
	}

	/**
	 * Whether an artwork can be bought, in words the assistant can pass on.
	 */
	private function availability( $product_id ) {
		$labels = array(
			'instock'       => 'Available',
			'artsy'         => 'Available',
			'inquiry_only'  => 'Available on inquiry',
			'sold'          => 'Sold',
			'outofstock'    => 'Sold',
			'not_available' => 'Not for sale',
		);

		return $labels[ get_post_meta( $product_id, '_stock_status', true ) ] ?? '';
	}

	/**
	 * The names of the artists linked to an artwork. Only published artists are named.
	 */
	private function artist_names( $product_id ) {
		$ids = array_filter( (array) get_post_meta( $product_id, 'artwork_artist', true ) );
		if ( ! $ids ) {
			$ids = array_filter( array( get_post_meta( $product_id, 'product_artist', true ) ) );
		}

		$names = array();
		foreach ( $ids as $id ) {
			if ( 'publish' === get_post_status( (int) $id ) ) {
				$names[] = wp_specialchars_decode( get_the_title( (int) $id ), ENT_QUOTES );
			}
		}

		return $names;
	}
}
