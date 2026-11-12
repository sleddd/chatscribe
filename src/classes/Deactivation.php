<?php namespace ChatScribe;

class Deactivation {
    public function __construct() {
       register_deactivation_hook( CHATSCRIBE_FILE, [$this, 'deactivate_plugin'] );
    }
    
    public function deactivate_plugin() {
        // Do de-activation steps here.
    }
}
