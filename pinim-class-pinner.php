<?php

/* 
 * Used to communicate with Pinterest
 * Based on https://github.com/dzafel/pinterest-pinner
 */

class PinIm_Pinner extends \PinterestPinner\Pinner{
    
    public $user_boards = null;
    
    public function get_all_boards_custom(){
        
        if ( empty($this->user_boards) ) {

            $bookmark = null;
            $board_page = 0;
            $boards = array();

            while ($bookmark != '-end-') { //end loop when bookmark "-end-" is returned by pinterest

                try {
                    $query = $this->get_boards_page_custom($bookmark);
                }catch (\Exception $e) {
                    throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
                }

                $bookmark = $query['bookmark'];

                if (isset($query['boards'])){

                    $page_boards = $query['boards'];

                    $boards = array_merge($boards,$page_boards);

                }

                $board_page++;

            }

            $this->user_boards = $boards;
            
        }
        
        return $this->user_boards;
        
    }


    public function get_boards_page_custom($bookmark = null){
        
        $page_boards = array();

        try {
            $user_datas = $this->getUserData();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }

        $post_data_options = array(
            'field_set_key'     => 'grid_item',
            'username'          => $user_datas['username'],
            'sort'              => 'profile'
        );
        
        if ($bookmark){ //used for pagination. Bookmark is defined when it is not the first page.
            $post_data_options['bookmarks'] = $bookmark;
        }
        
        $post_data = array(
            'data' => json_encode(array(
                'options' => $post_data_options,
                'context' => new \stdClass,
            )),
            'source_url' => '/'.$user_datas['username'].'/',
            '_' => time()*1000 //js timestamp
        );

        try {
            $this->_loadContent('/resource/BoardsResource/get/', $post_data, '/'.$user_datas['username'].'/');
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        
        if (isset($this->_response_content['resource_data_cache'][0]['data'])){
            
            $response = $this->_response_content;
            
            //boards
            if (isset($response['resource_data_cache'][0]['data'])){

                $page_boards = $response['resource_data_cache'][0]['data'];

                //remove items that have not the "pin" type (like module items)
                $page_boards = array_filter(
                    $page_boards,
                    function ($e) {
                        return $e['type'] == 'board';
                    }
                );  
                $page_boards = array_values($page_boards); //reset keys

            }

            //bookmark (pagination)
            if (!isset($response['resource']['options']['bookmarks'][0])){
                throw new PinterestPinner\PinnerException( 'get_boards_page_custom(): Missing bookmark' );
            }else{
                $bookmark = $response['resource']['options']['bookmarks'][0];
            }

            return array('boards'=>$page_boards,'bookmark'=>$bookmark);

        }

        throw new PinterestPinner\PinnerException( 'Error getting user boards.' );

    }
    
    /**
     * Get all pins for a board.
     * @param type $board - like in getUserBoards()
     * @param type $max - maximum number of pins to get
     * @param type $stop_at_pin_id - stop if that pin ID is met.  This pin ID could be saved somewhere and compared when getBoardPins is executed later.
     * @return \Exception
     */

    public function get_all_board_pins_custom($board_id,$max=0,$stop_at_pin_id=null){
        $bookmark = null;
        $board_page = 0;
        $board_pins = array();

        while ($bookmark != '-end-') { //end loop when bookmark "-end-" is returned by pinterest
            
            try {
                $query = $this->get_board_pins_page_custom($board_id,$bookmark);
            }catch (\Exception $e) {
                throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
            }

            $bookmark = $query['bookmark'];

            if (isset($query['pins'])){

                $page_pins = $query['pins'];

                //stop if this pin ID is found
                if ($stop_at_pin_id){
                    foreach($page_pins as $key=>$pin){
                        if (isset($pin['id']) && $pin['id']==$stop_at_pin_id){
                            $page_pins = array_slice($page_pins, 0, $key+1);
                            $bookmark = '-end-';
                            break;
                        }
                    }
                }
                
                $board_pins = array_merge($board_pins,$page_pins);

                //limit reached
                if ( ($max) && ( count($board_pins)> $max) ){
                    $board_pins = array_slice($board_pins, 0, $max);
                    $bookmark = '-end-';
                    break;
                }

            }

            $board_page++;
            
        }

        return $board_pins;

    }
    
    /**
     * 
     * @param array $board
     * @param type $bookmark Used to handle pagination.  If null, starts from the beginning.
     * @return array where 'pins' are the fetched pins and 'bookmark' is the token needed for pagination.
     * @throws PinnerException
     */

    private function get_board_pins_page_custom($board_id, $bookmark = null){

        $page_pins = array();
        
        try {
            $user_datas = $this->getUserData();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        
        $user_url = self::PINTEREST_URL . $user_datas['username']. '/';
        
        $post_data_options = array(
            'board_id'      => $board_id,
            //'board_url'     => $board['url'],
            'board_layout'  => 'default',
            'prepend'       => true,
            'page_size'     => null,
            'access'        => array('write','delete'),
        );

        if ($bookmark){ //used for pagination. Bookmark is defined when it is not the first page.
            $post_data_options['bookmarks'] = $bookmark;
        }
        
        $post_data = array(
            'data' => json_encode(array(
                'options' => $post_data_options,
                'context' => new \stdClass,
            )),
            'source_url' => '/',
            'module_path' => sprintf('UserProfilePage(resource=UserResource(username=%1$s, invite_code=null))>UserProfileContent(resource=UserResource(username=%1$s, invite_code=null))>UserBoards()>Grid(resource=ProfileBoardsResource(username=%1$s))>GridItems(resource=ProfileBoardsResource(username=%1$s))>Board(show_board_context=false, show_user_icon=false, view_type=boardCoverImage, component_type=1, resource=BoardResource(board_id=%2$d))',
                        $user_datas['username'],
                        $board_id
            ),
            '_' => time()*1000 //js timestamp
        );

        try {
            $this->_loadContent('/resource/BoardFeedResource/get/', $post_data, '/');
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }

        if (isset($this->_response_content['resource_data_cache'][0]['data'])){

                $response = $this->_response_content;

                //pins
                if (isset($response['resource_data_cache'][0]['data'])){

                    $page_pins = $response['resource_data_cache'][0]['data'];

                    //remove items that have not the "pin" type (like module items)
                    $page_pins = array_filter(
                        $page_pins,
                        function ($e) {
                            return $e['type'] == 'pin';
                        }
                    );  
                    $page_pins = array_values($page_pins); //reset keys

                }

                //bookmark (pagination)
                if (!isset($response['resource']['options']['bookmarks'][0])){

                    throw new PinterestPinner\PinnerException( 'get_board_pins_page_custom(): Missing bookmark' );
                }else{
                    $bookmark = $response['resource']['options']['bookmarks'][0];
                }

                return array('pins'=>$page_pins,'bookmark'=>$bookmark);
            
        }

        throw new PinterestPinner\PinnerException( 'Error getting user pins.' );

    }
    
}