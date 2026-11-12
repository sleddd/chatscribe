<?php namespace ChatScribe\AI;

use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;

class Client {
    private string $api_key;
    private string $system_instruction;
    private string $temperature;
    private array  $abilities;
    private object $resolver;
    private array  $messages;

    public function __construct( 
        string $api_key             = '',
        string $system_instruction  = '',
        string $temperature         = '',
        array  $abilities           = [] 
    ) {
        $this->api_key              = $api_key;
        $this->system_instruction   = $system_instruction;
        $this->temperature          = (float) $temperature;
        $this->abilities            = $abilities;
        $this->resolver             = new \WP_AI_Client_Ability_Function_Resolver(...$this->abilities);
        $this->messages             = [];
    }

    public function sendMessage( string $message ) {
        // Add your messages to messages.
        $this->messages = [ new UserMessage( [ new MessagePart( $message ) ] ) ];
        error_log( 'AI Request: ' . print_r( $this->messages, true ) );

        if ( !function_exists('wp_ai_client_prompt') ) {
            return new WP_Error(
                'missing_ai_client',
                'WordPress 7.0+ AI Client is required.'
            );
        }

        for( $i = 0; $i < 5; $i++ ) {

            // Build the AI request.
            $builder = \wp_ai_client_prompt( $this->messages )
            ->using_system_instruction($this->system_instruction)
            ->using_temperature($this->temperature)
            ->using_abilities( ...$this->abilities );


            // Only lock to a provider if one is selected. 
            if ( ! empty( $this->provider ) ) {
                $builder->using_provider( $this->provider );
            }

            // Verify valid api key has been provided.
            if ( ! $builder->is_supported_for_text_generation() ) {
                return new \WP_Error(
                    'invalid_api_key',
                    'Please add your API key in Settings > Connectors.'
                );
            }

            // Generate the text response from the AI model.
            $response = $builder->generate_text_result();
            error_log( 'AI Response: ' . print_r( $response, true ) );

            // Return if it is an error, otherwise continue to check if the model has requested a tool call.
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            // Convert the initial response to a message object for further processing.
            $reply = $response->toMessage();

            // Check if a tool call is requested, if not return the text response. 
            if ( ! $this->resolver->has_ability_calls( $reply ) ) {
                return $response->toText();
            }

            // Model asked for the abilty, so run the ability, add both turns, go again.
            $this->messages[] = $reply;
            $this->messages[] = $this->resolver->execute_abilities( $reply );
        }       

        // If the model has requested too many tool calls, return an error.
        return  new \WP_Error(
            'too_many_tool_calls',
            'The assistant got stuck. Please try again.'
        );
    }    
}