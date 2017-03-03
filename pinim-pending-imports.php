<?php
    
class Pinim_Pending_Imports {
    var $all_action_str = array(); //text on all pins | boards actions
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Pending_Imports;
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

    }
    
    function admin_menu(){
        
        $pending_menu_title = __('Pending importation','pinim');
        
        //TO FIX show number of pending pins
        //takes too much resources ?
        /*
        if( $pending_count = $this->get_pins_count_pending() ){
            $pending_menu_title.=__sprintf('<span class="$pending_menu_title">%s</span>',$pending_count);
        }
        */

        pinim()->page_pending_imports = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pending importation','pinim'), //page title
            $pending_menu_title, //menu title
            pinim_get_pin_capability(), //capability required
            'pending-importation', 
            array($this, 'page_pending_import')
        );

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
                        if ( in_array( $pin_id,pinim()->processed_pins_ids ) ){
                            $skip_pin_import[] = $pin_id;
                            continue;
                        }

                        //save pin
                        $pin = new Pinim_Pin_Item($pin_id);
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
        
        if ( !pinim_pending_imports()->get_pins_count_pending() ){
            $boards_url = pinim_get_menu_url(array('page'=>'boards'));
            add_settings_error('feedback_pending_import','not_logged',sprintf(__('To list the pins you can import here, you first need to <a href="%s">cache some Pinterest Boards</a>.','pinim'),$boards_url),'error inline');
        }

        if ($pins_ids = $this->get_requested_pins_ids()){
            $pins_ids = array_diff( $pins_ids, pinim()->processed_pins_ids );

            //populate pins
            foreach ((array)$pins_ids as $pin_id){
                $pins[] = new Pinim_Pin_Item($pin_id);
            }

            $this->table_pins->input_data = $pins;
            $this->table_pins->prepare_items();
        }
        
        
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

        if ( (!$bulk_pins_ids) && ($all_pins = pinim_pending_imports()->get_queued_raw_pins()) && !is_wp_error($all_pins) ) {

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

        $boards = pinim_boards()->get_boards();
        
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
        $pins_ids = array_diff( $pins_ids, pinim()->processed_pins_ids );

        return count($pins_ids);
    }

}

function pinim_pending_imports() {
	return Pinim_Pending_Imports::instance();
}
pinim_pending_imports();