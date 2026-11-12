<?php namespace ChatScribe\AI;

class Client {

    private string $api_key;
    private string $system_instruction;
    private string $temperature;

    public function __construct( 
        string $api_key = '',
        string $system_instruction = '',
        string $temperature = ''
    ) {
        $this->api_key = $api_key;
        $this->system_instruction = $system_instruction;
        $this->temperature = (float) $temperature;
    }

    public function sendMessage( string $message) {
        if ( !function_exists('wp_ai_client_prompt') ) {
            return new WP_Error(
                'missing_ai_client',
                'WordPress 7.0+ AI Client is required.'
            );
        }

        $builder = \wp_ai_client_prompt( $message )
        ->using_system_instruction($this->system_instruction)
        ->using_temperature($this->temperature);

        // Only lock to a provider if one is selected. 
        if ( ! empty( $this->provider ) ) {
            $builder->using_provider( $this->provider );
        }

        // Verify at a valid key has been provided for the selected provider.
        if ( ! $builder->is_supported_for_text_generation() ) {
            return new WP_Error(
                'invalid_api_key',
                'Please add your API key in Settings > Connectors.'
            );
        }

        return $builder->generate_text();
    }
}