<?php
    
class Pinim_Tool_Page {
    var $page_acount;
    var $page_boards;
    var $page_settings;
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
            'import_all_pins'       =>__( 'Import All Pins','pinim' ),
            'update_all_pins'       =>__( 'Update All Pins','pinim' )
        );
        
        
        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);

        add_action( 'current_screen', array( $this, 'page_pending_import_init') );

        add_action( 'all_admin_notices', array($this, 'plugin_header_feedback_notice') );

    }

    function page_pending_import_init(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_pending-importation') return;
        
        /*
        IMPORT PINS
        */

        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }

        //check if a filter action is set
        if ($all_pins_action = $this->get_all_pins_action()){
            $action = $all_pins_action;
        }
        
        if ($action){
            $pin_settings = array();
            $pin_error_ids = array();
            $skip_pin_import = array();
            $bulk_pins_ids = $this->get_requested_pins_ids();
            
            //TO FIX TO CHECK - the whole action stuff - no need for a switch since there is only one action.
            switch ($action) {

                case 'pins_import_pins':

                    foreach((array)$bulk_pins_ids as $key=>$pin_id){

                        //skip
                        if ( in_array( $pin_id,pinim_get_processed_pins_ids() ) ){
                            $skip_pin_import[] = $pin_id;
                            continue;
                        }

                        //save pin
                        $pin = new Pinim_Pin($pin_id);
                        $pin_saved = $pin->save();
                        if (is_wp_error($pin_saved)){
                            $pins_errors[$pin->pin_id] = $pin_saved;
                        }

                    }


                    //errors

                    if (!empty($bulk_pins_ids) && !empty($skip_pin_import)){

                        //remove skipped pins from bulk
                        foreach((array)$bulk_pins_ids as $key=>$pin_id){
                            if (!in_array($pin_id,$skip_pin_import)) continue;
                            unset($bulk_pins_ids[$key]);
                        }

                        if (!$all_pins_action){

                            add_settings_error('feedback_pending_import', 'pins_already_imported', 
                                sprintf(
                                    __( 'Some pins have been skipped because they already have been imported.  Choose "%1$s" if you want update the existing pins. (Pins: %2$s)', 'pinim' ),
                                    __('Update pins','pinim'),
                                    implode(',',$skip_pin_import)
                                ),
                                'inline'
                            );
                        }
                    }


                    if (!empty($bulk_pins_ids)){

                        $bulk_count = count($bulk_pins_ids);
                        $errors_count = (!empty($pins_errors)) ? count($pins_errors) : 0;
                        $success_count = $bulk_count-$errors_count;

                        if ($success_count){
                            add_settings_error('feedback_pending_import', 'import_pins', 
                                sprintf( _n( '%s pin have been successfully imported.', '%s pins have been successfully imported.', $success_count,'pinim' ), $success_count ),
                                'updated inline'
                            );
                        }

                        if (!empty($pins_errors)){
                            foreach ((array)$pins_errors as $pin_id=>$pin_error){
                                add_settings_error('feedback_pending_import', 'import_pin_'.$pin_id, $pin_error->get_error_message(),'inline');
                            }
                        }
                    }

                    //redirect to processed pins
                    $url = pinim_get_menu_url();
                    wp_redirect( $url );

                break;
            }
            
        }
        
        //clear pins selection //TO FIX REQUIRED ?
        //unset($_REQUEST['pin_ids']);
        //unset($_POST['pinim_form_pins']);
        
        /*
        INIT PENDING  PINS
        */
        
        $pins = array();
        $this->table_pins = new Pinim_Pending_Pins_Table();
        
        if ( !pinim_tool_page()->get_pins_count_pending() ){
            $boards_url = pinim_get_menu_url(array('page'=>'boards'));
            add_settings_error('feedback_pending_import','not_logged',sprintf(__('To list the pins you can import here, you first need to <a href="%s">cache some Pinterest Boards</a>.','pinim'),$boards_url),'error inline');
        }

        if ($pins_ids = $this->get_requested_pins_ids()){
            $pins_ids = array_diff( $pins_ids, pinim_get_processed_pins_ids() );

            //populate pins
            foreach ((array)$pins_ids as $pin_id){
                $pins[] = new Pinim_Pin($pin_id);
            }

            $this->table_pins->input_data = $pins;
            $this->table_pins->prepare_items();
        }
        
        
    }

    function admin_menu(){

        $this->page_pending_import = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pending importation','pinim'), 
            __('Pending importation','pinim'), 
            'manage_options', //TO FIX
            'pending-importation', 
            array($this, 'page_pending_import')
        );

    }

    function plugin_header_feedback_notice(){
        $screen = get_current_screen();
        if ( $screen->post_type != pinim()->pin_post_type ) return;
        

        $pins_count = count( pinim_get_processed_pins_ids() );
        if ($pins_count > 1){
            $rate_link_wp = 'https://wordpress.org/support/view/plugin-reviews/pinterest-importer?rate#postform';
            $rate_link = '<a href="'.$rate_link_wp.'" target="_blank" href=""><i class="fa fa-star"></i> '.__('Reviewing the plugin','pinim').'</a>';
            $donate_link = '<a href="'.pinim()->donate_link.'" target="_blank" href=""><i class="fa fa-usd"></i> '.__('make a donation','pinim').'</a>';
            ?>
            <div id="pinim-page-header">
                <p class="description" id="pinim-page-header-feedback">
                    <?php printf(__('<i class="fa fa-pinterest-p"></i>roudly already imported %1$s pins !  Happy with that ? %2$s and %3$s would help!','pinim'),'<strong>'.$pins_count.'</strong>',$rate_link,$donate_link);?>
                </p>
                <?php $this->user_infos_block();?>
            </div>
            <?php
        }

        //general notices
        settings_errors('feedback_pinim'); 
    }
    
    function user_infos_block(){
        
        $user_icon = $user_text = $user_stats = null;

        $user_data = pinim()->get_user_infos();
        if ( !is_wp_error($user_data) && $user_data ) { //logged
            
            $user_icon = pinim()->get_user_infos('image_medium_url');
            $username = pinim()->get_user_infos('username');
            $board_count = (int)pinim()->get_user_infos('board_count');
            $secret_board_count = (int)pinim()->get_user_infos('secret_board_count');
            $like_count = (int)pinim()->get_user_infos('like_count');

            //names
            $user_text = sprintf(__('Logged as %s','pinim'),'<strong>'.$username.'</strong>');

            $list = array();

            //public boards
            $list[] = sprintf(
                '<span>'.__('%1$s public boards','pinim').'</span>',
                '<strong>'.$board_count.'</strong>'
            );

            //public boards
            $list[] = sprintf(
                '<span><strike>'.__('%1$s private boards','pinim').'</strike></span>',
                '<strong>'.$secret_board_count.'</strong>'
            );

            //likes
            $list[] = sprintf(
                '<span>'.__('%1$s likes','pinim').'</span>',
                '<strong>'.$like_count.'</strong>'
            );

            $user_stats = implode(",",$list);

            $user_icon = sprintf('<img src="%s" class="img-cover"/>',$user_icon);
            $logout_link = pinim_get_menu_url(array('page'=>'account','logout'=>true));

            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> <small id="user-info-stats">(%3$s)</small> — <a id="user-logout-link" href="%4$s">%5$s</a>',$user_icon,$user_text,$user_stats,$logout_link,__('Logout','pinim'));
            
        }else{ // not logged
            $user_icon = '';
            $user_text = '<strong>' . __('Not logged to Pinterest','pinim') . '</strong>';
            $login_link = pinim_get_menu_url(array('page'=>'account'));
            $content = sprintf('<span id="user-info-thumb">%1$s</span><span id="user-info-username">%2$s</span> — <a id="user-logout-link" href="%3$s">%4$s</a>',$user_icon,$user_text,$login_link,__('Login','pinim'));
        }
        
        printf('<div id="pinim-page-header-account">%s</div>',$content);

    }

    function page_pending_import(){
?>
        <div class="wrap">
            <h2><?php _e('Pins pending importation','pinim');?></h2>
            <?php settings_errors('feedback_pending_import');?>
            <form action="<?php echo pinim_get_menu_url(array('page'=>'pending-importation'));?>" method="post">
                <?php
                $this->table_pins->views_display();
                $this->table_pins->views();
                $this->table_pins->display();                            
                ?>
            </form>
        </div>
        <?php
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

    
    
    function get_requested_pins_ids(){

        
        $bulk_pins_ids = array();

        //bulk pins
        if ( isset($_POST['pinim_form_pins']) ) {

            $form_pins = $_POST['pinim_form_pins'];
            
            //remove items that are not checked
            $form_pins = array_filter(
                (array)$_POST['pinim_form_pins'],
                function ($e) {
                    return isset($e['bulk']);
                }
            ); 

            foreach((array)$form_pins as $pin){
                $bulk_pins_ids[] = $pin['id'];
            }

        }elseif ( isset($_REQUEST['pin_ids']) ) {
            $bulk_pins_ids = explode(',',$_REQUEST['pin_ids']);
        }

        if ( (!$bulk_pins_ids) && ($all_pins = pinim_tool_page()->get_queued_raw_pins()) && !is_wp_error($all_pins) ) {

            foreach((array)$all_pins as $pin){
                $bulk_pins_ids[] = $pin['id'];
            }

        }

        return $bulk_pins_ids;
    }
    
    function get_queued_raw_pins(){
        return $this->get_all_raw_pins(true);
    }

    function get_all_raw_pins($only_queued_boards = false){

        $pins = array();

        $boards = pinim_page_boards()->get_boards();
        
        if (!is_wp_error($boards)) {

            foreach ((array)$boards as $board){

                if ( !$board->raw_pins ) continue;
                if ( $only_queued_boards && !$board->in_queue ) continue;

                $pins = array_merge($pins,$board->raw_pins);

            }
            
        }

        return $pins;

    }
    
    function get_pins_count_pending(){
        $pins_ids = $this->get_requested_pins_ids();
        $pins_ids = array_diff( $pins_ids, pinim_get_processed_pins_ids() );

        return count($pins_ids);
    }
    
    

}

function pinim_tool_page() {
	return Pinim_Tool_Page::instance();
}

pinim_tool_page();