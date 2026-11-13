<?php namespace ChatScribe\Shortcodes;

class Chat {

    const HANDLE = 'chatscribe-chat';

    public function __construct() {
        add_shortcode( 'chatscribe', [$this, 'render_shortcode'] );
        add_action( 'wp_enqueue_scripts', [$this, 'register_assets'] );
    }

    // Registers the chat CSS and JS. They only load when the shortcode renders.
    public function register_assets() {
        $css = 'src/assets/css/chat.css';
        $js  = 'src/assets/js/chat.js';
        $dir = plugin_dir_path( CHATSCRIBE_FILE );

        wp_register_style(
            self::HANDLE,
            plugins_url( $css, CHATSCRIBE_FILE ),
            [],
            filemtime( $dir . $css )
        );

        wp_register_script(
            self::HANDLE,
            plugins_url( $js, CHATSCRIBE_FILE ),
            [],
            filemtime( $dir . $js ),
            true
        );

        // Hands PHP values to the JS as window.ChatScribe.
        wp_localize_script( self::HANDLE, 'ChatScribe', [
            'restUrl' => esc_url_raw( rest_url( \ChatScribe\API\Chat::CHAT_REST_NAME ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'siteName'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'assistantName' => self::get_assistant_name(),
        ] );
    }

    // "{Site name} AI Assistant", so the chat carries each site's own identity.
    public static function get_assistant_name() {
        $site_name = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        return $site_name ? $site_name . ' AI Assistant' : 'AI Assistant';
    }

    public function render_shortcode( $atts ) {
        wp_enqueue_style( self::HANDLE );
        wp_enqueue_script( self::HANDLE );

        ob_start();
        ?>
        // it goes to gra 
        <div id="chatscribeChat">
            <div class="chatscribe-header">
                <p class="chatscribe-title"><?php echo esc_html( self::get_assistant_name() ); ?></p>
                <div class="chatscribe-actions">
                    <button type="button" id="chatscribeClear" aria-label="Clear conversation" title="Clear conversation">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>
                        </svg>
                    </button>
                    <button type="button" id="chatscribeClose" aria-label="Close chat" title="Close chat">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div id="chatscribeMessages">
                <p id="chatscribeGreeting" class="chatscribe-message chatscribe-message--bot">Hi there! How can I help?</p>
            </div>

            <div class="chatscribe-input-row">
                <input type="text" id="chatscribeInput" placeholder="Type your message..." autocomplete="off">
                <button type="button" id="chatscribeSend" aria-label="Send message">
                    <svg width="40" height="40" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="11" fill="currentColor"/>
                        <path d="M10 7.5v9l6.5-4.5z" fill="#ffffff"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
