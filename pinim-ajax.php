<?php

function pinim_boards_import_pins_ajax(){
    
    pinim()->start_session();
    
    $result = array(
            'success'   => false
    );
    $board_id = 153474368490569656;
    $board = new Pinim_Board($board_id);
    $board_pins = $board->get_pins();
                            
    if (is_wp_error($board_pins)){
        $result['message'] = $board_pins->get_error_message();
        $result['session'] = $_SESSION;
    }else{
        $result['boards'][$board_id]['pins'] = $board_pins;
        $result['success'] = true;
    }

    header('Content-type: application/json');
    echo json_encode($result);
    die();
}

add_action('wp_ajax_boards_import_pins', 'pinim_boards_import_pins_ajax');
