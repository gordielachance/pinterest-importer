<?php

class Pinim_Settings {
    function __construct(){
        add_action( 'admin_init', array( $this, 'settings_init' ) );
        add_action( 'admin_menu',array( $this,'admin_menu' ),20,2);
    }
    
    function admin_menu(){
        pinim()->page_settings = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Settings','pinim'), 
            __('Settings','pinim'), 
            'manage_options',
            'settings', 
            array($this, 'page_settings')
        );
    }
    
    
    function settings_sanitize( $input ){
        $new_input = array();

        if( isset( $input['reset_options'] ) ){
            
            $new_input = pinim()->options_default;
            
        }else{ //sanitize values
            
            //delete boards settings
            if ( isset($input['delete_boards_settings']) ){
                delete_user_meta( get_current_user_id(), 'pinim_boards_settings');
            }

            //boards per page
            if ( isset ($input['boards_per_page']) && ctype_digit($input['boards_per_page']) ){
                $new_input['boards_per_page'] = $input['boards_per_page'];
            }
            
            //pins per page
            if ( isset ($input['pins_per_page']) && ctype_digit($input['pins_per_page']) ){
                $new_input['pins_per_page'] = $input['pins_per_page'];
            }
            
            //default post status
            if ( isset ($input['default_status']) ){
                $stati = Pinim_Pin_Item::get_allowed_stati();
                $stati_keys = array_keys($stati);
                if (in_array($input['default_status'],$stati_keys)){
                    $new_input['default_status'] = $input['default_status'];
                }
            }
            
            //autocache
            $new_input['can_autocache']  = isset ($input['can_autocache']) ? 'on' : 'off';

            //auto private
            $new_input['can_autoprivate']  = isset ($input['can_autoprivate']) ? 'on' : 'off';

        }
        
        //remove default values
        foreach($input as $slug => $value){
            $default = pinim()->get_default_option($slug);
            if ($value == $default) unset ($input[$slug]);
        }

        $new_input = array_filter($new_input);

        return $new_input;
        
        
    }

    function settings_init(){

        register_setting(
            'pinim_option_group', // Option group
            PinIm::$meta_name_options, // Option name
            array( $this, 'settings_sanitize' ) // Sanitize
         );
        
        add_settings_section(
            'settings_general', // ID
            __('General','pinim'), // Title
            array( $this, 'pinim_settings_general_desc' ), // Callback
            'pinim-settings-page' // Page
        );

        add_settings_field(
            'boards_per_page', 
            __('Boards per page','pinim'), 
            array( $this, 'boards_per_page_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_general' //section
        );
        
        add_settings_field(
            'pins_per_page', 
            __('Pins per page','pinim'), 
            array( $this, 'pins_per_page_field_callback' ), 
            'pinim-settings-page', // Page
            'settings_general' //section
        );
        
        add_settings_field(
            'can_autocache', 
            __('Auto Cache','pinim'), 
            array( $this, 'can_autocache_callback' ), 
            'pinim-settings-page', // Page
            'settings_general'//section
        );
        
        add_settings_section(
            'settings_import', // ID
            __('Import','pinim'), // Title
            array( $this, 'pinim_settings_import_desc' ), // Callback
            'pinim-settings-page' // Page
        );
        
        add_settings_field(
            'default_status', 
            __('Defaut post status','pinim'), 
            array( $this, 'default_status_callback' ), 
            'pinim-settings-page', // Page
            'settings_import'//section
        );
        
        add_settings_field(
            'can_autoprivate', 
            __('Auto private status','pinim'), 
            array( $this, 'can_autoprivate_callback' ), 
            'pinim-settings-page', // Page
            'settings_import'//section
        );
        
        add_settings_section(
            'settings_system', // ID
            __('System','pinim'), // Title
            array( $this, 'pinim_settings_system_desc' ), // Callback
            'pinim-settings-page' // Page
        );
        
        add_settings_field(
            'reset_options', 
            __('Reset Options','pinim'), 
            array( $this, 'reset_options_callback' ), 
            'pinim-settings-page', // Page
            'settings_system'//section
        );
        
        if ( pinim_get_boards_options() ){
            add_settings_field(
                'delete_boards_settings', 
                __('Delete boards preferences','pinim'), 
                array( $this, 'delete_boards_settings_callback' ), 
                'pinim-settings-page', // Page
                'settings_system'//section
            );
        }

    }
    
function page_settings(){
        ?>
        <div class="wrap">
            <h2><?php _e('Pinterest Importer Settings','pinim');?></h2>
            <form method="post" action="options.php">
                <?php

                // This prints out all hidden setting fields
                settings_fields( 'pinim_option_group' );   
                do_settings_sections( 'pinim-settings-page' );
                submit_button();

                ?>
            </form>
        </div>
        <?php
    }
    
    function pinim_settings_general_desc(){
        
    }
    
    function can_autocache_callback(){
        
        $option = pinim()->get_options('can_autocache');
        $warning = '<i class="fa fa-exclamation-triangle" aria-hidden="true"></i> '.__("Auto-caching too many boards, or boards with a large amount of pins will slow the plugin, because we need to query informations for each pin of each board.","pinim");
        
        printf(
            '<input type="checkbox" name="%1$s[can_autocache]" value="on" %2$s/> %3$s<br/><p><small>%4$s</small></p>',
            PinIm::$meta_name_options,
            checked( $option, 'on', false ),
            __("Automatically cache displayed active boards.","pinim"),
            $warning
        );
    }
    
    function pinim_settings_import_desc(){
        
    }
    
    function default_status_callback(){
        $option = pinim()->get_options('default_status');
        $stati = Pinim_Pin_Item::get_allowed_stati();

        $select_options = array();

        foreach ((array)$stati as $slug=>$status){
            $selected = selected( $option, $slug, false);
            $select_options[] = sprintf('<option value="%1$s" %2$s>%3$s</option>',$slug,$selected,$status);
        }

        printf(
            '<select name="%1$s[default_status]">%2$s</select>',
            PinIm::$meta_name_options,
            implode('',$select_options)
        );
    }
    
    function can_autoprivate_callback(){
        $option = pinim()->get_options('can_autoprivate');

        printf(
            '<input type="checkbox" name="%1$s[can_autoprivate]" value="on" %2$s/> %3$s<br/>',
            PinIm::$meta_name_options,
            checked( $option, "on", false ),
            __("Set post status to private if the pin's board is secret.","pinim")
        );
    }
    
    function pinim_settings_system_desc(){
        
    }
    
    function reset_options_callback(){
        printf(
            '<input type="checkbox" name="%1$s[reset_options]" value="on"/> %2$s',
            PinIm::$meta_name_options,
            __("Reset options to their default values.","pinim")
        );
    }
    
    function delete_boards_settings_callback(){
        printf(
            '<input type="checkbox" name="%1$s[delete_boards_settings]" value="on"/> %2$s',
            PinIm::$meta_name_options,
            __("Delete the boards preferences for the current user","pinim")
        );
    }
    
    function boards_per_page_field_callback(){
        $option = (int)pinim()->get_options('boards_per_page');
        
        printf(
            '<input type="number" name="%1$s[boards_per_page]" size="3" value="%2$s" /> %3$s',
            PinIm::$meta_name_options,
            $option,
            '<small>'.__("0 = display all boards.","pinim").'</small>'
        );
        
    }
    
    function pins_per_page_field_callback(){
        $option = (int)pinim()->get_options('pins_per_page');

        printf(
            '<input type="number" name="%1$s[pins_per_page]" size="3" min="10" value="%2$s" /><br/>',
            PinIm::$meta_name_options,
            $option
        );
        
    }
    
}

new Pinim_Settings;