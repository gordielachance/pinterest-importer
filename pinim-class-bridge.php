<?php

/* 
 * Used to communicate with Pinterest
 * Inspired by https://github.com/dzafel/pinterest-pinner
 */

class Pinim_Bridge{
    /**
     * Pinterest.com base URL
     */
    static $pinterest_url               = 'https://www.pinterest.com';
    static $pinterest_login_url         = 'https://www.pinterest.com/login';
    
    /**
     * @var Pinterest App version loaded from pinterest.com
     */
    private $_app_version = null;
    private $_csrftoken = null;
    
    private $login = null;
    private $password = null;
    
    private $cookies = array();
    protected $headers = array();

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
    
    function get_default_headers(){
        return array(
            'Host'              => str_replace('https://', '', self::$pinterest_url),
            'Origin'            => self::$pinterest_url,
            'Referer'           => self::$pinterest_url,
            'Connection'        => 'keep-alive',
            'Pragma'            => 'no-cache',
            'Cache-Control'     => 'no-cache',
            'Accept-Language'   => 'en-US,en;q=0.5',
            'User-Agent'        => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML => like Gecko) Iron/31.0.1700.0 Chrome/31.0.1700.0' 
        );
    }
    
    function get_app_headers(){

        $app_version = $this->_getAppVersion();
        if ( is_wp_error($app_version) ) return $app_version;

        $login_headers = array(
                'Accept'                => 'application/json, text/javascript, */*; q=0.01',
                'X-APP-VERSION'         => $app_version,
                'X-NEW-APP'             => 1,
                'X-Pinterest-AppState'  => 'active',
                'X-Requested-With'      => 'XMLHttpRequest',
                'X-Requested-With'      => 'XMLHttpRequest',
        );

        $login_headers = wp_parse_args(
                $this->get_default_headers(),
                $login_headers
        );
        
        return $login_headers;
    }
    
    function get_logged_headers($reset_token = false,$extra_headers = array()){

        $app_headers = $this->get_app_headers();
        if ( is_wp_error($app_headers) ) return $app_headers;

        $token = $this->get_csrftoken($reset_token);
        if ( is_wp_error($token) ) return $token;

        $logged_headers = wp_parse_args($extra_headers,$app_headers);
        $logged_headers['X-CSRFToken'] = $token;
        
        pinim()->debug_log( json_encode($logged_headers) );

        return $logged_headers;
        
    }
    
    public function maybe_decode_response($response){
        if (substr($response, 0, 1) === '{') {
            $response = json_decode($response, true);
        }
        return $response;
    }
    
    public function set_csrftoken($response){
        
        /*
        Sets the csrftoken
        */
        
        $token = null;
        
        if ( is_wp_error($response) ) return false;
        
        $this->cookies = $response['cookies'];

        foreach ((array)$this->cookies as $cookie){
            if ($cookie->name !='csrftoken') continue;
            $token = $cookie->value;
            break;
        }
        if (!$token){
            pinim()->debug_log('Missing required CSRF token');
            pinim()->debug_log($this->cookies);
            return new WP_Error('pinim',__('Missing required CSRF token','pinim'));
        }
        
        $current_token = pinim()->get_session_data('csrftoken',$token);
        
        if ($current_token != $token){
            pinim()->set_session_data('csrftoken',$token);
            pinim()->debug_log('set_csrftoken() : ' . $token);
        }

        return $token;
    }
    
    private function get_csrftoken($reset = false){
        
        if ( ( !$token = pinim()->get_session_data('csrftoken') ) || ($reset) ){

            $headers = $this->get_app_headers();
            if ( is_wp_error($headers) ) return $headers;

            $args = array(
                'headers'       => $headers,
                'cookies'       => $this->cookies
            );

            $response = wp_remote_get( self::$pinterest_url, $args );

            $token = $this->set_csrftoken($response); //udpate token & cookies for further requests

        }
        
        return $token;
        

    }
    
    /**
    Check if the email exists in the Pinterest database
    */
    public function email_exists($email){
        
        pinim()->debug_log('email_exists()');
        
        $data_options = array(
            'email' => $email
        );

        $data = array(
            'source_url' => '/',
            'data' => json_encode(array(
                'options' => $data_options,
                'context' => new \stdClass,
            )),
            '_' => time()*1000 //js timestamp
        );

        $headers = $this->get_logged_headers();
        if (is_wp_error($headers)) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies,
            'body'          => $data,
        );

        $api_response = $this->api_response('resource/EmailExistsResource/get',$args,'GET');
        return $api_response['data'];
    }
    
    public function is_logged_in(){
        return pinim()->get_session_data('is_logged_in');
    }
    
    /**
     * Try to log in to Pinterest.
     * @return \WP_Error
     */
    public function do_login(){
        
        if ( !$is_logged_in = $this->is_logged_in() ){
            
            pinim()->debug_log('do_login()');
            
            if (!isset($this->login) or !isset($this->password)) {
                return new WP_Error('pinim',__('Missing login and/or password','pinim'));
            }


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

            $extra_headers = array(
                'Referer'           => self::$pinterest_login_url,
                //'Content-Type'      => 'application/x-www-form-urlencoded; charset=UTF-8'
            );

            $headers = $this->get_logged_headers(true,$extra_headers);
            if ( is_wp_error($headers) ) return $headers;

            $args = array(
                'headers'       => $headers,
                'body'          => http_build_query($data),
                'cookies'       => $this->cookies
            );

            $api_response = $this->api_response('resource/UserSessionResource/create/',$args);

            if ( is_wp_error($api_response) ){
                return new WP_Error( 'pinim',sprintf(__('Error while trying to login: %s','pinim'),$api_response->get_error_message()) );
            }

            pinim()->debug_log('has logged in');
            $is_logged_in = true;
            pinim()->set_session_data('is_logged_in',$is_logged_in);
            
            
        }

        return $is_logged_in;

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

        if ( !$app_version = pinim()->get_session_data('app_version') ){
            
            pinim()->debug_log('_getAppVersion():');
        
            $url = self::$pinterest_login_url;

            $args = array(
                'headers'       => $this->get_default_headers()
            );

            $response = wp_remote_get( $url, $args );
            $body = wp_remote_retrieve_body($response);

            if ( is_wp_error($body) ){
                return $body;
            }

            $json = $this->extract_header_json($body);
            if (is_wp_error($json)) return $json;

            if (isset($json['context']['app_version'])){
                $app_version = $json['context']['app_version'];
                pinim()->set_session_data('app_version',$app_version);
            }
            
            pinim()->debug_log($app_version);
        }
        
        if (!$app_version){
            return new WP_Error('pinim',__('Error getting App Version.  You may have been temporary blocked by Pinterest because of too much login attemps.','pinim'));
        }else{
            $this->_app_version = $app_version;
            return $app_version;
        }
        
        
    }
    
    /**
        $path : URL to get (without the 'https://www.pinterest.com/' prefix)
        $args : arguments for the request
        $method : 'POST' or 'GET
        $update_auth : should we update the csrftoken ?
        $keys_path : path (array keys) of the datas to return from the response - default is ['resource_response']['data']
    */
    
    public function api_response( $path = null,$args,$method='POST',$keys_path=array('resource_response','data') ){
        
        $url = self::$pinterest_url . '/' . $path;
        
        $response = null;
        
        if ($method=='GET'){
            $response = wp_remote_get( $url, $args );
        }else{
            $response = wp_remote_post( $url, $args );
        }
        
        pinim()->debug_log('api_response() request for: '.$url);
        pinim()->debug_log( json_encode($args) );

        $bodyraw = wp_remote_retrieve_body($response);
        if ( is_wp_error($bodyraw) ) return $bodyraw;

        $token = $this->set_csrftoken($response); //udpate token & cookies for further requests
        if ( is_wp_error($token) ) return $token;

        $data = null;

        $body = $this->maybe_decode_response($bodyraw);
        
        pinim()->debug_log('api_response() response for: '.$url);
        pinim()->debug_log( json_encode($body) );

        //check for errors
        if ( isset($body['resource_response']['error']) && $body['resource_response']['error'] ) {
            $error = $body['resource_response']['error'];
            $error_msg = ( isset($error['message']) ) ? $error['message'] : $error['code'];
            return new WP_Error('pinim',$error_msg,$error);
            
        }

        //fetch data
        if ( $key_exists = pinim_array_keys_exists($keys_path, $body) ){
            $data = pinim_get_array_value($keys_path, $body);
        }else{
            pinim()->debug_log('Unable to get path from response : ' . implode('>',$keys_path) );
            pinim()->debug_log( json_encode($body) );
            return new WP_Error('pinim',sprintf( __('Unable to get %s from the response','pinim'),'<em>'.implode('>',$keys_path).'</em>' ) );
        }
        
        //bookmark (pagination)
        $bookmark = pinim_get_array_value(array('resource','options','bookmarks',0), $body);
        
        return array(
            'data'      => $data,
            'bookmark'  => $bookmark
        );

    }
    
    /**
     * Get datas for a user.
     * @return \WP_Error
     */
    public function get_user_datas($username){
        
            if ( !$userdata = pinim()->get_session_data('userdata') ){
                
                pinim()->debug_log('get_user_datas() for user:' . $username);

                $login = $this->do_login();
                if (is_wp_error($login)) return $login;

                $headers = $this->get_logged_headers();
                if ( is_wp_error($headers) ) return $headers;

                $args = array(
                    'headers'       => $headers,
                    'cookies'       => $this->cookies
                );

                $api_response = $this->api_response( $username,$args,'GET',array('module','tree','data') );

                if ( is_wp_error($api_response) ){
                    return new WP_Error( 'pinim',sprintf(__("Error while getting user data '%s': %s",'pinim'),$username,$api_response->get_error_message()) );
                }

                $userdata = $api_response['data'];

                pinim()->set_session_data('userdata',$userdata);
                
            }

            return $userdata;
        
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

        //TO FIX to check : do we need bookmark here ?
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

        $headers = $this->get_logged_headers(true);
        if (is_wp_error($headers)) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies,
            'body'          => $data,
        );

        $api_response = $this->api_response('resource/BoardsResource/get/',$args);
        
        if ( is_wp_error($api_response) ){
            return new WP_Error( 'pinim',sprintf(__('Error getting boards from user %s : %s.  Try refreshing the page !','pinim'),'</em>'.$username.'</em>',$api_response->get_error_message()) );
        }

        //remove items that have not the "board" type (like module items)
        $page_boards = array_filter(
            (array)$api_response['data'],
            function ($e) {
                return $e['type'] == 'board';
            }
        );  
        return array_values($page_boards); //reset keys
        
        

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

        while ($board->bookmark != '-end-') { //end loop when bookmark "-end-" is returned by pinterest

            $query = $this->get_board_pins_page($board);

            if ( is_wp_error($query) ){
                
                if(empty($board_pins)){
                    $message = $query->get_error_message();
                }else{
                    $message = sprintf(__('Error getting some of the pins for board %1$s','pinim'),'<em>'.$board->get_datas('url').'</em>');
                }
                
                return new WP_Error( 'pinim', $message, $board_pins ); //return already loaded pins with error
            }

            $board->bookmark = $query['bookmark'];

            if (isset($query['pins'])){

                $page_pins = $query['pins'];

                //stop if this pin ID is found
                if ($stop_at_pin_id){
                    foreach($page_pins as $key=>$pin){
                        if (isset($pin['id']) && $pin['id']==$stop_at_pin_id){
                            $page_pins = array_slice($page_pins, 0, $key+1);
                            $board->bookmark = '-end-';
                            break;
                        }
                    }
                }
                
                $board_pins = array_merge($board_pins,$page_pins);

                //limit reached
                if ( ($max) && ( count($board_pins)> $max) ){
                    $board_pins = array_slice($board_pins, 0, $max);
                    $board->bookmark = '-end-';
                    break;
                }

            }

            $board_page++;
            
        }

        return $board_pins;

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
            'headers'       => $this->get_default_headers()
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
        
        pinim()->debug_log('get_board_pins_page() : '. $board->slug);

        $page_pins = array();
        $data_options = array();
        $url = null;
        $secret = null;

        $login = $this->do_login();
        if (is_wp_error($login)) return $login;

        if ($board->slug == 'likes'){
            $url = 'resource/UserLikesResource/get/';
            $data_options = array_merge($data_options,array(
                    'username'  => $board->username
                )
            );
        }else{
            $url = 'resource/BoardFeedResource/get/';
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
        
        $headers = $this->get_logged_headers(true,$extra_headers);
        if (is_wp_error($headers)) return $headers;

        $args = array(
            'headers'       => $headers,
            'cookies'       => $this->cookies,
            'body'          => $data,
        );
    
        $api_response = $this->api_response($url,$args);
        
        if ( is_wp_error($api_response) ){
            return new WP_Error( 'pinim',sprintf(__('Error getting pins for board %s: %s','pinim'),'<em>'.$board->get_datas('url').'</em>',$api_response->get_error_message()) );
        }

        //remove items that have not the "pin" type (like module items)
        $page_pins = array_filter(
            (array)$api_response['data'],
            function ($e) {
                return $e['type'] == 'pin';
            }
        );  
        $page_pins = array_values($page_pins); //reset keys

        return array(
            'pins'      => $page_pins,
            'bookmark'  => $api_response['bookmark']
        );

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

