<?php
/*
Plugin Name: Pinterest Importer
Description: Import images & videos from a Pinterest account.
Version: 0.1.1
Author: G.Breant
Author URI: http://sandbox.pencil2d.org
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/



class PinterestImporter {

    /** Version ***************************************************************/

    /**
    * @public string plugin version
    */
    public $version = '0.1.0';

    /**
    * @public string plugin DB version
    */
    public $db_version = '100';

    /** Paths *****************************************************************/

    public $file = '';

    /**
    * @public string Basename of the plugin directory
    */
    public $basename = '';

    /**
    * @public string Absolute path to the plugin directory
    */
    public $plugin_dir = '';


    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new PinterestImporter;
                    self::$instance->setup_globals();
                    self::$instance->includes();
                    self::$instance->setup_actions();
            }
            return self::$instance;
    }
    
    public $import_allow_create_users;
    public $import_attachment_size_limit;

    /**
        * A dummy constructor to prevent bbPress from being loaded more than once.
        *
        * @since bbPress (r2464)
        * @see bbPress::instance()
        * @see bbpress();
        */
    private function __construct() { /* Do nothing here */ }

    function setup_globals() {

            /** Paths *************************************************************/
            $this->file       = __FILE__;
            $this->basename   = plugin_basename( $this->file );
            $this->plugin_dir = plugin_dir_path( $this->file );
            $this->plugin_url = plugin_dir_url ( $this->file );
            
            $this->import_allow_create_users = true;
            $this->import_attachment_size_limit = 0; //0 = unlimited

    }

    
    
    function includes(){
        
        if(!is_admin())return false;
        
        if (!class_exists('phpQuery'))
            require_once($this->plugin_dir . '_inc/lib/phpQuery/phpQuery.php');

        require $this->plugin_dir . '/pinterest-importer-templates.php';

        /*
        if ( ! class_exists( 'PinterestGridParser' ) ) {
            require $this->plugin_dir . '/pinterest-importer-parsers.php';
        }
         * 
         */
        
    }

    function setup_actions(){   
        
        if(!is_admin())return false;

        add_action( 'admin_init', array(&$this,'load_textdomain'));

        //upgrade
        add_action( 'plugins_loaded', array($this, 'upgrade'));

        
        add_action ( 'admin_menu', array($this, 'admin_menu'));
        
        

    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinterest-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    function upgrade(){
        global $wpdb;
        
        $meta_key = "_pinterest-importer-db_version";

        $current_version = get_option($meta_key);

        if ($current_version==$this->db_version) return false;

        if(!$current_version){
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
        }
        
        //update DB version
        update_option($meta_key, $this->db_version );

    }
    
    function admin_menu(){
        add_management_page( __('Pinterest Importer','pinterest-importer'), __('Pinterest Importer','pinterest-importer'), 'manage_options', 'pinterest-importer', array(&$this,'admin_page'));
    }

    function admin_page(){

        //if ( ! class_exists( 'WP_Importer' ) )
                //require ( ABSPATH . 'wp-admin/includes/class-wp-importer.php' );
        
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Importer','pinterest-importer');?></h2>
            <p><?php _e("Howdy! Wanna backup your Pinterest.com profile ?  Here's how to do.",'pinterest-importer');?></p>
            <h3><?php _e('Save and upload your Pinterest.com pins page','pinterest-importer');?></h3>
            <p>
                <ol>
                    <li><?php printf(__("Login to %1s and head to your pins page, which url should be %2s.", 'pinterest-importer' ),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>','<code>http://www.pinterest.com/YOURLOGIN/pins/</code>');?></li>
                    <li><?php _e('Scroll down the page and be sure all your collection is loaded.', 'pinterest-importer' );?></li>
                    <li><?php _e('Save this file to your computer as an HTML file, then upload it here.', 'pinterest-importer' );?></li>
                </ol>
            </p>
            <?php wp_import_upload_form( 'tools.php?page=pinterest-importer&amp;step=1' );?>
        </div>
        <?php
    }

}

/**
 * The main function responsible for returning the one Instance
 * to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 */

function pinterest_importer() {
	return PinterestImporter::instance();
}

pinterest_importer();