<?php

/* 
 * Used to communicate with Pinterest
 * Inspired by https://github.com/dzafel/pinterest-pinner
 */

class Pinim_Bridge{
    /**
     * Pinterest.com base URL
     */
    static $pinterest_url = 'https://www.pinterest.com';
    static $pinterest_login_url = 'https://www.pinterest.com/login';
    
    /**
     * @var Pinterest App version loaded from pinterest.com
     */
    private $_app_version = null;
    
    private $login = null;
    private $password = null;
    
    /**
     * @var CSRF token loaded from pinterest.com
     */
    private $_csrftoken = null;
    
    private $cookies = array();
    
    protected $headers = array();
    
    public $is_logged_in = false;

    public function __construct(){
        // Default HTTP headers for requests

    }
    
    /**
     * Set Pinterest account login.
     *
     * @param string $login
     */
    public function set_login($login){
        $this->login = $login;
        return $this;
    }
    /**
     * Set Pinterest account password.
     *
     * @param string $password
     */
    public function set_password($password){
        $this->password = $password;
        return $this;
    }
    
    function get_headers($headers = array()){
        $default = array(
            'Host'              => str_replace('https://', '', self::$pinterest_url),
            'Origin'            => self::$pinterest_url,
            'Referer'           => self::$pinterest_url,
            'Connection'        => 'keep-alive',
            'Pragma'            => 'no-cache',
            'Cache-Control'     => 'no-cache',
            'Accept-Language'   => 'en-US,en;q=0.5',
            'User-Agent'        => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML => like Gecko) Iron/31.0.1700.0 Chrome/31.0.1700.0' 
        );
        
