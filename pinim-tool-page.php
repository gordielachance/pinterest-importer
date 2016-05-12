<?php

class Pinim_Tool_Page {
    
    var $options_page;
    var $current_step = 0;
    var $existing_pin_ids = array();
    var $screen_boards_filter = null;
    var $screen_pins_filter = null;
    var $bridge = null;
    var $session = null;

    var $all_action_str = array(); //text on all pins | boards actions
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Tool_Page;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    private function __construct() { /* Do nothing here */ }
    
    function init(){

        $this->all_action_str = array(
            'cache_all_pins'        =>__( 'Cache All Pins','pinim' ),
            'import_all_pins'       =>__( 'Import All Pins','pinim' ),
            'update_all_pins'       =>__( 'Update All Pins','pinim' ),
            'update_all_boards'     =>__( 'Update All Boards Settings','pinim' )
        );

        add_action( 'admin_init', array( $this, 'init_tool_page' ) );
        add_action( 'admin_init', array( $this, 'reduce_settings_errors' ) );
        add_action( 'admin_init', array( $this, 'settings_page_init' ) );
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);
        
        add_action( 'admin_init', array( $this, 'register_session' ), 1);
        add_action('wp_logout', array( $this, 'destroy_session' ) );
        add_action('wp_login', array( $this, 'destroy_session' ) );
        
    }
    
    function step(){
        
        //1  login page
        //2  cache board page + ajax redirect - EXCEPT IF IS FIRST LOGIN, board setup first
        //3  import pins page + details if any
        
    }
    
    function init_tool_page(){
        if (!pinim_is_tool_page()) return false;
        
        /*
        //dependencies
        if ( !is_plugin_active( 'wp-session-manager/wp-session-manager.php' ) ) {
            $dep_install_url = admin_url( 'plugin-install.php?tab=search&s=WP+Session+Manager' );
            $dep_link = '<a href="https://wordpress.org/plugins/wp-session-manager" target="_blank">WP Session Manager</a>';
            $message = sprintf(__( 'Pinterest Importer requires the plugin %1$s by Eric Mann to be installed. Click <a href="%2$s">here</a> !', 'pinim' ),$dep_link,$dep_install_url);
            add_settings_error('pinim', 'require_wp_session_manager', $message);
            return;
        }else{
            $this->session = WP_Session::get_instance();
        }
         */

        $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
        $this->bridge = new Pinim_Bridge;
        $step = pinim_get_tool_page_step();
        
        if($step!==false){
            
            $this->current_step = $step;
            $this->screen_boards_filter = $this->get_screen_boards_filter();
            $this->screen_pins_filter = $this->get_screen_pins_filter();
            
        }else{
            
            if ( $boards_data = $this->get_session_data('user_boards') ){ //we've got a boards cache
                $url = pinim_get_tool_page_url(array('step'=>'boards-settings'));

            }else{
                $url = pinim_get_tool_page_url(array('step'=>'pinterest-login'));
            }
            
            wp_redirect( $url );
            die();
            
        }

        $this->save_step();
        $this->init_step();

    }
    
    /**
     * Removes duplicate settings errors (based on their messages)
     * @global type $wp_settings_errors
     */
    
    function reduce_settings_errors(){
        //remove duplicates errors based on their message
        global $wp_settings_errors;

        if (empty($wp_settings_errors)) return;
        
        foreach($wp_settings_errors as $key => $value) {
          foreach($wp_settings_errors as $key2 => $value2) {
            if($key != $key2 && $value['message'] === $value['message']) {
              unset($wp_settings_errors[$key]);
            }
          }
        }
        
    }
    
    function get_screen_boards_filter(){
        
        if ($this->screen_boards_filter) return $this->screen_boards_filter;
        
        //default
        $status = 'active';
        
        if (!$this->get_boards_count_pending()){
            if (!$this->get_boards_count_waiting()){
                $status = 'active';
            }else{
                $status = 'disabled';
            }
        }

        if (isset($_REQUEST['boards_filter'])){
            $status = $_REQUEST['boards_filter'];
        }
        
        $this->screen_boards_filter = $status;

        return $status;
    }
    
    function get_screen_pins_filter(){

        if ($this->screen_pins_filter) return $this->screen_pins_filter;
        
        //default
        $status = 'pending';
        if (!pinim_tool_page()->get_pins_count_pending()){
            $status = 'processed';
        }

        if (isset($_REQUEST['pins_filter'])){
            $status = $_REQUEST['pins_filter'];
        }

        $this->screen_pins_filter = $status;

        return $status;
    }
    
    function form_do_login($login=null,$password=null){

        //try to auth
        $logged = $this->do_bridge_login($login,$password);
        if ( is_wp_error($logged) ) return $logged;
        
        //store login / password
        $this->set_session_data('login',$login);
        $this->set_session_data('password',$password);

        //try to get user datas
        $user_datas = $this->bridge->get_user_datas();
        if (is_wp_error($user_datas)) return $user_datas;

        //store user datas
        $this->set_session_data('user_datas',$user_datas);

        return true;
        
    }

    
    function save_step(){

        $input = null; //form datas, etc.
        
        if ( isset($_POST['pinim_tool']) ) {
            $input = $_POST['pinim_tool'];
        }

        $new_input = array();

        $user_id = get_current_user_id();
        $action = ( isset($_REQUEST['action']) ? $_REQUEST['action'] : null);

        //SYSTEM
        //clear boards cache
        if ( isset($input['clear_cache']) && $input['clear_cache'] ){
            $this->delete_session_data();
            add_settings_error('pinim', 'clear_cache', __( 'Cache Successfully cleared', 'pinim' ), 'updated');
            return;
        }
        

        switch ($this->current_step){

            case 'grab-pins':

                $pin_settings = array();
                $pin_error_ids = array();
                $skip_pin_import = array();
                $bulk_pins = $this->get_requested_pins();

                //check if a filter action is set
                if ($all_pins_action = $this->get_all_pins_action()){
                    $action = $all_pins_action;
                }

                switch ($action) {

                    case 'pins_update_pins':
                        
                        foreach((array)$bulk_pins as $key=>$pin){

                            //skip
                            
                            if (!in_array($pin->pin_id,$this->existing_pin_ids)){
                                $skip_pin_import[] = $pin->pin_id;
                                continue;
                            }

                            //save pin
                            $pin_saved = $pin->save(true);
                            if (is_wp_error($pin_saved)){
                                $pins_errors[$pin->pin_id] = $pin_saved;
                            }
                            
                        }

                        //errors
                        
                        if (!empty($bulk_pins) && !empty($skip_pin_import)){
                            
                            //remove skipped pins from bulk
                            foreach((array)$bulk_pins as $key=>$pin){
                                if (!in_array($pin->pin_id,$skip_pin_import)) continue;
                                unset($bulk_pins[$key]);
                            }
                            
                            if (!$all_pins_action){
                            
                                add_settings_error('pinim', 'pins_never_imported', 
                                    sprintf(
                                        __( 'Some pins cannot be updated because they never have been imported.  Choose "%1$s" if you want import pins. (Pins: %2$s)', 'pinim' ),
                                        __('Import Pins','pinim'),
                                        implode(',',$skip_pin_import)
                                        )
                                );
                            }
                            
                        }

                        
                        if (!empty($bulk_pins)){

                            $bulk_count = count($bulk_pins);
                            $errors_count = (!empty($pins_errors)) ? count($pins_errors) : 0;
                            $success_count = $bulk_count-$errors_count;
                            
                            if ($success_count){
                                add_settings_error('pinim', 'update_pins', sprintf(__( '%1$s Pins successfully updated', 'pinim' ),$success_count), 'updated');
                            }
                            
                            if (!empty($pins_errors)){
                                foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                    add_settings_error('pinim', 'update_pin_'.$pin_id, $pin_error->get_error_message());
                                }
                            }
                        }
                    break;
                    case 'pins_import_pins':

                        foreach((array)$bulk_pins as $key=>$pin){

                            //skip
                            if (in_array($pin->pin_id,$this->existing_pin_ids)){
                                $skip_pin_import[] = $pin->pin_id;
                                continue;
                            }

                            //save pin
                            $pin_saved = $pin->save();
                            if (is_wp_error($pin_saved)){
                                $pins_errors[$pin->pin_id] = $pin_saved;
                            }
                            
                        }
                        

                        //errors
                        
                        if (!empty($bulk_pins) && !empty($skip_pin_import)){
                            
                            //remove skipped pins from bulk
                            foreach((array)$bulk_pins as $key=>$pin){
                                if (!in_array($pin->pin_id,$skip_pin_import)) continue;
                                unset($bulk_pins[$key]);
                            }
                            
                            if (!$all_pins_action){
                                
                                add_settings_error('pinim', 'pins_already_imported', 
                                    sprintf(
                                        __( 'Some pins have been skipped because they already have been imported.  Choose "%1$s" if you want update the existing pins. (Pins: %2$s)', 'pinim' ),
                                        __('Update Pins','pinim'),
                                        implode(',',$skip_pin_import)
                                        )
                                );
                            }
                            

                            
                        }

                        
                        if (!empty($bulk_pins)){

                            $bulk_count = count($bulk_pins);
                            $errors_count = (!empty($pins_errors)) ? count($pins_errors) : 0;
                            $success_count = $bulk_count-$errors_count;
                            
                            if ($success_count){
                                add_settings_error('pinim', 'import_pins', sprintf(__( '%1$s Pins successfully imported', 'pinim' ),$success_count), 'updated');
                                //refresh pins list
                                $this->existing_pin_ids = pinim_get_meta_value_by_key('_pinterest-pin_id');
                            }
                            
                            if (!empty($pins_errors)){
                                foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                    add_settings_error('pinim', 'import_pin_'.$pin_id, $pin_error->get_error_message());
                                }
                            }
                        }

                    break;
                }

            break;
            
            case 'boards-settings'://'boards-settings':

            $board_settings = array();
            $board_errors = array();
            
            if ( isset($input['boards']) ){
                $board_settings = $input['boards'];
            }
                
                //bulk boards
                $bulk_boards = $this->get_requested_boards();
                
                //check if a filter action is set
                if ($all_boards_action = $this->get_all_boards_action()){
                    $action = $all_boards_action;
                }
                

                switch ($action) {
                    case 'boards_update_settings':
                        
                    foreach((array)$bulk_boards as $key=>$board){
   
                        $board_saved = $board->set_options($board_settings[$board->board_id]);

                        if (is_wp_error($board_saved)){
                            $board_errors[$board->board_id]=sprintf(__('Board #%1$s: ','pinim'),$board->board_id).$board_saved->get_error_message();
                        }
                        
                        pinim()->user_boards_options=null; //force reload

                    }

                    if (!empty($bulk_boards) && empty($board_errors)){
                        add_settings_error('pinim', 'set_options_boards', __( 'Boards Successfully updated', 'pinim' ), 'updated');
                    }else{
                        $board_errors = array_unique($board_errors);
                        foreach ($board_errors as $board_id=>$error){
                            add_settings_error('pinim', 'set_options_board_'.$board_id, $board_errors);
                        }
                    }
                    
                    
                        
                    break;
                    case 'boards_cache_pins':

                        foreach((array)$bulk_boards as $board){
                        
                            $board_pins = $board->get_pins(true);

                            if (is_wp_error($board_pins)){                                
                                $board_error[$board->board_id]=sprintf(__('Board #%1$s: ','pinim'),$board->board_id).$board_pins->get_error_message();
                                add_settings_error('pinim', 'cache_single_board_pins_'.$board->board_id, $board_pins->get_error_message());
                            }
                            
                        }
                        
                        if ($bulk_boards && empty($board_error)){
                            add_settings_error('pinim', 'cache_single_board_pins', __( 'Boards Pins successfully cached', 'pinim' ), 'updated');
                        }else{
                            $board_errors = array_unique($board_errors);
                            foreach ($board_errors as $board_id=>$error){
                                add_settings_error('pinim', 'set_options_board_'.$board_id, $board_errors);
                            }
                        }
                    
                    break;
                    
                    case 'boards_import_pins':
                        
                        $bulk_boards_ids = array();
                        foreach ((array)$bulk_boards as $bulk_boards){
                            $bulk_boards_ids[] = $bulk_boards->board_id;
                        }
                        
                        //redirect to next step, and set selected board_ids.
                        $url = pinim_get_tool_page_url(array('step'=>'grab-pins','board_ids'=>implode(',',$bulk_boards_ids)));
                        wp_redirect( $url );
                        die();
                    break;
                }

            break;
            default: //pinterest-login
                
                if ( !isset($_POST['pinim_form_login']) ) return;
                
                $login = ( isset($_POST['pinim_form_login']['username']) ? $_POST['pinim_form_login']['username'] : null);
                $password = ( isset($_POST['pinim_form_login']['password']) ? $_POST['pinim_form_login']['password'] : null);

                $logged = $this->form_do_login($login,$password);

                if (is_wp_error($logged)){
                    add_settings_error( 'pinim_form_login', 'do_login', $logged->get_error_message() );
                    return;
                }
                
                
                
                //try to populate boards
                $boards_data = $this->get_boards_data();

                if (is_wp_error($boards_data)) return $boards_data;

                //redirect to next step
                $args = array(
                    'step'=>'boards-settings'
                );

                $url = pinim_get_tool_page_url($args);
                wp_redirect( $url );
                die();

            break;
        }
        
    }
    
   function do_bridge_login($login = null, $password = null){
       
       if ($this->bridge->is_logged_in) return $this->bridge->is_logged_in;
       
       if (!$login){
           $login = $this->get_session_data('login');
       }
       if (!$password){
           $password = $this->get_session_data('password');
       }

        //try to auth
        $this->bridge->set_login($login)->set_password($password);
        $logged = $this->bridge->do_login();

        if ( is_wp_error($logged) ){
            return new WP_Error( 'pinim',$logged->get_error_message() );
        }
        
        return $logged;

   }

    function init_step(){

        $board_ids = array();

        switch ($this->current_step){
            case 'grab-pins':
                
                //remove processed pins from request
                unset($_REQUEST['pin_ids']);

                if ($pins = $this->get_requested_pins()){

                    foreach ((array)$pins as $key=>$pin){

                        switch ( $this->get_screen_pins_filter() ){
                            case 'pending':
                                if (in_array($pin->pin_id,$this->existing_pin_ids)) unset($pins[$key]);
                            break;
                            case 'processed':
                                if (!in_array($pin->pin_id,$this->existing_pin_ids)) unset($pins[$key]);
                            break;
                        }

                    }
                
                }else{
                    add_settings_error('pinim', 'pins-cache', __( 'No pins found.  Have you cached the boards pins ?', 'pinim'));
                }
 
                $this->table_pins = new Pinim_Pins_Table($pins);
                $this->table_pins->prepare_items();
                    
                

            break;
            case 'boards-settings': //boards settings
                
                $boards = array();

                if ( $boards_data = $this->get_session_data('user_boards') ){ //check cache exists
                    foreach((array)$boards_data as $board_data){
                        $boards[] = new Pinim_Board($board_data['id']);
                    }

                }else{
                    
                    $link_user_cache_args = array('step' => 0);
                    $link_user_cache = pinim_get_tool_page_url($link_user_cache_args);
                    
                    add_settings_error('pinim', 'boards-cache', __( 'No boards found.  Have you logged in ?', 'pinim'));
                }

                foreach ((array)$boards as $key=>$board){

                    $is_queue_complete = $board->is_queue_complete();
                    $is_fully_imported = $board->is_fully_imported();
                    
                    switch ($this->get_screen_boards_filter()){
                        case 'pending':
                            if ($is_fully_imported) unset($boards[$key]);
                        break;
                        case 'waiting':
                            if ($is_queue_complete) unset($boards[$key]);
                        break;
                        case 'completed':
                            if (!$is_fully_imported) unset($boards[$key]);
                        break;
                    }

                }

                $this->table_board = new Pinim_Boards_Table($boards);
                $this->table_board->prepare_items();

            break;
        }
    }
    
    function admin_menu(){
        $this->options_page = add_submenu_page('tools.php', __('Pinterest Importer','pinim'), __('Pinterest Importer','pinim'), 'manage_options', 'pinim', array($this, 'importer_page'));
    }
    
    function dummy_sanitize( $input ){
        /*
         * Do nothing here.  We use our own hooked function save_step() at init, this one is not necessary.
         */
        return false;
    }

    function settings_page_init(){

        register_setting(
             'pinim', // Option group
             'pinim_tool', // Option name
             array( $this, 'dummy_sanitize' ) // Sanitize
         );
        
        switch($this->current_step){

            case 'pinterest-login':

                add_settings_section(
                     'settings_general', // ID
                     __('Pinterest authentification','pinim'), // Title
                     array( $this, 'section_general_desc' ), // Callback
                     'pinim-user-auth' // Page
                );
                
                add_settings_field(
                    'login', 
                    __('Email/Username','pinim'), 
                    array( $this, 'login_field_callback' ), 
                    'pinim-user-auth', 
                    'settings_general'
                );

                add_settings_field(
                    'password', 
                    __('Password','pinim'), 
                    array( $this, 'password_field_callback' ), 
                    'pinim-user-auth', 
                    'settings_general'
                ); 
                
                if ( $user_datas = $this->get_session_data('user_datas') ){
                    
                    add_settings_field(
                         'status', 
                         __('Account','pinim'), 
                         array( $this, 'status_field_callback' ), 
                        'pinim-user-auth', 
                        'settings_general'
                    );
                    
                }

                
                if($this->get_session_data()){
                    
                    add_settings_section(
                        'settings_system', // ID
                        __('System','pinim'), // Title
                        array( $this, 'section_system_desc' ), // Callback
                        'pinim-user-auth' // Page
                    );

                    add_settings_field(
                        'delete_user_boards_data', 
                        __('Delete session','pinim'), 
                        array( $this, 'delete_session_callback' ), 
                        'pinim-user-auth', 
                        'settings_system'
                    );
                 }

            break;
        }

 
    }

    function importer_page(){
        // Set class property
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Importer','pinim');?></h2>  
            <?php
                $pins_count = count($this->existing_pin_ids);
                if ($pins_count > 1){
                    $rate_link_wp = 'https://wordpress.org/support/view/plugin-reviews/pinterest-importer?rate#postform';
                    $rate_link = '<a href="'.$rate_link_wp.'" target="_blank" href=""><i class="fa fa-star"></i> '.__('Reviewing it','pinim').'</a>';
                    $donate_link = '<a href="'.pinim()->donation_url.'" target="_blank" href=""><i class="fa fa-usd"></i> '.__('make a donation','pinim').'</a>';
                    ?>
                    <p class="description" id="header-links">
                        <?php printf(__('<i class="fa fa-pinterest-p"></i>roudly already imported %1$s pins !  Happy with it ? %2$s and %3$s would help a lot!','pinim'),'<strong>'.$pins_count.'</strong>',$rate_link,$donate_link);?>
                    </p>
                    <?php
                }
            ?>
            
            <?php settings_errors('pinim'); ?>
            
            <h2 class="nav-tab-wrapper">
                <?php $this->importer_page_tabs($this->current_step); ?>
            </h2>
                    <?php
                    
                    $form_classes = array('pinim-form');
                    $form_id = null;
                    $form_action = null;
                    $form_content = null;
                    $form_bt_txt = null;

                    switch ($this->current_step){
                        case 'grab-pins':
                            $form_id = 'pinim-form-pins';
                            
                            ob_start();
    
                            $this->table_pins->views();
                            $this->table_pins->display();
                            
                            $form_content = ob_get_clean();
                            
                            break;
                        case 'boards-settings':
                            $form_id = 'pinim-form-boards';
                            
                            ob_start();

                            $this->table_board->views();
                            $this->table_board->display();
        
                            $form_content = ob_get_clean();
                            
                        break;
                        default: //login
                            $form_id = 'pinim-form-login';
                            $form_action = 'options.php';
                            $form_bt_txt = __('Login to Pinterest','pinim');
                            
                            //check sessions are enabled
                            if (!session_id()){
                                add_settings_error('pinim_form_login', 'no_sessions', __("It seems that your host doesn't support PHP sessions.  This plugin will not work properly.  We'll try to fix this soon.","pinim"));
                            }
                            
                            ob_start();
                            
                            settings_errors('pinim_form_login');

                            // This prints out all hidden setting fields
                            settings_fields( 'pinim' );

                            ?>
                            <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />
                            <?php

                            do_settings_sections( 'pinim-user-auth' );


                            $form_content = ob_get_clean();
                            
                        break;
                    }
                    
                    $form_bt = get_submit_button($form_bt_txt);
                    
                    printf('<form id="%1$s" %2$s method="post" action="%3$s">%4$s,%5$s</form>',$form_id,pinim_get_classes($form_classes),$form_action,$form_content,$form_bt);

                ?>
            
        </div>
        <?php
    }
    
    function importer_page_tabs( $active_tab = '' ) {
            $tabs_html    = '';
            $idle_class   = 'nav-tab';
            $active_class = 'nav-tab nav-tab-active';

            $tabs = array(
                'pinterest-login' => array(
                    'href' => pinim_get_tool_page_url(array('step'=>'pinterest-login')),
                        'name' => __( '1. My Account', 'pinim' )
                ),
                'boards-settings' => array(
                    'href' => pinim_get_tool_page_url(array('step'=>'boards-settings')),
                    'name' => __( 'Boards Settings', 'pinim' )
                ),
                'grab-pins' => array(
                    'href' => pinim_get_tool_page_url(array('step'=>'grab-pins')),
                    'name' => __( 'Import Pins', 'pinim' )
                )
            );

            // Loop through tabs and build navigation
            foreach ($tabs as $slug=>$tab_data ) {
                    $is_current = (bool) ( $slug == $active_tab );
                    $tab_class  = $is_current ? $active_class : $idle_class;
                    $tabs_html .= '<a href="' . esc_url( $tab_data['href'] ) . '" class="' . esc_attr( $tab_class ) . '">' . esc_html( $tab_data['name'] ) . '</a>';
            }

            echo $tabs_html;
    }

    
    function section_general_desc(){
        $session_cache = session_cache_expire();
        echo '<p class="description">'.sprintf(__('Your login, password and datas retrieved from Pinterest will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p>";
    }
    
    function login_field_callback(){
        $option = $this->get_session_data('login');
        $has_user_datas = (null !== $this->get_session_data('user_datas'));
        $disabled = disabled($has_user_datas, true, false);
        printf(
            '<input type="text" name="pinim_form_login[username]" value="%2$s"%3$s/>',
            'pinim_tool',
            $option,
            $disabled
        );
    }
    
    function password_field_callback(){
        $option = $this->get_session_data('password');
        $has_user_datas = (null !== $this->get_session_data('user_datas'));
        $disabled = disabled($has_user_datas, true, false);
        printf(
            '<input type="password" name="pinim_form_login[password]" value="%2$s"%3$s/>',
            'pinim_tool',
            $option,
            $disabled
        );
    }
    
    function status_field_callback(){

        if ( $user_datas = $this->get_session_data('user_datas') ){
            if (isset($user_datas['image_medium_url'])){
                $image = $user_datas['image_medium_url'];
                printf(
                    '<img src="%1$s"/>',
                    $image
                );
            }
        }
        
        //names
        printf(
            '<p><strong>%1$s (%2$s)</strong></p>',
            $user_datas['username'],
            $user_datas['full_name']
        );
        
        $list = array();
        
        //public boards
        $list[] = sprintf(
            '<li>'.__('%1$s public boards','pinim').'</li>',
            '<strong>'.$user_datas['board_count'].'</strong>'
        );
        
        //public boards
        $list[] = sprintf(
            '<li>'.__('%1$s private boards','pinim').'</li>',
            '<strong>'.$user_datas['secret_board_count'].'</strong>'
        );
        
        //likes
        $list[] = sprintf(
            '<li>'.__('%1$s likes','pinim').'</li>',
            '<strong>'.$user_datas['like_count'].'</strong>'
        );
        
        echo "<ul>".implode("\n",$list)."</ul>";
        
        //print_r($user_datas);

    }
    
    function section_system_desc(){
        ?><p class="description">
            <?php
            _e('Delete session to reset the cache.  It will clear the temporary datas fetched from Pinterest but have no effects on pins already imported.','pinim');
            ?>
        </p>
        <?php
    }
    
    function delete_session_callback(){

            printf(
                '<p><input type="checkbox" name="%1$s[clear_cache]" value="on"/> %2$s</p>',
                'pinim_tool',
                __('Logout and delete cache','pinim')
            );
    }
    
    function get_boards_data(){

        $user_boards = null;

        if (!$user_boards = $this->get_session_data('user_boards')){ //already populated
            
            $logged = $this->do_bridge_login();

            $user_boards = $this->bridge->get_user_boards();

            if (is_wp_error($user_boards)){
                return $user_boards;
            }
            
            //likes board

            if ( $user_datas = pinim_tool_page()->get_session_data('user_datas') ){
                $likes_board = array(
                    'name'          => __('Likes','pinim'),
                    'id'            => 'likes',
                    'pin_count'     => $user_datas['like_count'],
                    'cover_images'  => array(
                        array(
                            'url'   => $user_datas['image_medium_url']
                        )
                    ),
                    'url'           => '/'.$user_datas['username'].'/likes'
                );
                $user_boards[] = $likes_board;
            }

            $this->set_session_data('user_boards',$user_boards);

        }

        return $user_boards;
    }
    
    function get_all_pins_action(){
        $action = null;

        //filter buttons
        if (isset($_REQUEST['all_pins_action'])){
            switch ($_REQUEST['all_pins_action']){
                //step 2
                case $this->all_action_str['import_all_pins']: //Import All Pins
                    $action = 'pins_import_pins';
                break;
                case $this->all_action_str['update_all_pins']: //Update All Pins
                    $action = 'pins_update_pins';
                break;

            }
        }

        return $action;
    }



    function get_all_boards_action(){
        $action = null;
        //filter buttons
        if (isset($_REQUEST['all_boards_action'])){
            switch ($_REQUEST['all_boards_action']){
                //step 1
                case $this->all_action_str['update_all_boards']: //Update all boards settings
                    $action = 'boards_update_settings';
                break;
                case $this->all_action_str['cache_all_pins']: //Cache All Pins
                    $action = 'boards_cache_pins';
                break;
                case $this->all_action_str['import_all_pins']: //Import All Pins
                    $action = 'boards_import_pins';
                break;

            }
        }
        return $action;
    }

    function get_requested_boards_ids(){
        $bulk_boards_ids = array();
        $bulk_boards = array();

        if (isset($_POST['pinim_tool'])){
            $input = $_POST['pinim_tool'];
        }

        if ( isset($input['boards']) ) { 

            $board_settings = $input['boards'];

            foreach((array)$board_settings as $board){
                if ( !$this->get_all_boards_action() && !isset($board['bulk']) ) continue;
                    $bulk_boards_ids[] = $board['id'];
            }

        }elseif ( isset($_REQUEST['board_ids']) ) {
            $bulk_boards_ids = explode(',',$_REQUEST['board_ids']);
        }



        return $bulk_boards_ids;
    }

    function get_requested_boards(){

        $bulk_boards_ids = $this->get_requested_boards_ids();
        $bulk_boards = array();

        foreach ((array)$bulk_boards_ids as $bulk_board_id){
            $bulk_boards[] = new Pinim_Board($bulk_board_id);
        }
        return $bulk_boards;
    }

    function get_requested_pins_ids(){
        $bulk_pins_ids = array();

        if (isset($_POST['pinim_tool'])){
            $input = $_POST['pinim_tool'];
        }

        //bulk pins
        if ( isset($input['pins']) ) {

            $pin_settings = $input['pins'];

            foreach((array)$pin_settings as $pin){
                if (!isset($pin['bulk'])) continue;
                    $bulk_pins_ids[] = $pin['id'];
            }

        }elseif ( isset($_REQUEST['pin_ids']) ) {

            $bulk_pins_ids = explode(',',$_REQUEST['pin_ids']);

        }

        if ( (!$bulk_pins_ids) && ( $requested_boards = pinim_tool_page()->get_requested_boards() ) ) {
            //get board queues
            foreach ((array)$requested_boards as $board){

                $board_datas = $board->get_datas();

                if ( is_wp_error($board_datas) ){
                    add_settings_error('pinim', 'get_datas_board_'.$board->board_id, $board_datas->get_error_message());
                    continue;
                }else{
                    $cached_pins = $board->get_cached_pins();

                    if ( empty($cached_pins) || is_wp_error($cached_pins) ){
                        $board_error_ids[]=$board->board_id;
                        $link_pins_cache = $board->get_link_action_cache();
                        add_settings_error('pinim', 'get_queue_board_'.$board->board_id, sprintf(__( 'No pins found for %1$s.  Please %2$s.', 'pinim' ),'<em>'.$board->get_datas('name').'</em>',$link_pins_cache));
                    }else{

                        foreach((array)$cached_pins as $raw_pin ){
                            $bulk_pins_ids[] = $raw_pin['id'];
                        }

                    }
                }


            }

        }

        return $bulk_pins_ids;
    }

    function get_requested_pins(){
        $pins = array();
        $requested_pins_ids = $this->get_requested_pins_ids();

        foreach ((array)$requested_pins_ids as $key=>$pin_id){
            $pin = new Pinim_Pin($pin_id);
            $pins[] = $pin;
        }
        
        if (!$requested_pins_ids){ //get all
            $all_pins = $this->get_all_cached_pins();
            foreach ($all_pins as $raw_pin){
                $pin = new Pinim_Pin($raw_pin['id']);
                $pins[] = $pin;
            }
        }

        return $pins;
    }

    function get_all_cached_pins(){

        $pins = array();

        $queues = (array)$this->get_session_data('queues');

        foreach ((array)$queues as $board_id=>$queue){
            if( isset($queue['pins']) ){
                $pins = array_merge($pins,$queue['pins']);
            }
        }

        return $pins;

    }
    
    function get_pins_count_pending(){
        $count = 0;
        
        $pins = $this->get_requested_pins();
        
        $processed_count = $this->get_pins_count_processed();
        
        return count($pins) - $processed_count;
    }
    
    function get_pins_count_processed(){
        $count = 0;
        
        $pins = $this->get_requested_pins();
        
        foreach ((array)$pins as $pin){
            if (in_array($pin->pin_id,pinim_tool_page()->existing_pin_ids)){
                $count++;
            }
        }
        return $count;
    }
    
    function get_boards_count_pending(){
         $count = 0;
         $boards_data = $this->get_session_data('user_boards');

        foreach((array)$boards_data as $board_data){
            $board = new Pinim_Board($board_data['id']);
            if ($board->is_queue_complete()){
                $count++;
            }

        }

        $count -= $this->get_boards_count_completed();

        return $count;

    }

    function get_boards_count_completed(){
        $count = 0;
        $boards_data = $this->get_session_data('user_boards');

       foreach((array)$boards_data as $board_data){
           $board = new Pinim_Board($board_data['id']);
           if ($board->get_pins_queue() && $board->is_fully_imported()){
               $count++;
           }

       }

       return $count;

    }

    function get_boards_count_waiting(){
        $count = 0;
        $boards_data = $this->get_session_data('user_boards');

        $count = count($boards_data) - $this->get_boards_count_pending() - $this->get_boards_count_completed();

       return $count;

    }
    
    /**
     * Register a session so we can store the temporary.
     */
    function register_session(){
        if (!pinim_is_tool_page()) return;
        if( !session_id() ) session_start();
    }
    
    function destroy_session(){
        $this->delete_session_data();
    }

    function set_session_data($key,$data){
        $_SESSION['pinim'][$key] = $data;
        return true;
    }
    
    function delete_session_data($key = null){
        if ($key){
            if (!isset($_SESSION['pinim'][$key])) return false;
            unset($_SESSION['pinim'][$key]);
            return;
        }
        unset($_SESSION['pinim']);
    }
    
    function get_session_data($key = null){

        if (!isset($_SESSION['pinim'])) return null;
        
        $data = $_SESSION['pinim'];
        
        if ($key){
            if (!isset($data[$key])) return null;
            return $data[$key];
        }
        
        return $data;
    }

}

function pinim_tool_page() {
	return Pinim_Tool_Page::instance();
}

if (is_admin()){
    pinim_tool_page();
}