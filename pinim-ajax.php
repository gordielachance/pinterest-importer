<?php

function pinim_login_ajax(){
    
    $result = array(
            'success'   => false
    );
    $result['message'] ="yololo";
    
    /*
    
    if (!isset($_POST['post_id'])) return false;
    
    $action = $_POST['action'];
    $post_id = $_POST['post_id'];
    
    if ( $action=='pinim_post_vote_up' ){
        $nonce = 'vote-up-post_'.$post_id;
    }else if ( $action=='pinim_post_vote_down' ){
        $nonce = 'vote-down-post_'.$post_id;
    }
    
    if( ! wp_verify_nonce( $_POST['_wpnonce'], $nonce ) ) return false;
    
    if ( $action=='pinim_post_vote_up' ){
        $vote = pinim()->do_post_vote($post_id,true);
    }else if ( $action=='pinim_post_vote_down' ){
        $vote = pinim()->do_post_vote($post_id,false);
    }

    if ( !is_wp_error( $vote ) ) {
        $result['success'] = true;
        $score = pinim_get_votes_score_for_post($post_id);
        $score_display = pinim_number_format($score);
        $votes_count = pinim_get_votes_count_for_post($post_id);
        $vote_count_display = pinim_number_format($votes_count);
        $result['score_text'] = sprintf(__('Score: %1$s','pinim'),$score_display);
        $result['score_title'] = sprintf(__('Votes count: %1$s','pinim'),$vote_count_display);
    }else{
        $result['message'] = $vote->get_error_message();
    }
     */

    header('Content-type: application/json');
    echo json_encode($result);
    die();
  
}

function pinim_get_votes_log_ajax(){
    if (!isset($_POST['post_id'])) return false;
    echo pinim_get_post_votes_log( $_POST['post_id'] );
    die();
}


add_action('wp_ajax_pinim_login', 'pinim_login_ajax');
//add_action('wp_ajax_pinim_post_vote_down', 'pinim_post_vote_ajax');
//add_action('wp_ajax_pinim_get_votes_log', 'pinim_get_votes_log_ajax');

?>