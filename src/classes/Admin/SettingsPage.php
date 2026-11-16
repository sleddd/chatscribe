<?php namespace ChatScribe\Admin;

/**
 * The ChatScribe settings: the page under Settings, and reading what was saved.
 * Everything lives in one option, as an array. Read a value anywhere in the
 * plugin with SettingsPage::get( 'frontend_chat_enabled' ) etc.
 */
class SettingsPage {

	const SLUG   = 'chatscribe';
	const GROUP  = 'chatscribe_settings_group';
	const HANDLE = 'chatscribe-admin-settings';
	const OPTION = 'chatscribe_settings';

	private string $hook                  = '';
	private static array $post_type_cache = array();

	/*
	------------------------------------------------------------------ *
	 * Reading settings
	 * ------------------------------------------------------------------ */

	public static function defaults(): array {
		return array(
			'frontend_chat_enabled' => true,
			'backend_chat_enabled'  => false,
			// Each *_access value: post_types (names) and
			// taxonomies keyed by post type, e.g. [ 'post' => [ 'category' ] ].
			'frontend_access'       => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array(),
			),
			'backend_access'        => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array(),
			),
			'frontend_statuses'     => array( 'publish' ),
			'backend_statuses'      => array( 'publish' ),
			// Everyone can use the frontend chat until roles are unticked.
			'frontend_roles'        => array_keys( self::roles( 'frontend' ) ),
			'backend_roles'         => array( 'administrator' ),
			// Data beyond posts the backend query can search. Off until ticked.
			'backend_data'          => array(),
			'frontend_data'         => array(),
			'summary_enabled'       => false,
			'summary_access'        => array(
				'post_types' => array( 'post' ),
				'taxonomies' => array(),
			),
			'summary_bullet_count'  => 5,
			'summary_bullet_words'  => 20,
			'generation_enabled'    => false,
			'generation_access'     => array(
				'post_types' => array( 'post' ),
				'taxonomies' => array(),
			),
		);
	}

	/**
	 * The post statuses the AI can be allowed to see, as status => label.
	 */
	public static function statuses(): array {
		$statuses = array();
		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $status ) {
			$object              = get_post_status_object( $status );
			$statuses[ $status ] = $object ? $object->label : ucfirst( $status );
		}
		return $statuses;
	}

	/**
	 * Data beyond posts that a query can be allowed to search, as key => label.
	 * WooCommerce orders are only offered while WooCommerce is active.
	 */
	public static function data_sources( string $context = 'backend' ): array {
		$woocommerce = \ChatScribe\Abilities\WooCommerce::active();

		if ( 'frontend' === $context ) {
			return $woocommerce ? array( 'own_orders' => __( 'Customers\' own WooCommerce orders (signed-in customers only)', 'chatscribe' ) ) : array();
		}

		$sources = array(
			'users'     => __( 'Users', 'chatscribe' ),
			'user_meta' => __( 'User meta (shown with users)', 'chatscribe' ),
			'options'   => __( 'Options (site settings)', 'chatscribe' ),
			'comments'  => __( 'Comments', 'chatscribe' ),
			'post_meta' => __( 'Post meta (shown with content)', 'chatscribe' ),
		);
		if ( $woocommerce ) {
			$sources['orders']    = __( 'WooCommerce orders', 'chatscribe' );
			$sources['customers'] = __( 'WooCommerce customers', 'chatscribe' );
		}
		return $sources;
	}

	/**
	 * The data sources ticked for a query ('frontend' or 'backend').
	 */
	public static function allowed_data( string $context = 'backend' ): array {
		return array_values( array_intersect( (array) self::get( $context . '_data' ), array_keys( self::data_sources( $context ) ) ) );
	}

	/**
	 * The user roles that can be allowed to make AI requests, as role => name.
	 * Frontend also offers "guest", for visitors who are not signed in.
	 */
	public static function roles( string $context ): array {
		$roles = 'frontend' === $context ? array( 'guest' => __( 'Visitors (not signed in)', 'chatscribe' ) ) : array();
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$roles[ $role ] = translate_user_role( $name );
		}
		return $roles;
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// Settings saved before the chat had separate frontend and backend switches.
		if ( isset( $saved['chat_enabled'] ) && ! isset( $saved['frontend_chat_enabled'] ) ) {
			$saved['frontend_chat_enabled'] = (bool) $saved['chat_enabled'];
		}
		unset( $saved['chat_enabled'] );

		// Settings saved when backend content had an "Any post type" option: keep the same
		// access by ticking every backend post type instead (saved as such on the next save).
		// Post types only exist once WordPress has run init.
		$backend_types = (array) ( $saved['backend_access']['post_types'] ?? array() );
		if ( in_array( 'any', $backend_types, true ) ) {
			$available               = did_action( 'init' ) ? self::available_post_types( 'backend' ) : array();
			$saved['backend_access'] = array(
				'post_types' => array_keys( $available ),
				'taxonomies' => array_filter(
					array_map(
						function ( $info ) {
							return array_keys( $info['taxonomies'] );
						},
						$available
					)
				),
			);
		}

		return array_merge( self::defaults(), $saved );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * The content a saved *_access setting allows, as [ post type => [ taxonomies ] ].
	 * Post types or taxonomies that no longer exist are left out.
	 *
	 * @param string $key     'frontend_access', 'backend_access', 'summary_access' or 'generation_access'.
	 * @param string $context 'frontend', 'backend', 'summary' or 'generation': which post types are on offer.
	 */
	public static function allowed_content( string $key, string $context ): array {
		$value     = (array) self::get( $key );
		$chosen    = (array) ( $value['post_types'] ?? array() );
		$chosen_tx = (array) ( $value['taxonomies'] ?? array() );
		$available = self::available_post_types( $context );

		$allowed = array();
		foreach ( $chosen as $type ) {
			if ( isset( $available[ $type ] ) ) {
				$allowed[ $type ] = array_values(
					array_intersect(
						(array) ( $chosen_tx[ $type ] ?? array() ),
						array_keys( $available[ $type ]['taxonomies'] )
					)
				);
			}
		}
		return $allowed;
	}

	/**
	 * The post statuses the AI can see in a context ('frontend' or 'backend').
	 */
	public static function allowed_statuses( string $context ): array {
		return array_values( array_intersect( (array) self::get( $context . '_statuses' ), array_keys( self::statuses() ) ) );
	}

	/**
	 * Whether the current user may make AI requests in a context ('frontend' or 'backend'),
	 * going by the ticked roles. Visitors who are not signed in count as "guest" (frontend only).
	 */
	public static function user_can_use( string $context ): bool {
		$roles = (array) self::get( $context . '_roles' );

		if ( ! is_user_logged_in() ) {
			return 'frontend' === $context && in_array( 'guest', $roles, true );
		}
		// On multisite a super admin may have no role on this site.
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return (bool) array_intersect( (array) wp_get_current_user()->roles, $roles );
	}

	/*
	------------------------------------------------------------------ *
	 * Admin page setup
	 * ------------------------------------------------------------------ */

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_page() {
		$this->hook = (string) add_options_page(
			__( 'ChatScribe Settings', 'chatscribe' ),
			__( 'ChatScribe', 'chatscribe' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}

		$css = 'src/assets/css/admin-settings.css';
		$js  = 'src/assets/js/admin-settings.js';
		$dir = plugin_dir_path( CHATSCRIBE_FILE );

		wp_enqueue_style( self::HANDLE, plugins_url( $css, CHATSCRIBE_FILE ), array(), filemtime( $dir . $css ) );
		wp_enqueue_script( self::HANDLE, plugins_url( $js, CHATSCRIBE_FILE ), array(), filemtime( $dir . $js ), true );
	}

	/*
	------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap chatscribe-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );

				$this->group(
					__( 'Chat', 'chatscribe' ),
					'',
					function () {
						$this->row_switch(
							'frontend_chat_enabled',
							__( 'Enable frontend chat', 'chatscribe' ),
							__( 'Show the chat on the site for visitors, and accept their chat requests.', 'chatscribe' )
						);
						$this->row_switch(
							'backend_chat_enabled',
							__( 'Enable backend chat', 'chatscribe' ),
							__( 'Show the chat in the WordPress admin for signed-in users.', 'chatscribe' )
						);
					}
				);

				$this->group(
					__( 'Frontend AI requests', 'chatscribe' ),
					__( 'Requests made by site visitors, such as the frontend chat.', 'chatscribe' ),
					function () {
						$this->row(
							__( 'Content the AI can use', 'chatscribe' ),
							function () {
								$this->description( __( 'Tick the post types the AI can use, then the categories and taxonomies it can use to find and filter them.', 'chatscribe' ) );
								$this->access_checklist( 'frontend_access', 'frontend' );
							}
						);
						$this->row(
							__( 'Status', 'chatscribe' ),
							function () {
								$this->description( __( 'Which post statuses the AI can see. Each person still only gets what they could already see themselves, such as their own drafts.', 'chatscribe' ) );
								$this->checklist( 'frontend_statuses', self::statuses() );
							}
						);
						$this->row(
							__( 'Permissions', 'chatscribe' ),
							function () {
								$this->description( __( 'Who can make frontend AI requests, such as using the chat. The AI never sees more than that person is allowed to.', 'chatscribe' ) );
								$this->checklist( 'frontend_roles', self::roles( 'frontend' ) );
							}
						);
						if ( self::data_sources( 'frontend' ) ) {
							$this->row(
								__( 'Other data the AI can search', 'chatscribe' ),
								function () {
									$this->description( __( 'A signed-in customer can ask about their own orders, and only ever gets their own. Visitors who are not signed in are asked to sign in.', 'chatscribe' ) );
									$this->checklist( 'frontend_data', self::data_sources( 'frontend' ) );
								}
							);
						}
					}
				);

				$this->group(
					__( 'Backend AI requests', 'chatscribe' ),
					__( 'Requests made from the WordPress admin by signed-in users.', 'chatscribe' ),
					function () {
						$this->row(
							__( 'Content the AI can use', 'chatscribe' ),
							function () {
								$this->access_checklist( 'backend_access', 'backend' );
							}
						);
						$this->row(
							__( 'Status', 'chatscribe' ),
							function () {
								$this->description( __( 'Which post statuses the AI can see.', 'chatscribe' ) );
								$this->checklist( 'backend_statuses', self::statuses() );
							}
						);
						$this->row(
							__( 'Permissions', 'chatscribe' ),
							function () {
								$this->description( __( 'Who can make backend AI requests. The AI never sees or does more than the signed-in user is allowed to.', 'chatscribe' ) );
								$this->checklist( 'backend_roles', self::roles( 'backend' ) );
							}
						);
						$this->row(
							__( 'Other data the AI can search', 'chatscribe' ),
							function () {
								$this->description( __( 'Each person only gets data they could see themselves: options need the Manage options permission, users need List users, and so on. Passwords, keys, tokens and similar values are always hidden. What the AI reads is sent to your AI provider.', 'chatscribe' ) );
								$this->checklist( 'backend_data', self::data_sources() );
							}
						);
					}
				);

				$this->group(
					__( 'Post summaries', 'chatscribe' ),
					'',
					function () {
						$this->row_switch(
							'summary_enabled',
							__( 'Enable AI summaries', 'chatscribe' ),
							__( 'Add a "Summarize" button to the editor of the post types below, which sums up the post as key points to copy into a block.', 'chatscribe' )
						);
						$this->row(
							__( 'Content that can be summarized', 'chatscribe' ),
							function () {
								$this->description( __( 'Posts, pages and other written content. WooCommerce and email content cannot be summarized.', 'chatscribe' ) );
								$this->access_checklist( 'summary_access', 'summary' );
							},
							'summary_enabled'
						);
						$this->row(
							__( 'Max number of bullet points', 'chatscribe' ),
							function () {
								$this->number_field( 'summary_bullet_count', 1, 20 );
							},
							'summary_enabled'
						);
						$this->row(
							__( 'Max words per bullet point', 'chatscribe' ),
							function () {
								$this->number_field( 'summary_bullet_words', 3, 100 );
							},
							'summary_enabled'
						);
					}
				);

				$this->group(
					__( 'Post generation', 'chatscribe' ),
					'',
					function () {
						$this->row_switch(
							'generation_enabled',
							__( 'Enable AI post generation', 'chatscribe' ),
							__( 'Add a "Generate" box to the editor of the post types below. The AI writes content from your instructions and what it finds on the subject in this site, and adds it to the post.', 'chatscribe' )
						);
						$this->row(
							__( 'Content that can be generated', 'chatscribe' ),
							function () {
								$this->description( __( 'Posts, pages and other written content, and WooCommerce products. Other WooCommerce and email content is never offered. The AI only searches what Backend AI requests allows.', 'chatscribe' ) );
								$this->access_checklist( 'generation_access', 'generation' );
							},
							'generation_enabled'
						);
					}
				);

				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * A field group: a heading, an optional intro and a table of rows.
	 * Groups are separated by a divider (see admin-settings.css).
	 */
	private function group( string $title, string $intro, callable $rows ) {
		echo '<section class="chatscribe-group">';
		echo '<h2 class="chatscribe-group__title">' . esc_html( $title ) . '</h2>';
		if ( $intro ) {
			echo '<p class="chatscribe-group__intro">' . esc_html( $intro ) . '</p>';
		}
		echo '<table class="form-table" role="presentation">';
		$rows();
		echo '</table></section>';
	}

	/**
	 * A form-table row. With $depends_on (a switch key) the row only shows while that switch is on.
	 */
	private function row( string $label, callable $render, string $depends_on = '' ) {
		$attrs = '';
		if ( $depends_on ) {
			$attrs = sprintf( ' data-chatscribe-show-when="%s"', esc_attr( $this->id( $depends_on ) ) );
			if ( ! self::get( $depends_on ) ) {
				$attrs .= ' hidden';
			}
		}

		echo '<tr' . $attrs . '><th scope="row">' . esc_html( $label ) . '</th><td>';
		$render();
		echo '</td></tr>';
	}

	private function row_switch( string $key, string $label, string $description ) {
		$id = $this->id( $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<span class="chatscribe-switch">
					<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $this->name( $key ) ); ?>" value="1"
						<?php checked( (bool) self::get( $key ) ); ?>>
					<span class="chatscribe-switch__track" aria-hidden="true"></span>
				</span>
				<?php $this->description( $description ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Post types, each with its taxonomies nested underneath (shown once the post type is ticked).
	 */
	private function access_checklist( string $key, string $context ) {
		$value      = (array) self::get( $key );
		$chosen     = (array) ( $value['post_types'] ?? array() );
		$chosen_tax = (array) ( $value['taxonomies'] ?? array() );
		$name       = $this->name( $key );

		echo '<fieldset class="chatscribe-checklist"><ul class="chatscribe-checklist__types">';

		foreach ( self::available_post_types( $context ) as $type => $info ) {
			$type_id = $this->id( $key . '-' . $type );
			$type_on = in_array( $type, $chosen, true );

			printf(
				'<li><label><input type="checkbox" id="%1$s" name="%2$s[post_types][]" value="%3$s" %4$s> %5$s</label>',
				esc_attr( $type_id ),
				esc_attr( $name ),
				esc_attr( $type ),
				checked( $type_on, true, false ),
				esc_html( $info['label'] )
			);

			if ( $info['taxonomies'] ) {
				printf(
					'<ul class="chatscribe-checklist__taxonomies" data-chatscribe-show-when="%s"%s>',
					esc_attr( $type_id ),
					$type_on ? '' : ' hidden'
				);
				foreach ( $info['taxonomies'] as $tax => $tax_label ) {
					printf(
						'<li><label><input type="checkbox" name="%1$s[taxonomies][%2$s][]" value="%3$s" %4$s> %5$s</label></li>',
						esc_attr( $name ),
						esc_attr( $type ),
						esc_attr( $tax ),
						checked( in_array( $tax, (array) ( $chosen_tax[ $type ] ?? array() ), true ), true, false ),
						esc_html( $tax_label )
					);
				}
				echo '</ul>';
			}

			echo '</li>';
		}

		echo '</ul></fieldset>';
	}

	/**
	 * A plain checklist of $options (value => label).
	 */
	private function checklist( string $key, array $options ) {
		$chosen = (array) self::get( $key );

		echo '<fieldset class="chatscribe-checklist"><ul class="chatscribe-checklist__types">';
		foreach ( $options as $value => $label ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label></li>',
				esc_attr( $this->name( $key ) ),
				esc_attr( $value ),
				checked( in_array( (string) $value, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</ul></fieldset>';
	}

	private function number_field( string $key, int $min, int $max ) {
		printf(
			'<input type="number" class="small-text" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="1">',
			esc_attr( $this->name( $key ) ),
			(int) self::get( $key ),
			$min,
			$max
		);
	}

	private function description( string $text ) {
		echo '<p class="description">' . esc_html( $text ) . '</p>';
	}

	private function name( string $key ): string {
		return self::OPTION . '[' . $key . ']';
	}

	private function id( string $key ): string {
		return 'chatscribe-' . str_replace( '_', '-', $key );
	}

	/**
	 * The post types offered in a checklist, each with its taxonomies:
	 * [ type => [ 'label' => ..., 'taxonomies' => [ taxonomy => label ] ] ].
	 * Frontend offers only public ones; backend offers anything with an admin screen.
	 * Summary and generation offer only written content (see is_written_content()).
	 */
	public static function available_post_types( string $context ): array {
		if ( isset( self::$post_type_cache[ $context ] ) ) {
			return self::$post_type_cache[ $context ];
		}

		if ( 'summary' === $context || 'generation' === $context ) {
			// Taxonomies play no part in summaries or generation, so none are offered.
			$with_products = 'generation' === $context;
			$list          = array();
			foreach ( self::available_post_types( 'backend' ) as $type => $info ) {
				if ( self::is_written_content( $type, $with_products ) ) {
					$list[ $type ] = array(
						'label'      => $info['label'],
						'taxonomies' => array(),
					);
				}
			}
			return self::$post_type_cache[ $context ] = $list;
		}

		$flag = 'frontend' === $context ? 'public' : 'show_ui';
		$list = array();

		foreach ( get_post_types( array( $flag => true ), 'objects' ) as $type ) {
			// Media, WordPress's own internal types (blocks, templates, navigation) and
			// WooCommerce orders (searched as "orders", not as content) are left out.
			if ( 'attachment' === $type->name || 0 === strpos( $type->name, 'wp_' )
				|| in_array( $type->name, \ChatScribe\Abilities\WooCommerce::ORDER_POST_TYPES, true ) ) {
				continue;
			}

			$taxonomies = array();
			foreach ( get_object_taxonomies( $type->name, 'objects' ) as $tax ) {
				// Product attributes (such as Color or Size) are shown on product pages,
				// so the frontend may use them even though they have no public archive.
				$attribute = 'product' === $type->name && function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $tax->name );
				if ( 'post_format' !== $tax->name && ( ! empty( $tax->$flag ) || $attribute ) ) {
					$taxonomies[ $tax->name ] = $tax->labels->name;
				}
			}

			$list[ $type->name ] = array(
				'label'      => $type->labels->name,
				'taxonomies' => $taxonomies,
			);
		}

		return self::$post_type_cache[ $context ] = $list;
	}

	/**
	 * Whether a post type is written content the AI can summarize or write: it has
	 * the content editor, and it is not WooCommerce or email content. WooCommerce
	 * products are allowed when $with_products is true (post generation).
	 */
	public static function is_written_content( string $post_type, bool $with_products = false ): bool {
		if ( 'product' === $post_type ) {
			return $with_products;
		}
		if ( ! post_type_supports( $post_type, 'editor' ) ) {
			return false;
		}
		// WooCommerce: orders, refunds, coupons, subscriptions, variations, webhooks, its emails and more.
		if ( preg_match( '/^(shop_|wc_|woo_|woocommerce_|product_)/', $post_type ) ) {
			return false;
		}
		// Emails and newsletters, such as MailPoet's.
		return ! preg_match( '/(e-?mail|newsletter)/i', $post_type );
	}

	/*
	------------------------------------------------------------------ *
	 * Saving
	 * ------------------------------------------------------------------ */

	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		return array(
			'frontend_chat_enabled' => ! empty( $input['frontend_chat_enabled'] ),
			'backend_chat_enabled'  => ! empty( $input['backend_chat_enabled'] ),
			'frontend_access'       => $this->sanitize_access( $input['frontend_access'] ?? array(), 'frontend' ),
			'backend_access'        => $this->sanitize_access( $input['backend_access'] ?? array(), 'backend' ),
			'frontend_statuses'     => $this->sanitize_choices( $input['frontend_statuses'] ?? array(), self::statuses() ),
			'backend_statuses'      => $this->sanitize_choices( $input['backend_statuses'] ?? array(), self::statuses() ),
			'frontend_roles'        => $this->sanitize_choices( $input['frontend_roles'] ?? array(), self::roles( 'frontend' ) ),
			'backend_roles'         => $this->sanitize_choices( $input['backend_roles'] ?? array(), self::roles( 'backend' ) ),
			'backend_data'          => $this->sanitize_data( $input['backend_data'] ?? array() ),
			'frontend_data'         => $this->sanitize_choices( $input['frontend_data'] ?? array(), self::data_sources( 'frontend' ) ),
			'summary_enabled'       => ! empty( $input['summary_enabled'] ),
			'summary_access'        => $this->sanitize_access( $input['summary_access'] ?? array(), 'summary' ),
			'summary_bullet_count'  => $this->sanitize_int( $input['summary_bullet_count'] ?? null, $defaults['summary_bullet_count'], 1, 20 ),
			'summary_bullet_words'  => $this->sanitize_int( $input['summary_bullet_words'] ?? null, $defaults['summary_bullet_words'], 3, 100 ),
			'generation_enabled'    => ! empty( $input['generation_enabled'] ),
			'generation_access'     => $this->sanitize_access( $input['generation_access'] ?? array(), 'generation' ),
		);
	}

	/**
	 * Keeps only post types offered in this context, and only taxonomies that
	 * belong to a ticked post type.
	 */
	private function sanitize_access( $value, string $context ): array {
		$value  = is_array( $value ) ? $value : array();
		$chosen = $this->strings( $value['post_types'] ?? array() );

		$available  = self::available_post_types( $context );
		$post_types = array_values( array_intersect( $chosen, array_keys( $available ) ) );
		$submitted  = is_array( $value['taxonomies'] ?? null ) ? $value['taxonomies'] : array();
		$taxonomies = array();

		foreach ( $post_types as $type ) {
			$picked = array_intersect( $this->strings( $submitted[ $type ] ?? array() ), array_keys( $available[ $type ]['taxonomies'] ) );
			if ( $picked ) {
				$taxonomies[ $type ] = array_values( $picked );
			}
		}

		return array(
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
		);
	}

	/**
	 * Keeps only values that are among $options (value => label).
	 */
	private function sanitize_choices( $value, array $options ): array {
		return array_values( array_intersect( $this->strings( $value ), array_map( 'strval', array_keys( $options ) ) ) );
	}

	/**
	 * User meta is only ever shown with users, so it needs Users ticked too.
	 */
	private function sanitize_data( $value ): array {
		$data = $this->sanitize_choices( $value, self::data_sources() );
		if ( ! in_array( 'users', $data, true ) ) {
			$data = array_values( array_diff( $data, array( 'user_meta' ) ) );
		}
		return $data;
	}

	private function sanitize_int( $value, int $default, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	private function strings( $value ): array {
		return array_values( array_filter( (array) $value, 'is_string' ) );
	}
}
