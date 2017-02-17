<?php

/*
 * Register pinim in Tools > Import
 * But redirects it to the Tools > Pinterest Importer page
 * 
 */

class Pinim_Dummy_Importer {

    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Dummy_Importer;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    private function __construct() { /* Do nothing here */ }
    
    function init(){
        add_action( 'admin_init', array($this,'register_importer') );
        add_action( 'admin_init', array( $this, 'auto_redirect' ) );
    }
    
    function register_importer(){
        register_importer(
                'pinim', 
                __('Pinterest', 'pinim'), 
                __('Install the Pinterest importer to import pins from Pinterest and convert them into Wordpress posts.', 'pinim'), 
                array ($this, 'dispatch')
        );
    }
    function dispatch(){
        $url = pinim_get_menu_url(array('page'=>'boards'));
        printf(__('You should be redirected to <a href="%1$s">the Pinterest Importer page</a>.',"pinim"),$url);
        die();
    }
    
    function is_importer_page(){
        global $pagenow;
        if (($pagenow == 'admin.php') && isset($_REQUEST['import']) && ($_REQUEST['import']=='pinim')) return true;
        return false;
    }
    
    function auto_redirect(){
        if (!$this->is_importer_page()) return false;
        $url = pinim_get_menu_url(array('page'=>'account'));
        wp_redirect( $url );
        die();
        
    }
    
}

function pinim_dummy_importer() {
	return Pinim_Dummy_Importer::instance();
}

if (is_admin()){
    pinim_dummy_importer();
}


