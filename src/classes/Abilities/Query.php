<?php namespace ChatScribe\Abilities;

class Query {
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action( 'wp_abilities_api_init', [$this, 'register_abilty'] );
    }

    public function register_abilty() {
        wp_register_ability(
            'chatscribe/db-query',
            array(
                'label'               => __( 'Content Search', 'chatscribe' ),
                'description'         => __( 'Searches or lists this site\'s published content (artworks, artists, exhibits, posts and pages). Returns the total number of matches and, for each match, its title, link, date and text. Searching for an artist\'s name also returns the artworks by that artist. Leave "search" empty to list everything of a post type.', 'chatscribe' ),
                'category'            => 'site',
                'input_schema'        => array(
                    'type'                  => 'object',
                    'additionalProperties'  => false,
                    'properties'            => [
                        'post_type' => [ 'type' => 'string', 'enum' => [ 'artist', 'artwork','product','exhibit','post', 'page' ], 'description' => 'The kind of content. "artwork" and "product" are the same thing. Leave out to search everything.' ],
                        'search'    => [ 'type' => 'string', 'maxLength' => 100, 'description' => 'Key words to match, such as a title, topic or artist name. Leave out to list everything of the post type.' ],
                        'category'  => [ 'type' => 'string', 'maxLength' => 100, 'description' => 'A blog category slug. Only for post_type "post".' ],
                        'orderby'   => [ 'type' => 'string', 'enum' => [ 'date', 'title' ] ],
                        'limit'     => [ 'type' => 'integer','minimum' => 1, 'maximum' => 50 ],
                        'offset'    => [ 'type' => 'integer','minimum' => 0, 'description' => 'How many matches to skip, to get the next part of a list.' ],
                        'count_only'=> [ 'type' => 'boolean' ],
                    ],
                ),
                'execute_callback' => function ( $input = [] ) {
                    return $this->run_query( (array) $input );
                },
                'permission_callback' => function () {
				    return current_user_can( 'read' );
			    },
			    'meta' => array(
				    'mcp' => array(
					    'public' => true,
				    ),
			    ),
            )
        );
    }

    /**
     * Runs the search and returns only what the assistant needs: how many
     * matches there are, and each match's title, link, date and text.
     */
    private function run_query( array $input ) {
        // Artworks are stored as WooCommerce products.
        $types     = [ 'artwork' => 'product' ];
        $post_type = $input['post_type'] ?? '';
        $post_type = $types[ $post_type ] ?? $post_type;
        $search    = trim( (string) ( $input['search'] ?? '' ) );
        $orderby   = $input['orderby'] ?? ( '' === $search ? 'date' : 'relevance' );
        $count     = ! empty( $input['count_only'] );

        $args = [
            'post_type'           => $post_type ?: [ 'page', 'post', 'product', 'artist', 'exhibit' ],
            'post_status'         => 'publish',
            'has_password'        => false,
            'ignore_sticky_posts' => true,
            'orderby'             => $orderby,
            'order'               => 'title' === $orderby ? 'ASC' : 'DESC',
            'posts_per_page'      => $count ? 1 : min( 50, max( 1, (int) ( $input['limit'] ?? 5 ) ) ),
            'fields'              => $count ? 'ids' : '',
        ];
        if ( ! $count && ! empty( $input['offset'] ) ) {
            $args['offset'] = max( 0, (int) $input['offset'] );
        }
        if ( '' !== $search ) {
            $args['s'] = $search;
        }
        if ( ! empty( $input['category'] ) ) {
            $args['category_name'] = $input['category'];
        }

        // One search covers artworks by their artist too: when the words match an artist's
        // name, that artist's artworks are added to the ordinary matches.
        if ( '' !== $search && in_array( $post_type, [ '', 'product' ], true ) ) {
            $by_artist = $this->artworks_by_artist_named( $search );

            if ( $by_artist ) {
                $matches = get_posts( [ 'fields' => 'ids', 'posts_per_page' => 200, 'offset' => 0 ] + $args );
                $args['post__in'] = array_values( array_unique( array_merge( $matches, $by_artist ) ) );
                unset( $args['s'] );
                if ( 'relevance' === $orderby ) {
                    // Ordinary matches first, then the artist's artworks.
                    $args['orderby'] = 'post__in';
                }
            }
        }

        $query  = new \WP_Query( $args );
        $result = [ 'total' => (int) $query->found_posts ];

        // Where a visitor can browse the whole list, such as the shop page for artworks.
        if ( $post_type && get_post_type_archive_link( $post_type ) ) {
            $result['browse_all_link'] = get_post_type_archive_link( $post_type );
        }
        if ( $count ) {
            return $result;
        }

        $result['items'] = [];
        foreach ( $query->posts as $post ) {
            $item = [
                'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
                'link'  => get_permalink( $post ),
                'type'  => 'product' === $post->post_type ? 'artwork' : $post->post_type,
                'date'  => get_the_date( 'F j, Y', $post ),
            ];

            if ( 'product' === $post->post_type ) {
                $artists = $this->artist_names( $post->ID );
                if ( $artists ) {
                    $item['artists'] = $artists;
                }
                $availability = $this->availability( $post->ID );
                if ( $availability ) {
                    $item['availability'] = $availability;
                }
            }

            $text = wp_strip_all_tags( strip_shortcodes( $post->post_excerpt . "\n" . $post->post_content ), true );
            if ( '' !== $text ) {
                $item['content'] = wp_trim_words( $text, 300 );
            }

            $result['items'][] = $item;
        }

        return $result;
    }

    /**
     * The IDs of the published artworks by any artist whose name matches the search.
     */
    private function artworks_by_artist_named( $name ) {
        $artist_ids = get_posts( [
            'post_type'      => 'artist',
            'post_status'    => 'publish',
            's'              => $name,
            'search_columns' => [ 'post_title' ],
            'posts_per_page' => 5,
            'fields'         => 'ids',
        ] );

        if ( ! $artist_ids ) {
            return [];
        }

        return get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'has_password'   => false,
            'meta_query'     => $this->artist_meta_query( $artist_ids ),
            'posts_per_page' => 500,
            'fields'         => 'ids',
        ] );
    }

    /**
     * Matches artworks by any of the given artists. An artwork keeps its artists as a
     * saved list of IDs, so each ID is matched in quotes (which stops 12 matching 120).
     * Older artworks keep a single ID under product_artist.
     */
    private function artist_meta_query( array $artist_ids ) {
        $query = [ 'relation' => 'OR' ];

        foreach ( $artist_ids as $id ) {
            $query[] = [ 'key' => 'artwork_artist', 'value' => '"' . (int) $id . '"', 'compare' => 'LIKE' ];
            $query[] = [ 'key' => 'product_artist', 'value' => (int) $id ];
        }

        return $query;
    }

    /**
     * Whether an artwork can be bought, in words the assistant can pass on.
     */
    private function availability( $product_id ) {
        $labels = [
            'instock'       => 'Available',
            'artsy'         => 'Available',
            'inquiry_only'  => 'Available on inquiry',
            'sold'          => 'Sold',
            'outofstock'    => 'Sold',
            'not_available' => 'Not for sale',
        ];

        return $labels[ get_post_meta( $product_id, '_stock_status', true ) ] ?? '';
    }

    /**
     * The names of the artists linked to an artwork.
     */
    private function artist_names( $product_id ) {
        $ids = array_filter( (array) get_post_meta( $product_id, 'artwork_artist', true ) );
        if ( ! $ids ) {
            $ids = array_filter( [ get_post_meta( $product_id, 'product_artist', true ) ] );
        }

        $names = [];
        foreach ( $ids as $id ) {
            if ( 'publish' === get_post_status( (int) $id ) ) {
                $names[] = wp_specialchars_decode( get_the_title( (int) $id ), ENT_QUOTES );
            }
        }

        return $names;
    }
}