        return wp_parse_args($headers,$default);
    }
    
    function get_logged_headers($headers = array()){
        
        $app_version = $this->_getAppVersion();
        
        if ( is_wp_error($app_version) ) {
            return $app_version;
        }
        
        $login_headers = array(
                'X-NEW-APP'             => 1,
                //'X-Requested-With'      => 'XMLHttpRequest',
                'Accept'                => 'application/json, text/javascript, */*; q=0.01',
                'X-APP-VERSION'         => $app_version,
                'X-CSRFToken'           => $this->_csrftoken,
                'X-Pinterest-AppState'  => 'autocache',
                'X-Requested-With'  => 'XMLHttpRequest',
        );
        
        $default = wp_parse_args(
                $this->get_headers(),
                $login_headers
        );
        
        return wp_parse_args($headers,$default);
        
    }
    
    public function maybe_decode_response($response){
        if (substr($response, 0, 1) === '{') {
            $response = json_decode($response, true);
        }
        return $response;
    }
    
    public function set_auth($response){
        
        if (is_wp_error($response)) return false;

        $this->cookies = $response['cookies'];

        foreach ((array)$this->cookies as $cookie){
            if ($cookie->name !='csrftoken') continue;
            $this->_csrftoken = $cookie->value;
        }
    }
    
    private function refresh_token($url = null){
        
        $extra_headers = array();
        
        $headers = $this->get_logged_headers($extra_headers);
        if ( is_wp_error($headers) ) return $headers;
        
        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies
        );
        
        $response = wp_remote_get( self::$pinterest_url.$url, $args );
        $this->set_auth($response); //udpate token & cookies for further requests
        return $this->_csrftoken;
    }
    
    /**
     * Try to log in to Pinterest.
     * @return \WP_Error
     */
    public function do_login(){
        
        $api_error = null;

        if ($this->is_logged_in) return $this->is_logged_in;
        
        if (!isset($this->login) or !isset($this->password)) {
            return new WP_Error('pinim',__('Missing login and/or password','pinim'));
        }
        
        $refresh_token = $this->refresh_token();

        $data = array(
            'data' => json_encode(array(
                'options' => array(
                    'username_or_email' => $this->login,
                    'password' => $this->password,
                ),
                'context' => new \stdClass,
            )),
            'source_url' => '/login/',
            'module_path' => 'App()>LoginPage()>Login()>Button(class_name=primary, text=Log In, type=submit, size=large)',
        );
        
        $url = self::$pinterest_url.'/resource/UserSessionResource/create/';

        $extra_headers = array(
            'Referer'           => self::$pinterest_login_url,
            //'Content-Type'      => 'application/x-www-form-urlencoded; charset=UTF-8'
        );

        $headers = $this->get_logged_headers($extra_headers);
        if ( is_wp_error($headers) ) return $headers;

        $args = array(
            'headers'       => $headers,
            'body'          => http_build_query($data),
            'cookies'       => $this->cookies
        );

        $response = wp_remote_post( $url, $args );
        $body = wp_remote_retrieve_body($response);

        if ( is_wp_error($body) ){
            return $body;
        }

        $this->set_auth($response); //udpate token & cookies for further requests
        $body = $this->maybe_decode_response($body);

        if (isset($body['resource_response']['error']['message']) and $body['resource_response']['error']['message']) {
            $api_error = $body['resource_response']['error']['message'];
        }elseif (!isset($body['resource_response']['data']) or !$body['resource_response']['data']) {
            $api_error = __('Unkown error while logging in','pinim');
        }
        
        if ($api_error) return new WP_Error('pinim',$api_error);

        $this->is_logged_in = true;
        return $this->is_logged_in;
    }
    
    private function extract_header_json($body){
        if (is_string($body) && $body){
            
            libxml_use_internal_errors(true);
            
            $dom = new DOMDocument;
            $dom->loadHTML($body);
            $xpath = new DOMXPath($dom);
            $js_init_node = $xpath->evaluate('//*[@id="jsInit1"][1]');
            $js_init = $js_init_node->item(0)->nodeValue;

            if ($js_init) {
                return @json_decode($js_init, true);
            }
        }
        
        return new WP_Error('pinim',__('Unable to parse the json informations.','pinim'));
    }

    /**
     * Get Pinterest App Version.
     * @return \WP_Error
     */
    private function _getAppVersion(){
        
        if ($this->_app_version) return $this->_app_version;
        
        $url = self::$pinterest_login_url;
        
        $args = array(
            'headers'       => $this->get_headers()
        );

        $response = wp_remote_get( $url, $args );
        $body = wp_remote_retrieve_body($response);

        if ( is_wp_error($body) ){
            return $body;
        }
        
        $json = $this->extract_header_json($body);
        if (is_wp_error($json)) return $json;

        if (isset($json['context']['app_version'])){
            $this->_app_version = $json['context']['app_version'];
            return $this->_app_version;
        }
        
        return new WP_Error('pinim',__('Error getting App Version.  You may have been temporary blocked by Pinterest because of too much login attemps.','pinim'));
    }
    
    /**
     * Get datas for a user.
     * if $username = 'me', get logged in user datas.
     * @return \WP_Error
     */
    public function get_user_datas($username = 'me'){

        $login = $this->do_login();
        if (is_wp_error($login)) return $login;

        $extra_headers = array(
            //'Referer'   => '/'
        );

        $headers = $this->get_logged_headers($extra_headers);
        if ( is_wp_error($headers) ) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies
        );

        $response = wp_remote_get( sprintf('%1$s/%2$s/',self::$pinterest_url,$username), $args );
        $this->set_auth($response); //udpate token & cookies
        
        $body = wp_remote_retrieve_body($response);

        if ( is_wp_error($body) ){
            return $body;
        }

        $body = $this->maybe_decode_response($body);

        if (isset($body['resource_data_cache'][0]['data'])) {
            $data = $body['resource_data_cache'][0]['data'];
            return array_filter($data);
        }

        return new WP_Error('pinim',__('Unknown error while getting user data','pinim'));
    }


    /**
     * Get boards for a username.
     * @return \WP_Error
     */
    
    public function get_user_boards($username){
        
        $login = $this->do_login();

        if (is_wp_error($login)){
            return $login;
        }
        
        $page_boards = array();

        $data_options = array(
            'field_set_key'     => 'grid_item',
            'username'          => $username,
            'sort'              => 'profile',
            //'bookmarks'         => ($bookmark) ? (array)$bookmark : null
        );

        $data = array(
            'data' => json_encode(array(
                'options' => $data_options,
                'context' => new \stdClass,
            )),
            'source_url' => sprintf('/%s/',$username),
            '_' => time()*1000 //js timestamp
        );
        
        $extra_headers = array(
            //'Referer'   => '/'
        );
        
        $headers = $this->get_logged_headers($extra_headers);
        if (is_wp_error($headers)) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies,
            'body'          => $data,
        );

        $response = wp_remote_post( self::$pinterest_url.'/resource/BoardsResource/get/', $args );
        
        $body = wp_remote_retrieve_body($response);

        if ( is_wp_error($body) ){
            return $body;
        }

        $body = $this->maybe_decode_response($body);

        if (isset($body['resource_response']['data'])){

            $page_boards = $body['resource_response']['data'];

            //remove items that have not the "board" type (like module items)
            $page_boards = array_filter(
                (array)$page_boards,
                function ($e) {
                    return $e['type'] == 'board';
                }
            );  
            return array_values($page_boards); //reset keys

        }
        
        return new WP_Error('pinim',sprintf(__("Error getting boards from user %s.  Try refreshing the page !",'pinim'),'</em>'.$username.'</em>' ) );

    }
    
    /**
     * Get all pins for a board.
     * @param type $board
     * @param type $bookmark
     * @param type $max
     * @param type $stop_at_pin_id
     * @return \WP_Error
     */

    public function get_board_pins($board, $max=0,$stop_at_pin_id=null){
        $board_page = 0;
        $board_pins = array();
        $bookmark = $board->bookmark;

        while ($bookmark != '-end-') { //end loop when bookmark "-end-" is returned by pinterest

            $query = $this->get_board_pins_page($board);
            
            if ( is_wp_error($query) ){
                
                if(empty($board_pins)){
                    $message = $query->get_error_message();
                }else{
                    $message = sprintf(__('Error getting some of the pins for board %1$s','pinim'),'<em>'.$board->get_datas('url').'</em>');
                }
                
                return new WP_Error( 'pinim', $message, array('pins'=>$board_pins,'bookmark'=>$board->bookmark) ); //return already loaded pins with error
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
        
        return array('pins'=>$board_pins,'bookmark'=>$bookmark);

    }
    
    static function get_short_url($username,$slug){
        return sprintf('/%1$s/%2$s/',$username,$slug);
    }
    
    /**
     * Validates a board url, like
     * 'https://www.pinterest.com/USERNAME/SLUG/'
     * or '/USERNAME/SLUG/'
     * @param type $url
     * @return \WP_Error
     */
    
    static function validate_board_url($url){
        preg_match("~(?:http(?:s)?://(?:www\.)?pinterest.com)?/([^/]+)/([^/]+)/?~", $url, $matches);
        
        if (!isset($matches[1]) || !isset($matches[2])){
            return new WP_Error('pinim_validate_board_url',__('This board URL is not valid','pinim'));
        }
        
        $output = array(
            'url'       => self::get_short_url($matches[1],$matches[2]),
            'url_full'  => self::$pinterest_url . self::get_short_url($matches[1],$matches[2]),
            'username'  => $matches[1],
            'slug'      => $matches[2],
        );

        return $output;
    }
    
    public function get_board_id($url){
        $board_args = self::validate_board_url($url);
        
        if (is_wp_error($board_args)) return $board_args;

        $args = array(
            'headers'       => $this->get_headers()
        );

        $response = wp_remote_get( self::$pinterest_url.$board_args['url'], $args );
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

    
    /**
     * 
     * @param type $board
     * @param type $bookmark
     * @return \WP_Error
     */
    private function get_board_pins_page($board){

        $page_pins = array();
        $data_options = array();
        $query_url = null;
        $secret = null;

        $login = $this->do_login();
        if (is_wp_error($login)) return $login;

        if ($board->slug == 'likes'){
            $query_url = self::$pinterest_url.'/resource/UserLikesResource/get/';
            $data_options = array_merge($data_options,array(
                    'username'  => $board->username
                )
            );
        }else{
            $query_url = self::$pinterest_url.'/resource/BoardFeedResource/get/';
            $data_options = array_merge($data_options,array(
                    'board_id'                  => $board->board_id,
                    'add_pin_rep_with_place'    => null,
                    'board_url'                 => $board->get_datas('url'),
                    'page_size'                 => null,
                    'prepend'                   => true,
                    'access'                    => array('write','delete'),
                    'board_layout'              => 'default',
                )
            );
            
        }

        if ($board->bookmark){ //used for pagination. Bookmark is defined when it is not the first page.
            $data_options['bookmarks'] = (array)$board->bookmark;
        }
        
        $data = array(
            'data' => json_encode(array(
                'options' => $data_options,
                'context' => new \stdClass,
            )),
            'source_url' => $board->get_datas('url'),
            '_' => time()*1000 //js timestamp
        );

        $extra_headers = array(
            //'Referer'   => '/'
            'X-Pinterest-AppState'  => 'background'
        );
        
        $headers = $this->get_logged_headers($extra_headers);
        if (is_wp_error($headers)) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies,
            'body'          => $data,
        );

        $response = wp_remote_post( $query_url, $args );        
        $body = wp_remote_retrieve_body($response);

        if ( is_wp_error($body) ){
            return $body;
        }

        $body = $this->maybe_decode_response($body);

        if (isset($body['resource_data_cache'][0]['data'])){

            $page_pins = $body['resource_data_cache'][0]['data'];

            //remove items that have not the "pin" type (like module items)
            $page_pins = array_filter(
                (array)$page_pins,
                function ($e) {
                    return $e['type'] == 'pin';
                }
            );  
            $page_pins = array_values($page_pins); //reset keys

            //bookmark (pagination)
            if (isset($body['resource']['options']['bookmarks'][0])){
                $bookmark = $body['resource']['options']['bookmarks'][0];
            }

            return array('pins'=>$page_pins,'bookmark'=>$bookmark);
            
        }

        return new WP_Error('pinim',sprintf(__('Error getting pins for board %s','pinim'),$board_args['url']));

    }
    /*
     * Converts an array to a string with keys and values
     
    private function implode_api_error($input){
        if (!is_array($input)) return $input;
        $input = array_filter($input);
        return implode('; ', array_map(function ($v, $k) { return sprintf('%s="%s"', $k, $v); }, $input, array_keys($input)));
    }
    */
}

