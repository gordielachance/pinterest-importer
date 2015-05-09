<?php
/*
Plugin Name: Pinterest Importer
Description: Import images & videos from a Pinterest account.
Version: 0.2.2
Author: G.Breant
Author URI: http://sandbox.pencil2d.org
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/

//ini_set('display_errors','Off'); //FIX for sessions (headers already sent...) - TO IMPROVE !

class PinIm {

    /** Version ***************************************************************/

    /**
    * @public string plugin version
    */
    public $version = '0.2.2';

    /**
    * @public string plugin DB version
    */
    public $db_version = '200';

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
    
    public $meta_name_options = 'pinim_options';

    var $Pinterest = null;
    var $user_boards_options = null;
    var $pinterest_url = 'https://www.pinterest.com';
    var $root_term_name = 'Pinterest.com';
    var $likes_term_name = 'Likes';


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

            $this->options_default = array(
                'boards_per_page'   => 20,
                'pins_per_page'     => 50,
                'category_root_id'  => null,
                'category_likes_id' => null,
            );
            $this->options = wp_parse_args(get_option( $this->meta_name_options), $this->options_default);

    }

    
    
    function includes(){
        
        require $this->plugin_dir . '/pinim-class-bridge.php';
        require $this->plugin_dir . '/pinim-functions.php';
        require $this->plugin_dir . '/pinim-templates.php';
        require $this->plugin_dir . '/pinim-pin-class.php';
        require $this->plugin_dir . '/pinim-board-class.php';
        require $this->plugin_dir . '/pinim-dummy-importer.php';
        require $this->plugin_dir . '/pinim-tool-page.php';

    }

    function setup_actions(){  
        
        //upgrade
        add_action( 'plugins_loaded', array($this, 'upgrade'));        
        add_action( 'add_meta_boxes', array($this, 'pinim_metabox'));
        
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_styles' ) );
        
        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_init', array( $this, 'register_session' ), 1);

        add_filter( 'pin_sanitize_raw_datas','pin_raw_data_remove_unecessary_keys');
        add_filter( 'pin_sanitize_raw_datas','pin_raw_data_date_to_timestamp');
        add_filter( 'pin_sanitize_before_insert','pin_raw_data_images_reduce');
        add_filter( 'pin_sanitize_before_insert','pin_raw_data_remove_self_pinner');

    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinim', false, $this->plugin_dir . '/languages' );
    }
    
    function upgrade(){
        global $wpdb;

        $current_version = get_option("_pinterest-importer-db_version");

        if ($current_version==$this->db_version) return false;
        
        if($current_version < '1.0.3') $current_version = null; //force re-install

        if(!$current_version){ //not installed
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
            /*
            if (!$root_term = term_exists($this->root_term_name,'category')){
                $root_term = wp_insert_term($this->root_term_name,'category');
            }
            if ( !is_wp_errort($root_term) && ($root_term_id = $root_term->term_id) ){
                $likes_term = wp_insert_term($this->likes_term_name,'category',array('parent' => $root_term_id));
            }
            */
            
        }




        //update DB version
        update_option("_pinterest-importer-db_version", $this->db_version );

    }

    function enqueue_scripts_styles($hook){
        $screen = get_current_screen();
        if ($screen->id != pinim_tool_page()->options_page) return;
        
        wp_enqueue_script('pinim', $this->plugin_url.'_inc/js/pinim.js', array('jquery'),$this->version);
        wp_enqueue_style('font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css',false,'4.3.0');
        wp_enqueue_style('pinim', $this->plugin_url . '_inc/css/pinim.css',false,$this->version);
        
        //localize vars
        $localize_vars=array();
        $localize_vars['ajaxurl']=admin_url( 'admin-ajax.php' );
        $localize_vars['update_warning']=__( 'Updating a pin will override it.  Continue ?',   'pinim' );
        wp_localize_script('pinim','pinimL10n', $localize_vars);
        
    }
    
    function get_options($key = null){
        $options = $this->options;
        if (!$key) return $options;
        if (!isset($options[$key])) return false;
        return $options[$key];
    }


    /**
     * Register a session so we can store the temporary.
     */
    function register_session(){
        if (!pinim_is_tool_page()) return;
        $this->start_session();
    }
    
    function start_session(){
        if( !session_id() ) session_start();
    }
    
    function save_session_data($key,$data){
        $_SESSION['pinim'][$key] = $data;
        return true;
    }
    
    function delete_session_data($key = null){
        if ($key){
            if (!isset($_SESSION['pinim'][$key])) return false;
            unset($_SESSION['pinim'][$key]);
        }
        unset($_SESSION['pinim']);
    }
    
    function get_session_data($key = null){
        
        $this->start_session();
        
        if (!isset($_SESSION['pinim'])) return null;
        
        $data = $_SESSION['pinim'];
        
        if ($key){
            if (!isset($data[$key])) return null;
            return $data[$key];
        }
        
        return $data;
    }

    
    /**
     * Display a metabox for posts having imported with this plugin
     * @return type
     */
    
    function pinim_metabox(){
        $metas = pinim_get_pin_meta();
        
        if (empty($metas)) return;
        
        add_meta_box(
                'pinterest_datas',
                __( 'Pinterest', 'pinim' ),
                array(&$this,'pinim_metabox_content'),
                'post'
        );
    }
    
    function pinim_metabox_content( $post ) {
        
        $metas = pinim_get_pin_meta();

        
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
                        
                        $content = null;
                        
                         switch ($meta_key){
                            case 'pin_id':
                                $meta_key = __('Pin URL','pinim');
                                
                                $links = array();
                                $links_str = null;
                                
                                foreach((array)$meta as $pin_id){
                                    $pin_url = pinim_get_pin_url($pin_id);
                                    $links[] = '<a href="'.$pin_url.'" target="_blank">'.$pin_url.'</a>';
                                }
                                
                                $content = implode('<br/>',$links);
                                

                            break;
                            case 'log':
                                //nothing for now
                            break;
                            default:
                                $content = $meta[0];
                            break;
                        }
                        
                            if ($content){
                        
                                ?>
                                <tr class="alternate">
                                    <td class="left">
                                        <?php echo $meta_key;?>
                                    </td>
                                    <td>
                                        <?php echo $content;?>
                                    </td>
                                </tr>
                                <?php
                            
                            }
                    }

                    ?>
                </tbody>
        </table>
        <?php
    }
    
    public function debug_log($message,$title = null) {

        if (WP_DEBUG_LOG !== true) return false;

        $prefix = '[pinim] ';
        if($title) $prefix.=$title.': ';

        if (is_array($message) || is_object($message)) {
            error_log($prefix.print_r($message, true));
        } else {
            error_log($prefix.$message);
        }
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