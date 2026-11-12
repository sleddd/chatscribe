<?php  namespace ChatScribe\Api;

use ChatScribe\AI\Client;

class Chat {

    const CHAT_REST_NAMESPACE = 'chatscribe/v1';
    const CHAT_REST_ROUTE = '/chat';
    const CHAT_REST_NAME = self::CHAT_REST_NAMESPACE . self::CHAT_REST_ROUTE;
    
    private Client $ai_client;

    public function __construct() {
        $this->ai_client = new Client();
        add_action( 'rest_api_init', [$this, 'register_routes'] );
    }

    public function register_routes() {
       register_rest_route( self::CHAT_REST_NAMESPACE, self::CHAT_REST_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle_chat_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_chat_request( $request ) {
        $data = $request->get_json_params();
        $message = isset($data['message']) ? sanitize_text_field($data['message']) : '';

        $response = $this->ai_client->sendMessage($message);
        //error_log( print_r( $response, true ) );
    
        $response = [
            'status' => 'success',
            'message' => $response,
        ];

        return rest_ensure_response( $response );
    }
}