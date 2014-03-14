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
    public $version = '0.1.1';

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
        
        // Load Importer API
        require_once ABSPATH . 'wp-admin/includes/import.php';
        
        require $this->plugin_dir . '/pinterest-importer-templates.php';

        if ( ! class_exists( 'WP_Importer' ) ) {
                $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                if ( file_exists( $class_wp_importer ) ) {
                    require $class_wp_importer;
                    require $this->plugin_dir . '/pinterest-importer-class.php';
                }
        }

        if ( ! class_exists( 'PinterestGridParser' ) ) {
            require $this->plugin_dir . '/pinterest-importer-parsers.php';
        }
        
        if (!class_exists('phpQuery'))
            require_once($this->plugin_dir . '_inc/lib/phpQuery/phpQuery.php');
        
    }

    function setup_actions(){   
        
        if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) return;

        /** Display verbose errors */
        if (!defined('IMPORT_DEBUG')) define( 'IMPORT_DEBUG', false );
        
        
        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_init', array(&$this,'register_importer'));


        //upgrade
        add_action( 'plugins_loaded', array($this, 'upgrade'));

    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinterest-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
    
    function register_importer() {
            /**
            * WordPress Importer object for registering the import callback
            * @global WP_Import $wp_import
            */
            $GLOBALS['pinterest_wp_import'] = new Pinterest_Importer();
            register_importer( 'pinterest-html', 'Pinterest', sprintf(__('Import <strong>images and videos</strong> from your %s account to Wordpress', 'pinterest-importer'),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>'), array( $GLOBALS['pinterest_wp_import'], 'dispatch' ) );
    }
    
    /**
    * Get term ID for an existing term (with its name),
    * Or create the term and return its ID
    * @param string $term_name
    * @param string $term_tax
    * @param type $term_args
    * @return boolean 
    */
    function get_term_id($term_name,$term_tax,$term_args=array()){

        $term_exists = term_exists($term_name,$term_tax,$term_args['parent']);
        $term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;

        //it exists, return ID
        if($term_id) return $term_id;

        //create it
        $t = wp_insert_term($term_name,$term_tax,$term_args);
        if (!is_wp_error( $t ) ){
            return $t['term_id'];
        }elseif ( defined('IMPORT_DEBUG') && IMPORT_DEBUG ){
                echo ': ' . $t->get_error_message();
        }

        return false;
    }

    function upgrade(){
        global $wpdb;

        $current_version = get_option("_lovit-importer-db_version");

        if ($current_version==$this->db_version) return false;

        if(!$current_version){
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
        }

        //update DB version
        update_option("_lovit-importer-db_version", $this->db_version );

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
?>