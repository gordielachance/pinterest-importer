<?php
/*
Plugin Name: Pinterest Importer
Description: Backup your Pinterest.com account by importing pins as Wordpress posts.  Supports boards, secret boards and likes.  Images are downloaded as Wordpress medias.
Version: 0.4.7
Author: G.Breant
Author URI: https://profiles.wordpress.org/grosbouff/#content-plugins
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/

class PinIm {

    /** Version ***************************************************************/

    /**
    * @public string plugin version
    */
    public $version = '0.4.8';

    /**
    * @public string plugin DB version
    */
    public $db_version = '208';

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
    
    public $plugin_url = '';
    public $donate_link = 'http://bit.ly/gbreant';
    
    public $options_default = array();
    public $options = array();

    static $pin_post_type = 'pin';
    static $meta_name_options = 'pinim_options';

    var $boards_followed_urls = array();
    var $user_boards_options = null;
    var $pinterest_url = 'https://www.pinterest.com';
    var $root_term_name = 'Pinterest.com';


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
                'boards_per_page'       => 10,
                'pins_per_page'         => 25,
                'category_root_id'      => null,
                'category_likes_id'     => null,
                'enable_update_pins'    => false,
                'boards_view_filter'    => 'simple',
                'boards_filter'         => 'all',
                'pins_filter'           => 'pending',
                'autocache'             => true,
                'enable_follow_boards'  => true,
                'default_status'        => 'publish',
                'auto_private'          => true
            );
            $this->options = wp_parse_args(get_option( self::$meta_name_options), $this->options_default);

    }

    function includes(){
        require $this->plugin_dir . 'pinim-class-bridge.php';      //communication with Pinterest
        require $this->plugin_dir . 'pinim-functions.php';
        require $this->plugin_dir . 'pinim-templates.php';
        require $this->plugin_dir . 'pinim-pin-class.php';
        //require $this->plugin_dir . 'pinim-ajax.php';
        require $this->plugin_dir . 'pinim-board-class.php';
        require $this->plugin_dir . 'pinim-dummy-importer.php';
        require $this->plugin_dir . 'pinim-tool-page.php';
    }

    function setup_actions(){  
        add_action( 'plugins_loaded', array($this, 'upgrade'));//upgrade
        add_filter( 'plugin_action_links_' . $this->basename, array($this, 'plugin_bottom_links')); //bottom links
        add_action( 'init', array($this, 'register_post_type') );
        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_styles' ) );
        add_action( 'add_meta_boxes', array($this, 'pinim_metabox'));
    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinim', false, $this->plugin_dir . '/languages' );
    }
    
    function upgrade(){
        global $wpdb;

        $current_version = get_option("_pinterest-importer-db_version");
        if ($current_version==$this->db_version) return false;
        
        if(!$current_version){ //not installed
            /*
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
             */
            /*
            if (!$root_term = term_exists($this->root_term_name,'category')){
                $root_term = wp_insert_term($this->root_term_name,'category');
            }
            */
            
        }else{
            
            //force destroy session
            pinim_tool_page()->destroy_session();
            
            if($current_version < '204'){
                $boards_settings = pinim_get_boards_options();
                foreach((array)$boards_settings as $key=>$board){
                    if (isset($board['id'])){
                        $boards_settings[$key]['board_id'] = $board['id'];
                        unset($boards_settings[$key]['id']);
                    }
                }
                update_user_meta( get_current_user_id(), 'pinim_boards_settings', $boards_settings);
            }
            
            
            if($current_version <= '207'){ //switch post type to 'pin'
                
                $querystr = $wpdb->prepare( "UPDATE $wpdb->posts table_posts LEFT JOIN $wpdb->postmeta table_metas ON table_posts.ID = table_metas.post_id SET table_posts.post_type = REPLACE(table_posts.post_type, %s, %s) WHERE table_metas.meta_key = '%s'", 'post', self::$pin_post_type,'_pinterest-pin_id' );
                
                $result = $wpdb->get_results ( $querystr );
                
            }
            
            if($current_version < '206'){
                $boards_settings = pinim_get_boards_options();
                foreach((array)$boards_settings as $key=>$board){
                    if (!isset($board['username']) || !isset($board['slug']) ) continue;
                    $boards_settings[$key]['url'] = Pinim_Bridge::get_short_url($board['username'],$board['slug']);
                }
                update_user_meta( get_current_user_id(), 'pinim_boards_settings', $boards_settings);
            }
        }




        //update DB version
        update_option("_pinterest-importer-db_version", $this->db_version );

    }

    function register_post_type() {

        $labels = array(
            'name'                  => _x( 'Pins', 'Post Type General Name', 'pinim' ),
            'singular_name'         => _x( 'Pin', 'Post Type Singular Name', 'pinim' ),
            'menu_name'             => __( 'Pins', 'pinim' ),
            'name_admin_bar'        => __( 'Pin', 'pinim' ),
            'archives'              => __( 'Item Archives', 'pinim' ),
            'attributes'            => __( 'Item Attributes', 'pinim' ),
            'parent_item_colon'     => __( 'Parent Item:', 'pinim' ),
            'all_items'             => __( 'All Items', 'pinim' ),
            'add_new_item'          => __( 'Add New Item', 'pinim' ),
            'add_new'               => __( 'Add New', 'pinim' ),
            'new_item'              => __( 'New Item', 'pinim' ),
            'edit_item'             => __( 'Edit Item', 'pinim' ),
            'update_item'           => __( 'Update Item', 'pinim' ),
            'view_item'             => __( 'View Item', 'pinim' ),
            'view_items'            => __( 'View Items', 'pinim' ),
            'search_items'          => __( 'Search Item', 'pinim' ),
            'not_found'             => __( 'Not found', 'pinim' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'pinim' ),
            'featured_image'        => __( 'Featured Image', 'pinim' ),
            'set_featured_image'    => __( 'Set featured image', 'pinim' ),
            'remove_featured_image' => __( 'Remove featured image', 'pinim' ),
            'use_featured_image'    => __( 'Use as featured image', 'pinim' ),
            'insert_into_item'      => __( 'Insert into item', 'pinim' ),
            'uploaded_to_this_item' => __( 'Uploaded to this item', 'pinim' ),
            'items_list'            => __( 'Items list', 'pinim' ),
            'items_list_navigation' => __( 'Items list navigation', 'pinim' ),
            'filter_items_list'     => __( 'Filter items list', 'pinim' ),
        );
        $args = array(
            'label'                 => __( 'Pin', 'pinim' ),
            'description'           => __( 'Posts imported from Pinterest', 'pinim' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'comments', 'custom-fields', 'post-formats', ),
            'taxonomies'            => array( 'category', 'post_tag' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,		
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
        );
        register_post_type( self::$pin_post_type, $args );

    }
    
    function plugin_bottom_links($links){
        
        $links[] = sprintf('<a target="_blank" href="%s">%s</a>',$this->donate_link,__('Donate','pinim'));//donate
        
        if (current_user_can('manage_options')) {
            $settings_page_url = add_query_arg(
                array(
                    'step'  => 'pinim-options'
                ),
                pinim_get_tool_page_url()
            );
            $links[] = sprintf('<a href="%s">%s</a>',esc_url($settings_page_url),__('Settings'));
        }
        
        return $links;
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
    
    public function get_default_option($name){
        if (!isset($this->options_default[$name])) return;
        return $this->options_default[$name];
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
