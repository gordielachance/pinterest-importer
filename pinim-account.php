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
        add_action( 'admin_menu',array( $this,'admin_menu' ),9,2);
        add_action( 'current_screen', array( $this, 'page_account_init') );
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
    
    //TO FIX TO CHECK still is required ?
    function page_account_init(){
        
        if ( isset($_REQUEST['logout']) ){
            pinim()->destroy_session();
            add_settings_error('feedback_login', 'clear_cache', __( 'You have logged out, and the plugin cache has been cleared', 'pinim' ), 'updated inline');
            return;
        }
        
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_account') return;
        
        if ( isset($_POST['pinim_form_login']) ){

            $token = ( isset($_POST['pinim_form_login']['token']) ? $_POST['pinim_form_login']['token'] : null);

            $logged = $this->save_user_token($token);

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
    
    /*
    Validate and store access token
    */
    function save_user_token($token){
        
        $user_id = get_current_user_id();
        
        pinim()->api->auth->setOAuthToken($token);
        $userdatas = $this->get_user_datas();

        if ( is_wp_error($userdatas) ){
            
            delete_user_meta($user_id,pinim()->user_token_metaname);
            return $userdatas;
            
        }else{
            
            update_user_meta($user_id,pinim()->user_token_metaname,$token);
            return true;
            
        }

    }
    
    function get_my_username(){
        if ( !$username = pinim()->get_session_data('user_datas','me','username') ) {
            return new WP_Error('Missing username in user data.','pinim');
        }
        return $username;
    }
    
    /**
     * Get datas for a user.
     * @return \WP_Error
     */
    public function get_user_datas($keys = null,$username = 'me'){
        
        $me_username = $this->get_my_username();
        if ($username == $me_username) $username = 'me';

        if ( !$userdatas = pinim()->get_session_data(array('user_datas',$username)) ){
            if ($username == 'me'){ //me
                try{
                    $json = pinim()->api->users->me(array('fields' => 'username,first_name,last_name,image[large],counts'));
                    $userdatas = json_decode($json, true);
                }catch (Exception $e) {
                    //TO FIX we should return the actual error
                    //https://github.com/dirkgroenen/Pinterest-API-PHP/issues/68
                    return new WP_Error( 'pinim',__('Error while getting user datas','pinim') );
                }
            }else{
                echo("get_user_datas() [NOT ME] WIP");
            }
            
            if ($userdatas){
                $all_userdatas = pinim()->get_session_data('user_datas');
                $all_userdatas[$username] = $userdatas;
                pinim()->set_session_data('user_datas',$all_userdatas);
            }

        }

        return pinim_get_array_value($keys, $userdatas);
        
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
                    <!--
                    <div id="pinim-app-login">
                        <?php
                        $boards_url = pinim_get_menu_url(array('page'=>'boards'));
                        $login_url = pinim()->api->auth->getLoginUrl($boards_url, array('read_public'));
                        printf('<a class="button button-primary" href="%s">%s</a>',$login_url,__('Authorize Pinterest','pinim'));
                        ?>
                    </div>
                    -->
                    <div id="pinim-token-login">
                        <?php $this->token_field_callback();?>
                        <?php submit_button(__('Login with a token','pinim'));?>
                    </div>
                    <?php
        
                    ?>
                </div>
            </form>
        </div>
        <?php
    }
    
    function pinim_form_login_desc(){

        $link_token = sprintf('<a href="https://developers.pinterest.com/tools/access_token" target="_blank">%s</a>',__('Generate a access token','pinim'));
        $desc_token = sprintf(__('%s on Pinterest and copy/paste it here.','pinim'),$link_token);
        printf('<p class="description">%s</p>',$desc_token);
        
    }
    
    function token_field_callback(){
        
        $user_id = get_current_user_id();
        
        $option = get_user_meta($user_id,pinim()->user_token_metaname,true);
        
        $el_id = 'pinim_user_token';
        $el_txt = __('Token','pinim');
        $input = sprintf(
            '<input type="text" id="%s" name="%s[token]" value="%s"/>',
            $el_id,
            'pinim_form_login',
            $option
        );
        
        printf('<p><label for="%s">%s</label>%s</p>',$el_id,$el_txt,$input);
        
    }
}

function pinim_account() {
	return Pinim_Account::instance();
}

pinim_account();