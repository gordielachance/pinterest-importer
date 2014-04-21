<?php
/*
Plugin Name: Pinterest Importer
Description: Import images & videos from a Pinterest account.
Version: 0.1.0
Author: G.Breant
Author URI: http://sandbox.pencil2d.org
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/



class PinIm {

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
                    self::$instance = new PinIm;
                    self::$instance->setup_globals();
                    self::$instance->includes();
                    self::$instance->setup_actions();
            }
            return self::$instance;
    }

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

            $this->import_attachment_size_limit = 0; //0 = unlimited

    }

    
    
    function includes(){

        // Load Importer API
        require_once ABSPATH . 'wp-admin/includes/import.php';
        
        require $this->plugin_dir . '/pinim-templates.php';

        if ( ! class_exists( 'WP_Importer' ) ) {
                $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                if ( file_exists( $class_wp_importer ) ) {
                    require $class_wp_importer;
                }
        }
        
        if (!class_exists('phpQuery'))
            require_once($this->plugin_dir . '_inc/lib/phpQuery/phpQuery.php');
        
        require $this->plugin_dir . '/pinim-class.php';
    }

    function setup_actions(){  
        
        //upgrade
        add_action( 'plugins_loaded', array($this, 'upgrade'));        
        add_action( 'add_meta_boxes', array($this, 'pinim_metabox'));

        if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) return;

        /** Display verbose errors */
        if (!defined('IMPORT_DEBUG')) define( 'IMPORT_DEBUG', false );

        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_init', array(&$this,'register_importer'));
        
        $root_category_id = pai_get_term_id('Pinterest.com','category'); // create or get the root category
        $this->root_category_id = apply_filters('pai_get_root_category_id',$root_category_id);

        add_filter('pai_get_post_content','pai_add_source_text',10,2);


    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinim', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
    
    function register_importer() {
            /**
            * WordPress Importer object for registering the import callback
            * @global WP_Import $wp_import
            */
            $GLOBALS['pinterest_wp_import'] = new Pinterest_Importer();
            register_importer( 'pinterest-pins', 'Pinterest', sprintf(__('Import pins from your %s account to Wordpress.', 'pinim'),'<a href="http://www.pinterest.com" target="_blank">Pinterest.com</a>'), array( $GLOBALS['pinterest_wp_import'], 'dispatch' ) );
    }

    function upgrade(){
        global $wpdb;

        $current_version = get_option("_pinterest-importer-db_version");

        if ($current_version==$this->db_version) return false;

        if(!$current_version){
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
        }

        //update DB version
        update_option("_pinterest-importer-db_version", $this->db_version );

    }
    
    /**
     * Display a metabox for posts having imported with this plugin
     * @return type
     */
    
    function pinim_metabox(){
        $metas = pai_get_pin_meta();
        
        if (empty($metas)) return;
        
        add_meta_box(
                'pinterest_datas',
                __( 'Pinterest', 'pinim' ),
                array(&$this,'pinim_metabox_content'),
                'post'
        );
    }
    
    function pinim_metabox_content( $post ) {
        
        $metas = pai_get_pin_meta();
        
        ?>
        <table id="pinterest-list-table">
                <thead>
                <tr>
                        <th class="left"><?php _ex( 'Name', 'meta name' ) ?></th>
                        <th><?php _e( 'Value' ) ?></th>
                </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $metas as $meta_key => $meta ) {
                        
                         switch ($meta_key){
                            case 'pinner':
                                $meta_key = __('Pinner URL','pinim');
                                $pinner_url = pai_get_user_url($meta);
                                $meta = '<a href="'.$pinner_url.'" target="_blank">'.$pinner_url.'</a>';
                            break;
                            case 'pin_id':
                                $meta_key = __('Pin URL','pinim');
                                $pin_url = pai_get_pin_url($meta);
                                $meta = '<a href="'.$pin_url.'" target="_blank">'.$pin_url.'</a>';
                            break;
                            case 'board_slug':
                                $meta_key = __('Board URL','pinim');
                                $board_url = pai_get_board_url($metas['pinner'],$meta);
                                $meta = '<a href="'.$board_url.'" target="_blank">'.$board_url.'</a>';
                            break;
                            case 'source':
                                $meta_key = __('Source URL','pinim');
                                $meta = '<a href="'.$meta.'" target="_blank">'.$meta.'</a>';
                            break;
                        }
                        
                        
                            ?>
                            <tr class="alternate">
                                <td class="left">
                                    <?php echo $meta_key;?>
                                </td>
                                <td>
                                    <?php echo $meta;?>
                                </td>
                            </tr>
                            <?php
                    }

                    ?>
                </tbody>
        </table>
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

function pinim() {
	return PinIm::instance();
}

if (is_admin()){
    pinim();
}

?>