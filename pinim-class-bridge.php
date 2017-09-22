<?php

class Pinim_Bridge{
    /**
     * Pinterest.com base URL
     */
    static $pinterest_url               = 'https://www.pinterest.com';
    static $pinterest_api_url           = 'https://api.pinterest.com';

    private $_csrftoken = null;
    
    private $login = null;
    private $password = null;
    
    protected $client = null; //web client
    protected $remote_response = array('headers'=>null,'body'=>null);
    
    public $isLoggedIn = false;

    public function __construct(){
    }

    /**
     * Get boards for a username.
     * @return \WP_Error
     */
    
    public function get_user_boards($username = null){
        
        if ( !$boards = pinim()->get_session_data('user_datas_boards') ){
            
            try{
                $json = pinim()->api->users->getMeBoards();
                $response = json_decode($json, true);
                $boards = $response['data'];
            } catch (Exception $e) {
                //TO FIX we should return the actual error
                return new WP_Error( 'pinim',__('Error while getting user boards','pinim') );
            }
            
            pinim()->set_session_data('user_datas_boards',$boards);
            
        }
        
        return $boards;

    }

    public function get_api_pins($board = null){
        
        $pins = array();

        $response = $this->_httpRequest(
            'API',
            '/v3/pidgets/users/' . urlencode($board->username) . '/pins/'
        );
        
        if ($response->status_code === 200) {
            $body = json_decode($response->body);
            
            if ( $pins = pinim_get_array_value(array('data','pins'), $body) ) {
                if ($board) {
                    $board_id = $board->board_id;
                    $pins = array_filter(
                        $pins,
                        function ($pin) use ($board_id) {
                            return $pin['board']['id'] == $board_id;
                        }
                    );
                }
                
            }

        }
        
        return $pins;
    }
    
    /**
     * Get all pins for a board.
     * @param type $board
     * @param type $max
     * @param type $stop_at_pin_id
     * @return \WP_Error
     */
    public function get_board_pins($board, $max=0,$stop_at_pin_id=null){

        if ( $board->is_private_board() && (!$this->isLoggedIn) ){
            return new WP_Error( 'pinim', __("Grabbing pins from a private board requires to be logged to Pinterest","pinim"), $board->board_id );
        }
        
        $board_page = 0;
        $board_pins = array();
        while ($board->bookmarks != '-end-') { //end loop when bookmarks "-end-" is returned by pinterest
            $query = $this->get_board_pins_page($board);
            if ( is_wp_error($query) ){
                if(empty($board_pins)){
                    $message = $query->get_error_message();
                }else{
                    $message = sprintf(__('Error getting some of the pins for board %1$s','pinim'),'<em>'.$board->get_datas('url').'</em>');
                }
                
                return new WP_Error( 'pinim', $message, $board_pins ); //return already loaded pins with error
            }
            $board->bookmarks = $query['bookmarks'];
            if (isset($query['pins'])){
                $page_pins = $query['pins'];
                //stop if this pin ID is found
                if ($stop_at_pin_id){
                    foreach($page_pins as $key=>$pin){
                        if (isset($pin['id']) && $pin['id']==$stop_at_pin_id){
                            $page_pins = array_slice($page_pins, 0, $key+1);
                            $board->bookmarks = '-end-';
                            break;
                        }
                    }
                }
                
                $board_pins = array_merge($board_pins,$page_pins);
                //limit reached
                if ( ($max) && ( count($board_pins)> $max) ){
                    $board_pins = array_slice($board_pins, 0, $max);
                    $board->bookmarks = '-end-';
                    break;
                }
            }
            $board_page++;
            
        }
        return $board_pins;
    }
    
    /**
     * 
     * @param type $board
     * @return \WP_Error
     */
    private function get_board_pins_page($board){
        
        pinim()->debug_log(array('slug'=>$board->slug,'bookmark'=>$board->bookmarks),'get_board_pins_page()');
                
        $page_pins = array();
        $data_options = array();
        $url = null;
        $secret = null;
        
        $options_default = array();
        if ( $board->bookmarks ){
            $options_default['bookmarks'] = (array)$board->bookmarks;
        }

        if ($board->slug == 'likes'){
            
            $options_likes = array(
                'username'          => $board->username
            );
            
            $options = array_merge($options_default,$options_likes);
            
            $query = array(
                'source_url' => sprintf('/%s/',$board->username),
                'data' => json_encode(array(
                    'options' => $options,
                    'context' => new stdClass,
                )),
                '_' => time() . '999',
            );

            $loaded = $this->loadContentAjax('/resource/UserLikesResource/get/?' . http_build_query($query), true);

        }else{
            
            $options_board = array(
                'board_id'                  => $board->board_id,
                'add_pin_rep_with_place'    => null,
                'board_url'                 => $board->get_datas('url'),
                'page_size'                 => null,
                'prepend'                   => true,
                'access'                    => array('write','delete'),
                'board_layout'              => 'default'
            );
            
            $options = array_merge($options_default,$options_board);
            
            $query = array(
                'source_url' => sprintf('/%s/%s/',$board->username,$board->slug),
                'data' => json_encode(array(
                    'options' => $options,
                    'context' => new stdClass,
                )),
                '_' => time() . '999',
            );
            
            $loaded = $this->loadContentAjax('/resource/BoardFeedResource/get/?' . http_build_query($query), true);

            
        }

        
        if ( is_wp_error($loaded) ){
            return new WP_Error( 'pinim',sprintf(__('Error getting pins for board %s: %s','pinim'),'<em>'.$board->get_datas('url').'</em>',$loaded->get_error_message()) );
        }
        
        //pins
        $page_pins = pinim_get_array_value(array('body','resource_response','data'), $this->remote_response);

        //remove items that have not the "pin" type (like module items)
        $page_pins = array_filter(
            (array)$page_pins,
            function ($e) {
                return $e['type'] == 'pin';
            }
        );  
        $page_pins = array_values($page_pins); //reset keys
        
        //bookmarks
        $bookmarks = null;
        if ( $bookmarks = pinim_get_array_value(array('body','resource','options','bookmarks'), $this->remote_response) ){
            $bookmarks = $bookmarks[0];
        }

        return array(
            'pins'      => $page_pins,
            'bookmarks' => $bookmarks
        );

    }
    
    public function get_board_id($url){
        $board_url = pinim_validate_board_url($url);
        
        if (is_wp_error($board_url)) return $board_url;

        $args = array(
            'headers'       => $this->_get_default_headers()
        );

        $response = wp_remote_get( $board_url, $args );
        $body = wp_remote_retrieve_body($response);
        
        if ( is_wp_error($body) ){
            return $body;
        }

        $json = $this->extract_header_json($body);
        if (is_wp_error($json)) return $json;
        
        if (isset($json['resourceDataCache']['0']['data']['id'])){
            $board_id = $json['resourceDataCache']['0']['data']['id'];
            return $board_id;

        }
        return new WP_Error('pinim',__('Error getting Board ID.','pinim'));
    }

}