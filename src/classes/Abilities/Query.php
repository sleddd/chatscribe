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
                'label'               => __( 'Database Query', 'chatscribe' ),
                'description'         => __( 'Executes a database query using the WordPress database connection.', 'chatscribe' ),
                'category'            => 'site',
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'The SQL query to execute.',
                        ),
                    ),
                    'required'   => array( 'query' ),
                ),
                'execute_callback' => function ( array $input ) {
                    $query = $input['query'];
                    $result = $this->wpdb->get_results( $query, ARRAY_A );
                    return $result;
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
}