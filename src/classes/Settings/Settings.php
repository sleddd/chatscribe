<?php namespace ChatScribe\Settings;

/**
 * Reads the plugin's saved settings. Everything lives in one option, as an array.
 * Use Settings::get( 'chat_enabled' ) etc. anywhere in the plugin.
 */
class Settings {

	const OPTION = 'chatscribe_settings';

	public static function defaults(): array {
		return array(
			'chat_enabled'         => true,
			'frontend_access'      => array(
				'post_types' => array( 'post', 'page' ),
				'taxonomies' => array(),
			),
			'backend_access'       => array(
				'post_types' => array( 'any' ),
				'taxonomies' => array(),
			),
			'frontend_permissions' => array( 'read' ),
			'backend_permissions'  => array( 'read' ),
			'summary_enabled'      => false,
			'summary_access'       => array(
				'post_types' => array( 'post' ),
				'taxonomies' => array(),
			),
			'summary_bullet_count' => 5,
			'summary_bullet_words' => 20,
			'generation_enabled'   => false,
			'generation_access'    => array(
				'post_types' => array( 'post' ),
				'taxonomies' => array(),
			),
		);
	}

	/**
	 * The permission levels offered for AI requests, as key => label.
	 */
	public static function permissions(): array {
		return array(
			'read'         => __( 'Read published content', 'chatscribe' ),
			'read_private' => __( 'Read private and draft content', 'chatscribe' ),
			'create'       => __( 'Create drafts', 'chatscribe' ),
			'edit'         => __( 'Edit existing content', 'chatscribe' ),
			'publish'      => __( 'Publish content', 'chatscribe' ),
			'delete'       => __( 'Delete content', 'chatscribe' ),
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}
}
