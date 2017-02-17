<?php

class Pinim_Account {
    function __construct(){
        add_action( 'admin_menu',array( $this,'admin_menu' ),9,2);
        add_action( 'current_screen', array( $this, 'page_account_init') );
    }
    
    function admin_menu(){
        pinim()->page_account = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pinterest Account','pinim'), 
            __('Pinterest Account','pinim'), 
            'manage_options', //TO FIX
            'account', 
            array($this, 'page_account')
        );
    }
    
    function form_do_login($login=null,$password=null){

        //try to auth
        $logged = pinim()->do_bridge_login($login,$password);
        if ( is_wp_error($logged) ) return $logged;
        
        //store login / password
        pinim()->set_session_data('login',$login);
        pinim()->set_session_data('password',$password);

        //try to get user datas
        $user_datas = pinim()->get_user_infos();
        if (is_wp_error($user_datas)) return $user_datas;

        return true;
        
    }
    
    function page_account_init(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_account') return;
        
        if ( isset($_REQUEST['logout']) ){
            pinim()->destroy_session();
            add_settings_error('feedback_login', 'clear_cache', __( 'You have logged out, and the plugin cache has been cleared', 'pinim' ), 'updated inline');
        }elseif ( isset($_POST['pinim_form_login']) ){

            $login = ( isset($_POST['pinim_form_login']['username']) ? $_POST['pinim_form_login']['username'] : null);
            $password = ( isset($_POST['pinim_form_login']['password']) ? $_POST['pinim_form_login']['password'] : null);

            $logged = $this->form_do_login($login,$password);

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
                    <?php submit_button(__('Login to Pinterest','pinim'));?>
                </div>
            </form>
        </div>
        <?php
    }
    
    function pinim_form_login_desc(){
        $session_cache = session_cache_expire();
        echo '<p class="description">'.sprintf(__('Your login, password and datas retrieved from Pinterest will be stored for %1$s minutes in a PHP session. It is not stored in the database.','pinim'),$session_cache)."</p>";
    }

    function login_field_callback(){
        $option = pinim()->get_session_data('login');
        $disabled = disabled( (bool)$option , true, false);;
        $el_id = 'pinim_form_login_username';
        $el_txt = __('Username');
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
        $option = pinim()->get_session_data('password');
        $disabled = disabled( (bool)$option, true, false);
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
    
    
}

new Pinim_Account;