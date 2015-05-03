<?php

class Pinim_Tool_Page {
    
    var $options_page;
    public $current_step = 0;
    var $current_user_board = 0;
    private $steps = array('login','board-settings','fetch-pins');
    
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
        add_action( 'admin_init', array( $this, 'init_tool_page' ) );
        add_action( 'admin_init', array( $this, 'settings_page_init' ) );
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);
    }
    
    function init_tool_page(){
        if (!pinim_is_tool_page()) return false;

        $step = pinim_get_tool_page_step();
        
        if($step!==false){
            $this->current_step = $step;
        }else{
            if ($user_boards = pinim()->get_session_data('user_boards')){ //check cache exists
                $url = pinim_get_tool_page_url(array('step'=>1));
                wp_redirect( $url );
                die();
            }
            
        }

        $this->save_step();
        $this->init_step();

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
        if ( isset($input['clear_user_boards_cache']) && $input['clear_user_boards_cache'] ){
            pinim()->delete_session_data('user_boards');
            add_settings_error('pinim', 'clear_user_boards_cache', __( 'Cache Successfully cleared', 'pinim' ), 'updated');
            return;
        }
        

        switch ($this->current_step){

            case 2://'fetch-pins':

                $pin_settings = array();
                $pin_error_ids = array();
                $skip_pin_import = array();
                $bulk_pins = pinim_get_requested_pins();

                //check if a filter action is set
                if ($filter_action = pinim_get_pins_filter_action()){
                    $action = $filter_action;
                }

                switch ($action) {

                    case 'pins_update_pins':
                        foreach((array)$bulk_pins as $key=>$pin){

                            //skip
                            if (!$post_id = pinim_get_post_by_pin_id($pin->pin_id)){
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
                            
                            if (!pinim_get_pins_filter_action()){
                            
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
                            if ($post_id = pinim_get_post_by_pin_id($pin->pin_id)){
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
                            
                            if (!pinim_get_pins_filter_action()){
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
            
            case 1://'boards-settings':

            $board_settings = array();
            $board_error_ids = array();
            
            if ( isset($input['boards']) ){
                $board_settings = $input['boards'];
            }
                
                //bulk boards
                $bulk_boards = pinim_get_requested_boards();
                
                //check if a filter action is set
                if ($filter_action = pinim_get_boards_filter_action()){
                    $action = $filter_action;
                }

                switch ($action) {
                    case 'boards_update_settings':
                        
                    foreach((array)$bulk_boards as $key=>$board){
   
                        $board_saved = $board->set_options($board_settings[$board->board_id]);

                        if (is_wp_error($board_saved)){
                            $board_error_ids[]=$board->board_id;
                            add_settings_error('pinim', 'set_options_board_'.$board->board_id, $board_saved->get_error_message());
                        }

                    }

                    if (!empty($bulk_boards) && empty($board_error_ids)){
                        add_settings_error('pinim', 'set_options_boards', __( 'Boards Successfully updated', 'pinim' ), 'updated');
                    }
                        
                    break;
                    case 'boards_cache_pins':

                        foreach((array)$bulk_boards as $board){
                        
                            $board_pins = $board->get_pins('disabled');

                            if (is_wp_error($board_pins)){
                                $board_error_ids[]=$board->board_id;
                                add_settings_error('pinim', 'cache_single_board_pins_'.$board->board_id, $board_pins->get_error_message());
                            }
                            
                        }
                        
                        if (empty($board_error_ids)){
                            add_settings_error('pinim', 'cache_single_board_pins', __( 'Boards Pins Successfully cached', 'pinim' ), 'updated');
                        }
                    
                    break;
                    
                    case 'boards_import_pins':
                        
                        $bulk_boards_ids = array();
                        foreach ((array)$bulk_boards as $bulk_boards){
                            $bulk_boards_ids[] = $bulk_boards->board_id;
                        }
                        
                        //redirect to next step, and set selected board_ids.
                        $url = pinim_get_tool_page_url(array('step'=>2,'board_ids'=>implode(',',$bulk_boards_ids)));
                        wp_redirect( $url );
                        die();
                    break;
                }

            break;
            default: //login
                
                //login
                if( isset( $input['login'] )  ){
                    $new_input['login'] = $input['login']; 
                }

                //pwd
                if( isset( $input['password'] )  ){
                    $new_input['password'] = $input['password']; 
                }

                if ( !isset($new_input['login']) || !isset($new_input['password']) ) return;

                //login
                $login = pinim()->pinterest_do_login($new_input['login'],$new_input['password']);

                if (!is_wp_error($login) ){
                    $boards_data = pinim_get_boards_data(); //do populate boards
                    if (!is_wp_error($boards_data)){
                        $url = pinim_get_tool_page_url(array('step'=>1));
                        wp_redirect( $url );
                        die();
                    }else{
                        add_settings_error('pinim', 'get_boards_data', sprintf(__('Error while trying to get boards data : %1$s.','pinim'),$boards_data->get_error_message()));
                    }

                    
                }else{
                    add_settings_error('pinim', 'do_login', sprintf(__('Error while trying to login : %1$s.','pinim'),$login->get_error_message()));
                }
                

            break;
        }
        
    }

    function init_step(){
        
        
        $board_ids = array();
        
        //redirect to login page
        if ($this->current_step){
            if (!$user_boards = pinim()->get_session_data('user_boards')){ //not populated
                $url = pinim_get_tool_page_url();
                wp_redirect( $url );
                die();
            }
        }

        switch ($this->current_step){
            case 2: //pins settings
                
                $board_error_ids = array();
                $bulk_pins_ids = array();
                $bulk_pins = array();
                $pins = array();
                $requested_boards = array();
                
                
                $pins = pinim_get_requested_pins();
                
                //filter by status
                $requested_status = pinim_get_screen_pins_import_status();

                foreach ((array)$pins as $key=>$pin){

                    $post_id = pinim_get_post_by_pin_id($pin->pin_id);

                    if ($requested_status=='pending'){
                        if ($post_id) unset($pins[$key]);
                    }elseif ($requested_status=='processed'){
                        if (!$post_id) unset($pins[$key]);
                    }

                }

                $this->table_pins = new Pinim_Pins_Table($pins);
                $this->table_pins->prepare_items();
                    
                

            break;
            case 1: //boards settings
                
                $boards = array();
                
                $boards_data = pinim()->get_session_data('user_boards');

                if ( $boards_data ){
                    foreach((array)$boards_data as $board_data){
                        $boards[] = new Pinim_Board($board_data['id']);
                    }

                }else{
                    
                    $link_user_cache_args = array('step' => 0);
                    $link_user_cache = pinim_get_tool_page_url($link_user_cache_args);
                    
                    add_settings_error('pinim', 'boards-cache', sprintf(__( 'No boards found.  Please <a href="%1$s">refresh user cache</a>.', 'pinim' ),$link_user_cache));
                }
                
                //filter by status
                $requested_status = pinim_get_screen_boards_import_status();

                foreach ((array)$boards as $key=>$board){

                    $queue = $board->get_pins_queue();

                    if ($requested_status=='pending'){
                        if ($queue) unset($boards[$key]);
                    }elseif ($requested_status=='cached'){
                        if (!$queue) unset($boards[$key]);
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
    }

    function settings_page_init(){
        
        
        register_setting(
             'pinim', // Option group
             'pinim_tool', // Option name
             array( $this, 'dummy_sanitize' ) // Sanitize
         );
        
        switch($this->current_step){
            
            case 1://'boards-settings':
            break;
            
            default:

                add_settings_section(
                     'settings_general', // ID
                     __('Pinterest authentification','pinim'), // Title
                     array( $this, 'section_general_desc' ), // Callback
                     'pinim-user-auth' // Page
                );
                
                if (!pinim()->pinterest_is_logged()){
                    add_settings_field(
                        'login', 
                        __('Login','pinim'), 
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
                }else{
                    
                    add_settings_field(
                         'status', 
                         __('Status','pinim'), 
                         array( $this, 'status_field_callback' ), 
                        'pinim-user-auth', 
                        'settings_general'
                    );
                    
                }

                 add_settings_section(
                     'settings_system', // ID
                     __('System','pinim'), // Title
                     array( $this, 'section_system_desc' ), // Callback
                     'pinim-user-auth' // Page
                 );
                 
                 if(pinim()->get_session_data()){
                    add_settings_field(
                        'delete_user_boards_data', 
                        __('User boards cache','pinim'), 
                        array( $this, 'delete_user_boards_cache_callback' ), 
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
            <?php screen_icon(); ?>
            <h2><?php _e('Pinterest Importer','pinim');?></h2>  
            <?php settings_errors();?>
                    <?php

                    switch ($this->current_step){
                        case 2: //fetch pins
                            ?>
                            <form id="pinim-form" method="post" action="">
                                <?php
                                $this->table_pins->views();
                                $this->table_pins->display();
                                ?>
                            </form>
                            <?php
                            break;
                        case 1: //'boards-settings'
                            ?>
                            <form id="pinim-form" method="post" action="">
                                <?php
                                
                                $this->table_board->views();
                                $this->table_board->display();

                                    printf(
                                        '<a href="%1$s" />%2$s</a>',
                                        pinim_get_tool_page_url(array('step'=>2)),
                                        __('Fetch Pins','pinim')
                                    );
                                ?>
                            </form>
                            <?php
                            
                            
                        break;
                        default: //login
                            ?>
                            <form id="pinim-form" method="post" action="options.php">
                                <?php
                                // This prints out all hidden setting fields
                                settings_fields( 'pinim' );

                                ?>
                                <input type="hidden" name="step" value="<?php echo $this->current_step;?>" />
                                <?php

                                do_settings_sections( 'pinim-user-auth' );

                                if ( pinim()->get_session_data('user_datas') ){

                                    printf(
                                        '<a href="%1$s" />%2$s</a>',
                                        pinim_get_tool_page_url(array('step'=>1)),
                                        __('Go to Boards Settings','pinim')
                                    );

                                }
                                submit_button(__('Login','pinim'));


                                ?>
                            </form>
                            <?php
                            
                            
                            
                        break;
                    }
                    
                
                ?>
            
        </div>
        <?php
    }
    
    function section_general_desc(){
        $session_cache = session_cache_expire();
        echo "<p>".sprintf(__('Your login, password and datas retrieved from Pinterest will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p>";
        echo "<p>".__("If you get a HTTP error, please try again; sometimes it does not work at the first time.  Eventually, reset your internet connection.",'pinim')."</p>";
    }
    
    function login_field_callback(){
        $option = pinim()->get_session_data('login');
        printf(
            '<input type="text" name="%1$s[login]" value="%2$s"/>',
            'pinim_tool',
            $option
        );
    }
    
    function password_field_callback(){
        $option = pinim()->get_session_data('password');
        printf(
            '<input type="password" name="%1$s[password]" value="%2$s"/>',
            'pinim_tool',
            $option
        );
    }
    
    function status_field_callback(){

        if ( $user_datas = pinim()->get_session_data('user_datas') ){
            print_r($user_datas);
        }

    }
    
    function section_system_desc(){
        
    }
    
    function delete_user_boards_cache_callback(){

            printf(
                '<p><input type="checkbox" name="%1$s[clear_user_boards_cache]" value="on"/> %2$s</p>',
                'pinim_tool',
                __('Clear user boards cache & force regenerate','pinim')
            );
    }

}

function pinim_tool_page() {
	return Pinim_Tool_Page::instance();
}

if (is_admin()){
    pinim_tool_page();
}

