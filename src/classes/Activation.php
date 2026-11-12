<?php namespace ChatScribe;

class Activation {
    public function __construct() {
       register_activation_hook( CHATSCRIBE_FILE, [$this, 'activate_plugin'] );
    }
    
    public function activate_plugin() {
        // Do activation steps here.
    }
}
