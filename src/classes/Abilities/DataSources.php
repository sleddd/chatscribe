<?php namespace ChatScribe\Abilities;

use ChatScribe\Admin\SettingsPage;

/**
 * Data beyond posts for the query abilities.
 *   Backend: users (with user meta), options, comments, WooCommerce orders and customers,
 *            plus post meta added to content results.
 *   Frontend: a signed-in customer's own WooCommerce orders, nothing else.
 *
 * Each is only available when ticked under "Other data the AI can search" for that
 * context, and only as far as the signed-in user could see it themselves.
 * Values that look like secrets (passwords, keys, tokens...) are never returned.
 */
class DataSources {

	// Names that look like they hold secrets. Their values are always hidden.
	const SECRET_NAME = '/(pass(word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|auth(?!or)|salt|nonce|session|credential|licen[cs]e|signature|hash|otp|2fa|webhook|cookie|_key$|^key$)/i';

	// Values that look like secrets whatever they are called: well-known key formats,
	// JWTs, and long random-looking strings mixing upper case, lower case and digits.
	const SECRET_VALUE = '/^(?:(?:sk|pk|rk)_(?:live|test)_|sk-[A-Za-z0-9]|gh[pousr]_|xox[abprs]-|AKIA[0-9A-Z]{16}|eyJ[\w-]+\.eyJ)|^(?=[^\s]*[a-z])(?=[^\s]*[A-Z])(?=[^\s]*\d)[A-Za-z0-9+\/=_-]{32,}$/';

	const HIDDEN          = '[hidden]';
	const VALUE_MAX_CHARS = 300;
	const META_MAX_KEYS   = 40;
	const MAX_CHECKED     = 1000;

	// Meta WordPress keeps for its own bookkeeping; never useful to the assistant.
	const NOISY_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', 'dismissed_wp_pointers' );

	/**
	 * Ticked sources that can be searched on their own with the "source" input
	 * (post and user meta only come along with content and users).
	 */
	public static function searchable( string $context = 'backend' ): array {
		$ticked = SettingsPage::allowed_data( $context );

		if ( 'frontend' === $context ) {
			// The frontend setting is "own_orders"; the AI searches it as "orders".
			return in_array( 'own_orders', $ticked, true ) && WooCommerce::active() ? array( 'orders' ) : array();
		}

		$sources = array_intersect( array( 'users', 'options', 'comments', 'orders', 'customers' ), $ticked );
		if ( ! WooCommerce::active() ) {
			$sources = array_diff( $sources, array( 'orders', 'customers' ) );
		}
		return array_values( $sources );
	}

	public static function is_enabled( string $source ): bool {
		return in_array( $source, SettingsPage::allowed_data( 'backend' ), true );
	}

	/**
	 * Whether the current user could see this kind of data themselves.
	 * Individual users, comments and posts are checked again one by one.
	 */
	public static function user_can( string $source, string $context = 'backend' ): bool {
		if ( 'frontend' === $context ) {
			// Customers look up only their own orders, so they just need to be signed in.
			return 'orders' === $source && is_user_logged_in();
		}
		switch ( $source ) {
			case 'orders':
				return current_user_can( 'edit_shop_orders' );
			case 'customers':
				return current_user_can( 'manage_woocommerce' );
			case 'users':
				return current_user_can( 'list_users' );
			case 'user_meta':
				return current_user_can( 'edit_users' );
			case 'options':
				return current_user_can( 'manage_options' );
			case 'comments':
				return current_user_can( 'edit_posts' );
			case 'post_meta':
				return current_user_can( 'edit_posts' );
		}
		return false;
	}

