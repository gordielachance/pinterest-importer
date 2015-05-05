<?php



function pinim_is_tool_page(){

    //TO FIX should also allow options.php

    global $pagenow;

    switch($pagenow){
        case 'tools.php':
            if ( isset($_REQUEST['page']) && ($_REQUEST['page'] == 'pinim') ) return true;
        break;
        case 'options.php':
            if ( isset($_REQUEST['option_page']) && ($_REQUEST['option_page'] == 'pinim') ) return true;
        break;
    }

    return false;
}

function pinim_get_tool_page_step(){
    //if (!pinim_is_tool_page()) return false; //TO FIX do not work if page is options !

    if (!isset($_REQUEST['step'])) return false;
    return $_REQUEST['step'];
}

function pinim_get_tool_page_url($args = array()){
    
    $defaults = array(
        'page'=>'pinim'
    );

    $args = wp_parse_args($args, $defaults);
     
    //url encode
    $args = array_combine(
            array_map( 'rawurlencode', array_keys( $args ) ),
            array_map( 'rawurlencode', array_values( $args ) )
    );

    return add_query_arg($args,admin_url('tools.php'));

}

function pinim_get_posts(){
    $query_args = array(
        'post_status'   => array('publish','pending','draft','future','private'),
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-pin_id',
            )
        ),
        'posts_per_page' => -1
    );
    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts;
}

/**
 * Checks if a pin ID already has been imported
 * @param type $pin_id
 * @return boolean
 */

function pinim_get_posts_by_board_id($board_id){
    $query_args = array(
        'post_status'   => array('publish','pending','draft','future','private'),
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-board_id',
                'value'   => $board_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => -1
    );
    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts;
}

/**
 * Checks if a pin ID already has been imported
 * @param type $pin_id
 * @return boolean
 */

function pinim_get_post_by_pin_id($pin_id){
    $query_args = array(
        'post_status'   => array('publish','pending','draft','future','private'),
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-pin_id',
                'value'   => $pin_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts[0]->ID;
}

/**
 * Checks if a featured pin image already has been imported (eg. If we have two pins with the same image)
 * @param type $img_url
 * @return boolean
 */

function pinim_image_exists($img_url){
    $query_args = array(
        'post_type'         => 'attachment',
        'post_status'       => 'inherit',
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-image-url',
                'value'   => $img_url,
                'compare' => '='
            )
        ),
        'posts_per_page'    => 1
    );

    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts[0]->ID;
}



/**
 * Get a Pinterest pin url from its ID
 * @param type $pin_id
 * @return type
 */

function pinim_get_pin_url($pin_id){
    $pin_url = sprintf('https://www.pinterest.com/pin/%s',$pin_id);
    return $pin_url;
}

/**
 * Get a Pinterest user url from its username
 * @param type $username
 * @return type
 */

function pinim_get_user_url($username){
    $user_url = sprintf('https://www.pinterest.com/%s',$username);
    return $user_url;
}

/**
 * Get a Pinterest board url from its username & board slug
 * @param type $username
 * @param type $board_slug
 * @return type
 */

function pinim_get_board_url($username,$board_slug){
    $board_url = sprintf('https://www.pinterest.com/%1s/%2s',$username,$board_slug);
    return $board_url;
}


/**
 * Get a single pinterest meta (if key is defined) or all the pinterest metas for a post ID
 * @param type $key (optional)
 * @param type $post_id
 * @return type
 */

function pinim_get_pin_meta($key = false, $post_id = false, $single = false){
    $pin_metas = array();
    $prefix = '_pinterest-';
    $metas = get_post_custom($post_id);

    foreach((array)$metas as $meta_key=>$meta){
        $splitkey = explode($prefix,$meta_key);
        if (!isset($splitkey[1])) continue;
        $pin_key = $splitkey[1];
        $pin_metas[$pin_key] = $meta;

    }
    
    if ($key){
        $pin_metas = $pin_metas[$key];
    }

    if($single){
        return $pin_metas[0];
    }else{
        return $pin_metas;
    }


}

function pinim_get_boards_options(){

    //TO FIX TO RESTORE if (!pinim()->user_boards_options){
        pinim()->user_boards_options = get_user_meta( get_current_user_id(), 'pinim_boards_settings', true);
    //}

    $boards_settings = pinim()->user_boards_options;

    //remove duplicates
    $boards_settings = array_filter(
        $boards_settings,
            function ( $board ) {
              static $idlist = array();

              if ( in_array( $board['id'], $idlist ) ){
                  return false;
              }

              $idlist[] = $board['id'];
              return true;    
            }
    );
    $boards_settings = array_filter($boards_settings); //reset keys

    return $boards_settings;
}

function pinim_get_classes($classes){
    if (empty($classes)) return;
    return' class="'.implode(' ',$classes).'"';
}

function pinim_get_root_category_id(){
    if (!$category_id = pinim()->get_options('category_root_id')){
        if ($root_term = pinim_get_term_id(pinim()->root_term_name,'category')){
            return $root_term['term_id'];
        }
    }
    return false;
}





?>
