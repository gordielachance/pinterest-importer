<?php

/* 
 * Used to communicate with Pinterest
 * Based on https://github.com/dzafel/pinterest-pinner
 */

class PinIm_Pinner extends \PinterestPinner\Pinner{
    
    public $user_boards = null;
    
    public function hasUserBoards_NonApi(){
        try {
            $boards_count = $this->countUserBoards_NonApi();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        return (bool)$boards_count;
    }
    
    public function countUserBoards_NonApi(){
        try {
            $user_datas = $this->getUserData();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        
        $count = 0;

        if ( isset($user_datas['board_count']) ) $count += $user_datas['board_count'];
        if ( isset($user_datas['secret_board_count']) ) $count += $user_datas['secret_board_count'];

        return $count;
    }
    
    //this function may / should be improved;
    //or replaced by some API thing.
    protected function getBoardUrl($board_id){
        
    }
    
    /*
     * 
     */
    
    public function getUserBoards_NonApi(){
        
        if ( !empty($this->user_boards) ) {
                return $this->user_boards;
        }

        try {
            $has_boards = $this->hasUserBoards_NonApi();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        
        if (!$has_boards) return $this->user_boards;

        try {
            $user_datas = $this->getUserData();
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }

        $post_data = array(
            'data' => json_encode(array(
                'options' => array(
                    'field_set_key' => 'grid_item',
                    'username' => $user_datas['username'],
                ),
                'context' => new \stdClass,
            )),
            'source_url' => '/'.$user_datas['username'].'/',
            '_' => time()*1000 //js timestamp
        );

        try {
            $this->_loadContent('/resource/ProfileBoardsResource/get/', $post_data, '/'.$user_datas['username'].'/');
        } catch (\Exception $e) {
            throw new PinterestPinner\PinnerException($e->getMessage(), null, $e);
        }
        
        if (isset($this->_response_content['resource_data_cache'][0]['data'])){
            
            $boards = $this->_response_content['resource_data_cache'][0]['data'];

            //remove items that have not the "board" type (like module items)
            $boards = array_filter(
                $boards,
                function ($e) {
                    return $e['type'] == 'board';
                }
            );  
            $boards = array_values($boards); //reset keys
            
            $this->user_boards = $boards;
            return $this->user_boards;
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

    public function getBoardPins_NonApi($board_id,$max=0,$stop_at_pin_id=null){
        $bookmark = null;
        $board_page = 0;
        $board_pins = array();

        while ($bookmark != '-end-') { //end loop when bookmark "-end-" is returned by pinterest
            
            try {
                $query = $this->getPageBoardPins_NonApi($board_id,$bookmark);
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

    private function getPageBoardPins_NonApi($board_id, $bookmark = null){

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
            'bookmarks'     => (array)$bookmark
        );

        if ($bookmark){ //used for pagination. Bookmark is defined when it is not the first page.
            $post_data_options['data']['options']['bookmarks'] = $bookmark;
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

                    throw new PinterestPinner\PinnerException( 'getPageBoardPins(): Missing bookmark' );
                }else{
                    $bookmark = $response['resource']['options']['bookmarks'][0];
                }

                return array('pins'=>$page_pins,'bookmark'=>$bookmark);
            
        }

        throw new PinterestPinner\PinnerException( 'Error getting user boards.' );

    }
    
}