	/**
	 * Searches users, options, comments or orders. Checks were made by the caller:
	 * the source is ticked and user_can( $source, $context ) is true.
	 *
	 * @param array  $input      The ability input.
	 * @param array  $post_types Post types the query may search (for comments).
	 * @param string $context    'frontend' or 'backend'.
	 */
	public static function search( string $source, array $input, array $post_types, string $context = 'backend' ) {
		if ( 'orders' === $source ) {
			return WooCommerce::orders( $input, $context );
		}
		if ( 'customers' === $source && 'backend' === $context ) {
			return WooCommerce::customers( $input );
		}
		if ( 'frontend' === $context ) {
			return new \WP_Error( 'unknown_source', 'That kind of data cannot be searched.' );
		}

		$search = trim( (string) ( $input['search'] ?? '' ) );
		$count  = ! empty( $input['count_only'] );
		$limit  = min( 50, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		switch ( $source ) {
			case 'users':
				return self::users( $input, $search, $count, $limit, $offset );
			case 'options':
				return self::options( $search, $count, $limit, $offset );
			case 'comments':
				return self::comments( $search, $count, $limit, $offset, $post_types );
		}
		return new \WP_Error( 'unknown_source', 'That kind of data cannot be searched.' );
	}

	/*
	------------------------------------------------------------------ *
	 * Users
	 * ------------------------------------------------------------------ */

	private static function users( array $input, string $search, bool $count, int $limit, int $offset ): array {
		$by_name = 'title' === ( $input['orderby'] ?? '' );
		$args    = array(
			'number'      => $count ? 1 : $limit,
			'offset'      => $count ? 0 : $offset,
			'count_total' => true,
			'orderby'     => $by_name ? 'display_name' : 'registered',
			'order'       => $by_name ? 'ASC' : 'DESC',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name', 'user_url' );
		}
		$role = (string) ( $input['role'] ?? '' );
		if ( '' !== $role && wp_roles()->is_role( $role ) ) {
			$args['role'] = $role;
		}

		$query  = new \WP_User_Query( $args );
		$result = array( 'total' => (int) $query->get_total() );
		if ( $count ) {
			return $result;
		}

		$role_names = wp_roles()->get_names();
		$with_meta  = self::is_enabled( 'user_meta' ) && self::user_can( 'user_meta' );

		$result['items'] = array();
		foreach ( $query->get_results() as $user ) {
			$item = array(
				'name'       => $user->display_name,
				'username'   => $user->user_login,
				'email'      => $user->user_email,
				'roles'      => array_values(
					array_map(
						function ( $role ) use ( $role_names ) {
							return isset( $role_names[ $role ] ) ? translate_user_role( $role_names[ $role ] ) : $role;
						},
						(array) $user->roles
					)
				),
				'registered' => mysql2date( 'F j, Y', $user->user_registered ),
			);
			if ( current_user_can( 'edit_user', $user->ID ) ) {
				$item['edit_link'] = get_edit_user_link( $user->ID );
				if ( $with_meta ) {
					$item['meta'] = self::meta( get_user_meta( $user->ID ) );
				}
			}
			$result['items'][] = $item;
		}

		return $result;
	}

	/*
	------------------------------------------------------------------ *
	 * Options
	 * ------------------------------------------------------------------ */

	private static function options( string $search, bool $count, int $limit, int $offset ): array {
		global $wpdb;

		// Transients are temporary caches, not settings.
		$where = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND ( option_name LIKE %s OR option_value LIKE %s )', $like, $like );
		}

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from constants and prepare().
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_name ASC LIMIT " . self::MAX_CHECKED );

		// A secret option is only found by its name, never by what it holds,
		// so its value cannot be guessed by searching for it bit by bit.
		$rows = array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $search ) {
					return ! self::is_secret_name( $row->option_name ) || '' === $search || false !== stripos( $row->option_name, $search );
				}
			)
		);

		$result = array( 'total' => count( $rows ) );
		if ( $count ) {
			return $result;
		}

		$result['items'] = array();
		foreach ( array_slice( $rows, $offset, $limit ) as $row ) {
			$result['items'][] = array(
				'name'  => $row->option_name,
				'value' => self::value( $row->option_name, $row->option_value ),
			);
		}
		return $result;
	}

	/*
	------------------------------------------------------------------ *
	 * Comments
	 * ------------------------------------------------------------------ */

	private static function comments( string $search, bool $count, int $limit, int $offset, array $post_types ): array {
		if ( ! $post_types ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}
		$moderator = current_user_can( 'moderate_comments' );

		$comments = get_comments(
			array(
				'post_type' => $post_types,
				'status'    => $moderator ? array( 'approve', 'hold' ) : 'approve',
				'search'    => $search,
				'number'    => self::MAX_CHECKED,
				'orderby'   => 'comment_date_gmt',
				'order'     => 'DESC',
			)
		);

		// Only comments on posts this user could see themselves.
		$comments = array_values(
			array_filter(
				$comments,
				function ( $comment ) {
					return Query::user_can_see( (int) $comment->comment_post_ID );
				}
			)
		);

		$result = array( 'total' => count( $comments ) );
		if ( $count ) {
			return $result;
		}

		$result['items'] = array();
		foreach ( array_slice( $comments, $offset, $limit ) as $comment ) {
			$item = array(
				'author' => $comment->comment_author,
				'date'   => mysql2date( 'F j, Y', $comment->comment_date ),
				'status' => '1' === (string) $comment->comment_approved ? 'approved' : 'pending',
				'text'   => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 100 ),
				'on'     => wp_specialchars_decode( get_the_title( $comment->comment_post_ID ), ENT_QUOTES ),
				'link'   => get_comment_link( $comment ),
			);
			if ( $moderator ) {
				$item['author_email'] = $comment->comment_author_email;
			}
			if ( current_user_can( 'edit_comment', $comment->comment_ID ) ) {
				$item['edit_link'] = admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID );
			}
			$result['items'][] = $item;
		}
		return $result;
	}

	/*
	------------------------------------------------------------------ *
	 * Meta and values
	 * ------------------------------------------------------------------ */

	/**
	 * A post's meta, for a content result. The caller checks the user can edit the post.
	 */
	public static function post_meta( int $post_id ): array {
		return self::meta( get_post_meta( $post_id ) );
	}

	/**
	 * Meta as name => value, without bookkeeping keys, with secrets hidden.
	 *
	 * @param array $all As returned by get_post_meta( $id ) / get_user_meta( $id ): name => [ values ].
	 */
	private static function meta( $all ): array {
		$meta = array();
		foreach ( (array) $all as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, self::NOISY_META, true ) || 0 === strpos( $key, '_oembed_' ) ) {
				continue;
			}
			$values       = (array) $values;
			$meta[ $key ] = 1 === count( $values )
				? self::value( $key, reset( $values ) )
				: array_map(
					function ( $value ) use ( $key ) {
						return self::value( $key, $value );
					},
					array_values( $values )
				);

			if ( count( $meta ) >= self::META_MAX_KEYS ) {
				break;
			}
		}
		return $meta;
	}

	/**
	 * A stored value made safe to hand to the AI: secrets hidden (also inside saved
	 * arrays), saved arrays shown as JSON, and long values cut short.
	 */
	private static function value( string $name, $raw ) {
		if ( self::is_secret_name( $name ) ) {
			return self::HIDDEN;
		}

		if ( is_string( $raw ) && is_serialized( $raw ) ) {
			// No objects are rebuilt from stored data.
            $raw = @unserialize( trim( $raw ), [ 'allowed_classes' => false ] ); // phpcs:ignore
		}
		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = wp_json_encode( self::redact( (array) $raw ) );
		}

		$text = (string) $raw;
		if ( preg_match( self::SECRET_VALUE, trim( $text ) ) ) {
			return self::HIDDEN;
		}
		return mb_strlen( $text ) > self::VALUE_MAX_CHARS ? mb_substr( $text, 0, self::VALUE_MAX_CHARS ) . '…' : $text;
	}

	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_secret_name( $key ) ) {
				$data[ $key ] = self::HIDDEN;
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$data[ $key ] = self::redact( (array) $value );
			} elseif ( is_string( $value ) && preg_match( self::SECRET_VALUE, trim( $value ) ) ) {
				$data[ $key ] = self::HIDDEN;
			}
		}
		return $data;
	}

	private static function is_secret_name( string $name ): bool {
		return (bool) preg_match( self::SECRET_NAME, $name );
	}
}
