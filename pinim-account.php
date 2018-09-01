<?php

class Pinim_Account {
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Account;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    function init(){
        
        //auth session
        add_action( 'current_screen', array( $this, 'register_session' ), 1);
        
        add_action( 'admin_menu',array( $this,'admin_menu' ),9,2);
        add_action( 'current_screen', array( $this, 'maybe_logout'), 9 );
        add_action( 'current_screen', array( $this, 'page_account_init') );
        add_action('wp_logout', array( $this, 'destroy_usercache' ) );
        add_action('wp_login', array( $this, 'destroy_usercache' ) );
    }
    
    function admin_menu(){
        pinim()->page_account = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pinterest Account','pinim'), 
            __('Pinterest Account','pinim'), 
            pinim_get_pin_capability(), //capability required
            'account', 
            array($this, 'page_account')
        );
    }
    
    function maybe_logout(){
        //want to logout
        if ( !isset($_REQUEST['do_logout']) ) return;
        
        $this->destroy_usercache();
        $logged_out_link = pinim_get_menu_url(array('page'=>'account','did_logout'=>true));
        wp_redirect( $logged_out_link );
        die();
    }
    
    function page_account_init(){
        
        //has logout
        if ( isset($_REQUEST['did_logout']) ){
            add_settings_error('feedback_login', 'clear_cache', __( 'You have logged out, and the plugin cache has been cleared', 'pinim' ), 'updated inline');
        }
        
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_account') return;
        
        //send auth form
        if ( isset($_POST['pinim_form_login']) ){

            $login = ( isset($_POST['pinim_form_login']['username']) ? trim($_POST['pinim_form_login']['username']) : null);
            $password = ( isset($_POST['pinim_form_login']['password']) ? trim($_POST['pinim_form_login']['password']) : null);
            
            //store login & password in session for further authentification
            if ($login && $password){
                $this->set_session_data('login',$login);
                $this->set_session_data('password',$password);
            }

            //auth to pinterest
            $logged = $this->do_pinterest_auth();

            if (is_wp_error($logged)){
                add_settings_error('feedback_login', 'do_login', $logged->get_error_message(),'inline' );
                return;
            }

            //redirect to next step
            $args = array(
                'page'=>    'boards'
            );

            $url = pinim_get_menu_url($args);
            wp_redirect( $url );
            die();

            
        }
        
    }

    function page_account(){
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Account','pinim');?></h2>
            <?php
            //check sessions are enabled
            if (!session_id()){
                add_settings_error('feedback_login', 'no_sessions', __("It seems that your host doesn't support PHP sessions.  This plugin will not work properly.  We'll try to fix this soon.","pinim"),'inline');
            }

            $this->pinim_form_login_desc();
        
            ?>
            <form id="pinim-form-login" action="<?php echo pinim_get_menu_url(array('page'=>'account'));?>" method="post">
                <div id="pinim_login_box">
                    <p id="pinim_login_icon"><i class="fa fa-pinterest" aria-hidden="true"></i></p>
                    <?php settings_errors('feedback_login');?>
                    <?php $this->login_field_callback();?>
                    <?php $this->password_field_callback();?>
                    <?php 
                    if ( $this->has_credentials() ) { //session exists
                        $logout_url = pinim_get_menu_url(array('page'=>'account','do_logout'=>true));

                        $content = printf('<a class="button" href="%s">%s</a>',$logout_url,__('Logout','pinim'));
                    }else{
                        submit_button(__('Login to Pinterest','pinim'));
                    }
                    ?>
                </div>
            </form>
        </div>
        <?php
    }
    
    function pinim_form_login_desc(){
        $session_cache = session_cache_expire();
        echo '<p class="description">'.sprintf(__('Your login and password will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p>";
    }

    function login_field_callback(){
        $option = $this->get_session_data('login');
        $disabled = disabled( (bool)$this->has_credentials(), true, false);
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Email');
        $input = sprintf(
            '<input type="text" id="%1$s" name="%2$s[username]" value="%3$s"%4$s/>',
            $el_id,
            'pinim_form_login',
            $option,
            $disabled
        );
        
        printf('<p><label for="%1$s">%2$s</label>%3$s</p>',$el_id,$el_txt,$input);
        
    }
    
    function password_field_callback(){
        $option = $this->get_session_data('password');
        $disabled = disabled( (bool)$this->has_credentials() , true, false);
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Password');
        
        $input = sprintf(
            '<input type="password" id="%1$s" name="%2$s[password]" value="%3$s"%4$s/>',
            $el_id,
            'pinim_form_login',
            $option,
            $disabled
        );
        
        printf('<p><label for="%1$s">%2$s</label>%3$s</p>',$el_id,$el_txt,$input);
    }

    /**
    Login to pinterest
    **/
    function do_pinterest_auth(){

        if ( !$logged = pinim()->bot->auth->isLoggedIn() ){
            
            $login = $this->get_session_data('login');
            $password = $this->get_session_data('password');

            if (!$login || !$password){
                return new WP_Error( 'pinim',__('Missing login and/or password','pinim') );
            }

            //try to auth
            $result = pinim()->bot->auth->login($login,$password);

            if (!$result) {
                return new WP_Error( 'php-pinterest-bot',pinim()->bot->getLastError() );
            }
            
        }

        return $logged;

    }
    
    function get_user_profile(){ //TOUFIX cache ?

        $user_profile = get_user_meta( get_current_user_id(),pinim()->usermeta_profile,true );

        
        if ( !$user_profile ){

            //auth to pinterest
            $this->do_pinterest_auth();
            
            if ( $logged = pinim()->bot->auth->isLoggedIn() ){
                $user_profile = pinim()->bot->user->profile();
                $success = update_user_meta( get_current_user_id(),pinim()->usermeta_profile, $user_profile );
            }else{
                return new WP_Error( 'php-pinterest-bot',pinim()->bot->getLastError() );
            }
        }
        
        return $user_profile;
    }
    
    function destroy_usercache(){
        $this->debug_log('destroy_usercache');

        //force update profile & user boards by deleting the user metas
        delete_user_meta( get_current_user_id(), $this->usermeta_profile );
        delete_user_meta( get_current_user_id(), $this->usermeta_boards );
        delete_user_meta( get_current_user_id(), $this->usermeta_followed_boards );
        
        $this->delete_session_data();
    }
    
    /**
     * AUTH SESSION 
     So we can store the auth data (we don't want to store it in the database because of security reasons)
     */
    function register_session(){
        $screen = get_current_screen();
        if ( $screen->post_type != pinim()->pin_post_type ) return;
        if( !session_id() ) session_start();
    }
    
    private function get_session_data($keys = null){
        
        if (!isset($_SESSION['pinim'])) return null;
        $session = $_SESSION['pinim'];
        
        return pinim_get_array_value($keys, $session);

    }
    
    //Would be better to use transients here, but that would mean that we would store pwd in db.
    private function set_session_data($key,$data){
        $_SESSION['pinim'][$key] = $data;
        return true;
    }
    
    private function delete_session_data($key = null){
        if (!isset($_SESSION['pinim'])) return false;
        
        if ($key){
            if (!isset($_SESSION['pinim'][$key])) return false;
            unset($_SESSION['pinim'][$key]);
            return;
        }
        unset($_SESSION['pinim']);
    }
    
    function has_credentials(){
        $has_login = $this->get_session_data('login');
        $has_pwd = $this->get_session_data('password');
        return ($has_login && $has_pwd);
    }

}

function pinim_account() {
	return Pinim_Account::instance();
}

pinim_account();