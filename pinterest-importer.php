<?php
/*
Plugin Name: Pinterest Importer
Description: Backup your Pinterest.com account by importing pins in Wordpress.  Supports boards, secret boards, and downloads HD images.
Version: 0.6.0
Author: G.Breant
Author URI: https://profiles.wordpress.org/grosbouff/#content-plugins
Plugin URI: http://wordpress.org/extend/plugins/pinterest-importer
License: GPL2
*/

require plugin_dir_path( __FILE__ ) . '_inc/php/vendor/autoload.php';
use seregazhuk\PinterestBot\Factories\PinterestBot;

class PinIm {

    /** Version ***************************************************************/

    /**
    * @public string plugin version
    */
    public $version = '0.6.0';

    /**
    * @public string plugin DB version
    */
    public $db_version = '209';

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

    public $pin_post_type = 'pin';
    public $meta_name_options = 'pinim_options';
    public $meta_name_user_boards_options = 'pinim_boards_settings';

    var $user_boards_options = null;
    var $pinterest_url = 'https://www.pinterest.com';
    var $root_term_name = 'Pinterest.com';
    
    var $session = null;
    
    var $page_account = null;
    var $page_boards = null;
    var $page_pending_imports = null;
    var $page_settings = null;
    
    private $processed_pins_ids = null;
    private $uploads_dir = null;

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
                'pagination_limit'      => 50,
                'category_root_id'      => null,
                'boards_layout'         => 'advanced',
                'boards_filter'         => 'all',
                'pins_filter'           => 'pending',
                'enable_followed'       => false,
                'default_status'        => 'publish',
                'can_autoprivate'       => 'on'
            );
        
            $this->options = wp_parse_args(get_option( $this->meta_name_options), $this->options_default);

    }

    function includes(){
        require $this->plugin_dir . 'pinim-functions.php';
        require $this->plugin_dir . 'pinim-templates.php';
        
        

        if ( is_admin() ){
            
            //communication with Pinterest
            $this->bot = PinterestBot::create();
            
            require $this->plugin_dir . 'pinim-classes.php';
            //require $this->plugin_dir . 'pinim-ajax.php';

            require $this->plugin_dir . 'pinim-dummy-importer.php';
            require $this->plugin_dir . 'pinim-account.php';
            require $this->plugin_dir . 'pinim-boards.php';
            require $this->plugin_dir . 'pinim-pending-imports.php';
            require $this->plugin_dir . 'pinim-settings.php';

        }

    }
    
    public function get_processed_pin_ids(){
        if (!$this->processed_pins_ids){
            $this->processed_pins_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
        }
        return $this->processed_pins_ids;
    }

    function setup_actions(){  
        add_action( 'plugins_loaded', array($this, 'upgrade'));//upgrade
        add_filter( 'plugin_action_links_' . $this->basename, array($this, 'plugin_bottom_links')); //bottom links
        add_action( 'init', array($this, 'register_post_type') );
        add_action( 'admin_init', array(&$this,'load_textdomain'));
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_styles' ) );
        add_action( 'add_meta_boxes', array($this, 'pinim_metabox'));
        
        //pins list
        add_filter( 'manage_'.$this->pin_post_type.'_posts_columns', array($this, 'pins_table_columns') );
        add_action( 'manage_'.$this->pin_post_type.'_posts_custom_column', array($this, 'pins_table_columns_content'), 10, 2 );
        add_filter( "views_edit-pin", array($this, 'pins_list_views') );
        add_filter('post_row_actions', array($this, 'pins_row_actions'), 10, 2);
        
        //sessions
        add_action( 'current_screen', array( $this, 'register_session' ), 1);
        add_action('wp_logout', array( $this, 'destroy_session' ) );
        add_action('wp_login', array( $this, 'destroy_session' ) );
        
        //promo
        add_action( 'all_admin_notices', array($this, 'plugin_page_header') );
    }
    
    function load_textdomain() {
        load_plugin_textdomain( 'pinim', false, $this->plugin_dir . '/languages' );
    }
    
    function pins_list_views($views){
        $pending_count = count(pinim_pending_imports()->get_all_raw_pins());
        $awaiting_url = pinim_get_menu_url(array('page'=>'pending-importation'));

        $views['pending_import'] = sprintf('<a href="%s">%s <span class="count">(%s)</span></a>',$awaiting_url,__('Pending importation','pinim'),$pending_count);

        return $views;
    }
    
    function pins_row_actions($actions, $post){
        if ( $post->post_type == $this->pin_post_type ) {
            if ( $pin_id = pinim_get_pin_id_for_post($post->ID) ){
                $url = pinim_get_pinterest_pin_url($pin_id);
                $actions['pinterest']  = sprintf('<a href="%1$s" target="_blank">%2$s</a>',$url,__('View on Pinterest','pinim'),'view');
            }
        }
        return $actions;
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
            $this->destroy_session();
            
            if($current_version < '208'){ //switch post type to 'pin'
                
                $querystr = $wpdb->prepare( "UPDATE $wpdb->posts table_posts LEFT JOIN $wpdb->postmeta table_metas ON table_posts.ID = table_metas.post_id SET table_posts.post_type = REPLACE(table_posts.post_type, %s, %s) WHERE table_metas.meta_key = '%s'", 'post', $this->pin_post_type,'_pinterest-pin_id' );
                
                $result = $wpdb->get_results ( $querystr );
                
            }
            
            if($current_version < '204'){
                $boards_settings = pinim_get_boards_options();
                foreach((array)$boards_settings as $key=>$board){
                    if (isset($board['id'])){
                        $boards_settings[$key]['board_id'] = $board['id'];
                        unset($boards_settings[$key]['id']);
                    }
                }
                update_user_meta( get_current_user_id(), $this->meta_name_user_boards_options, $boards_settings);
            }
            
            if($current_version < '206'){
                $boards_settings = pinim_get_boards_options();
                foreach((array)$boards_settings as $key=>$board){
                    if (!isset($board['username']) || !isset($board['slug']) ) continue;
                    $boards_settings[$key]['url'] = pinim_get_board_url($board['username'],$board['slug'], true);
                }
                update_user_meta( get_current_user_id(), $this->meta_name_user_boards_options, $boards_settings);
            }
        }




        //update DB version
        update_option("_pinterest-importer-db_version", $this->db_version );

    }

    function register_post_type() {

        $labels = array(
            'name'                  => _x( 'Pins', 'Post Type General Name', 'pinim' ),
            'singular_name'         => _x( 'Pin', 'Post Type Singular Name', 'pinim' ),
            /*
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
            */
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
        register_post_type( $this->pin_post_type, $args );

    }
    
    function pins_table_columns($columns){
        $columns['pin_source'] = __( 'Source', 'pinim' );
        $columns['pin_thumbnail'] = '';
        return $columns;
    }
    
    function pins_table_columns_content($column, $post_id){
        switch ( $column ) {
            case 'pin_thumbnail':
                printf(
                    '<img src="%1$s" />',
                    get_the_post_thumbnail_url($post_id)
                );
            break;
            case 'pin_source':
                $text = $url = null;

                $text = pinim_get_pin_log($post_id,'domain');
                $url = pinim_get_pin_log($post_id,'link');

                //if (!$text || !$url) return;

                printf(
                    '<a target="_blank" href="%1$s">%2$s</a>',
                    esc_url($url),
                    $text
                );
            break;
        }
    }
    
    function plugin_bottom_links($links){
        
        $links[] = sprintf('<a target="_blank" href="%s">%s</a>',$this->donate_link,__('Donate','pinim'));//donate
        
        if (current_user_can('manage_options')) {
            $settings_page_url = pinim_get_menu_url(array('page'  => 'settings'));
            $links[] = sprintf('<a href="%s">%s</a>',esc_url($settings_page_url),__('Settings'));
        }
        
        return $links;
    }

    function enqueue_scripts_styles($hook){
        $screen = get_current_screen();

        if ( $screen->post_type != $this->pin_post_type ) return;
        
        wp_enqueue_script('pinim', $this->plugin_url.'_inc/js/pinim.js', array('jquery'),$this->version);
        wp_enqueue_style('font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css',false,'4.3.0');
        wp_enqueue_style('pinim', $this->plugin_url . '_inc/css/pinim.css',false,$this->version);
        
        //localize vars
        $localize_vars=array();
        $localize_vars['ajaxurl']=admin_url( 'admin-ajax.php' );
        wp_localize_script('pinim','pinimL10n', $localize_vars);
        
    }
    
    function get_options($keys = null){
        return pinim_get_array_value($keys, $this->options);
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
        global $post;
        if ( $log = pinim_get_pin_log($post->ID) ){
            add_meta_box(
                    'pinterest_log',
                    __( 'Pinterest Log', 'pinim' ),
                    array(&$this,'pinim_metabox_log_content'),
                    $this->pin_post_type
            );
        }
    }
    
    function pinim_metabox_log_content( $post ) {

        if ( $log = pinim_get_pin_log($post->ID) ){
            $list = pinim_get_list_from_array($log);
            printf('<div>%s</div>',$list);
        }
        
        if ( $db_version = pinim_get_pin_meta('db_version')[0] ){
            echo '<small id="pinim-db-version">' . sprintf(__( 'Pinterest Importer DB version: %s', 'pinim' ),'<strong>' . $db_version . '</strong>') . '</small>';
        }
        
    }
    
    /**
     * Register a session so we can store the temporary data.
     */
    function register_session(){
        $screen = get_current_screen();
        if ( $screen->post_type != $this->pin_post_type ) return;
        if( !session_id() ) session_start();
    }
    
    function destroy_session(){
        $this->debug_log('destroy_session');
        $this->delete_session_data();
    }

    //Would be better to use transients here, but that would mean that we would store pwd in db.
    function set_cached_data($key,$data){
        $_SESSION['pinim'][$key] = $data;
        return true;
    }
    
    function delete_session_data($key = null){
        if (!isset($_SESSION['pinim'])) return false;
        
        if ($key){
            if (!isset($_SESSION['pinim'][$key])) return false;
            unset($_SESSION['pinim'][$key]);
            return;
        }
        unset($_SESSION['pinim']);
    }
    
    function get_cached_data($keys = null){
        
        if (!isset($_SESSION['pinim'])) return null;
        $session = $_SESSION['pinim'];
        
        return pinim_get_array_value($keys, $session);

    }
    
    function plugin_page_header(){
        $screen = get_current_screen();
        if ( $screen->post_type != pinim()->pin_post_type ) return;

        $pins_count = count( pinim()->get_processed_pin_ids() );
        if ($pins_count > 1){
            $rate_link_wp = 'https://wordpress.org/support/view/plugin-reviews/pinterest-importer?rate#postform';
            $rate_link = '<a href="'.$rate_link_wp.'" target="_blank" href=""><i class="fa fa-star"></i> '.__('Reviewing the plugin','pinim').'</a>';
            $donate_link = '<a href="'.pinim()->donate_link.'" target="_blank" href=""><i class="fa fa-usd"></i> '.__('make a donation','pinim').'</a>';
            ?>
            <div id="pinim-page-header">
                <p class="description" id="pinim-page-header-feedback">
                    <?php printf(__('<i class="fa fa-pinterest-p"></i>roudly already imported %1$s pins !  Happy with that ? %2$s and %3$s would help!','pinim'),'<strong>'.$pins_count.'</strong>',$rate_link,$donate_link);?>
                </p>
                <?php $this->plugin_page_header_user();?>
            </div>
            <?php
        }

        //general notices
        settings_errors('feedback_pinim'); 
    }
    
    function plugin_page_header_user(){
        
        $user_icon = $user_text = $user_stats = null;
        
        
        if ( pinim()->get_cached_data() ) { //session exists
            
            $user_data = pinim_account()->get_user_profile();

            $user_icon = $user_data['profile_image_url'];
            $username = $user_data['username'];
            
            //counts
            $board_count = $secret_board_count = 0;
            
            $user_boards = pinim_boards()->get_boards_user();

            if ( !is_wp_error($user_boards) ){
                foreach((array)$user_boards as $board){
                    if ( $board->is_private_board() ){
                        $secret_board_count++;
                    }else{
                        $board_count++;
                    }
                }
            }

            //names
            $user_text = sprintf(__('Logged as %s','pinim'),'<strong>'.$username.'</strong>');

            $list = array();

            //public boards
            $list[] = sprintf(
                '<span>'.__('%1$s public boards','pinim').'</span>',
                '<strong>'.$board_count.'</strong>'
            );

            //private boards
            $list[] = sprintf(
                '<span>'.__('%1$s private boards','pinim').'</span>',
                '<strong>'.$secret_board_count.'</strong>'
            );

            $user_stats = implode(",",$list);

            $user_icon = sprintf('<img src="%s" class="img-cover"/>',$user_icon);
            $logout_link = pinim_get_menu_url(array('page'=>'account','do_logout'=>true));

            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> <small id="user-info-stats">(%3$s)</small> — <a id="user-logout-link" href="%4$s">%5$s</a>',$user_icon,$user_text,$user_stats,$logout_link,__('Logout','pinim'));
            
        }else{ // not logged
            $user_icon = '';
            $user_text = '<strong>' . __('Not logged to Pinterest','pinim') . '</strong>';
            $login_link = pinim_get_menu_url(array('page'=>'account'));
            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> — <a id="user-logout-link" href="%3$s">%4$s</a>',$user_icon,$user_text,$login_link,__('Login','pinim'));
        }
        
        printf('<div id="pinim-page-header-account">%s</div>',$content);

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
    
    /*
    Get path where are written pinim files
    */
    function get_uploads_dir(){
        
        if (!$this->uploads_dir){
            $dir = WP_CONTENT_DIR . '/uploads/pinim';
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            $this->uploads_dir = trailingslashit($dir);
        }
        
        return $this->uploads_dir;

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

pinim();

?>
