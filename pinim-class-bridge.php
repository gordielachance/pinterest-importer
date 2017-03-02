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
    static $pinterest_api_url           = 'https://api.pinterest.com';

    private $_csrftoken = null;
    
    private $login = null;
    private $password = null;
    
    protected $client = null; //web client
    protected $remote_response = array('headers'=>null,'body'=>null);

    public function __construct(){
        // use Requests_Session here because it does maintain a cookie session
        
        $config = array(
            'url'       => null,
            'headers'   => $this->_get_default_headers(),
            'data'      => array(),
            'options'    => array(
                'cookies'    => new \Requests_Cookie_Jar(),
                'verify'    => false //SSL verify
            )
        );

        $this->client = new Requests_Session($config['url'],$config['headers'],$config['data'],$config['options']);
    }
    
    /**
     * Set Pinterest account login.
     *
     * @param string $login
     */
    public function set_login($login){
        $this->login = $login;
        pinim()->set_session_data('login',$login);
        return $this;
    }
    /**
     * Set Pinterest account password.
     *
     * @param string $password
     */
    public function set_password($password){
        $this->password = $password;
        pinim()->set_session_data('password',$password);
        return $this;
    }
    
    private function _get_default_headers(){
        return array(
            'Connection' => 'keep-alive',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache',
            'Accept-Language' => 'en-US,en;q=0.5',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML => like Gecko) Iron/31.0.1700.0 Chrome/31.0.1700.0',
        );
    }
    
    /**
     * Get Pinterest App Version.
     * @return \WP_Error
     */
    private function _getAppVersion(){

        if ( !$app_version = pinim()->get_session_data('app_version') ){
            
            pinim()->debug_log('_getAppVersion():');
            
            $loaded = $this->loadContent('/login/');
            
            if ( !is_wp_error($loaded) ){
                
                $json = $this->extract_header_json($this->remote_response['body']);

                if (is_wp_error($json)) return $json;

                if ( $data = pinim_get_array_value(array('context','app_version'), $json) ) {
                    $app_version = $json['context']['app_version'];
                    pinim()->set_session_data('app_version',$app_version);
                }
                
            }

            pinim()->debug_log($app_version);
        }
        
        if (!$app_version){
            return new WP_Error('pinim',__('Error getting App Version.  You may have been temporary blocked by Pinterest because of too much login attemps.','pinim'));
        }else{
            return $app_version;
        }
        
        
    }

    private function get_csrftoken($url = '/login/', $force_reset = false){
        
        if ($force_reset){
            pinim()->set_session_data('csrftoken',null);
        }elseif ( $token = pinim()->get_session_data('csrftoken') ) {
            return $token;
        }

        if (!$this->remote_response['headers']) {
            $loaded = $this->loadContent($url);
            if ( is_wp_error($loaded) ) return $loaded;
        }

        $cookies = pinim_get_array_value(array('set-cookie'), $this->remote_response['headers']);

        if ( is_array($cookies) ) {
            $cookies = implode(' ', $cookies);
        } else {
            $cookies = (string)$cookies;
        }
        preg_match('/csrftoken=(.*)[\b;\s]/isU', $cookies, $match);
        if (isset($match[1]) and $match[1]) {
                $token = $match[1];
                pinim()->set_session_data('csrftoken',$token);
                pinim()->debug_log('set_csrftoken() : ' . $token);
        }

        return $token;

    }
    
    /**
     * Try to log in to Pinterest.
     * @return \WP_Error
     */
    public function do_login(){
        
        if ( !$this->isLoggedIn ){
            
            pinim()->debug_log('do_login()');
            
            //login cached
            if ( !$this->login && ( $login = pinim()->get_session_data('login') ) ){
                $this->set_login($login);
            }

            //pwd cached
            if ( !$this->password && ( $password = pinim()->get_session_data('password') ) ){
                $this->set_password($password);
            }
            
            if (!isset($this->login) or !isset($this->password)) {
                return new WP_Error('pinim',__('Missing login and/or password','pinim'));
            }
            
            // reset CSRF token if any (TO FIX : if any !)
            $this->get_csrftoken('/', true);

            $postData = array(
                'data' => json_encode(array(
                    'options' => array(
                        'username_or_email' => $this->login,
                        'password' => $this->password,
                    ),
                    'context' => new stdClass,
                )),
                'source_url' => '/login/',
                'module_path' => 'App()>LoginPage()>Login()>Button(class_name=primary, '
                    . 'text=Log In, type=submit, size=large)',
            );
            
            $loaded = $this->loadContentAjax('/resource/UserSessionResource/create/', $postData, '/login/');
            if ( is_wp_error($loaded) ) return $loaded;
            
            // Force reload CSRF token, it's different for logged in user
            $this->get_csrftoken('/', true);
            
            if ( !$data = pinim_get_array_value(array('resource_response','data'), $this->remote_response['body']) ){
                if( $resource_response_error = pinim_get_array_value(array('resource_response','error'), $this->remote_response['body']) ){
                    $error_message = $resource_response_error;
                }else{
                    $error_message = __("Unknown error.","pinim");
                }
                return new WP_Error( 'pinim',sprintf(__('Error while trying to login: %s','pinim'),$error_message ) );
            }

            $this->isLoggedIn = true;
            pinim()->debug_log('has logged in');

        }

        return $this->isLoggedIn;

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
    
    protected function _httpRequest($type = 'GET', $urlPath, $data = null, $headers = array()){
        $url = self::$pinterest_url . $urlPath;
        if ($type === 'API') {
            $url = self::$pinterest_api_url . $urlPath;
            $type = 'GET';
        }
        if (empty($headers)) { //TO FIX as client as default headers, maybe we can remove this ?
            $headers = $this->_get_default_headers();
        }
        if ($type === 'POST') {
            $response = $this->client->post($url,$headers,$data);
        
        } else {
            $response = $this->client->get($url,$headers);
        }
        pinim()->debug_log($url,'_httpRequest url');
        pinim()->debug_log(json_encode($headers),'_httpRequest headers');
        pinim()->debug_log(json_encode($data),'_httpRequest data');
        //pinim()->debug_log(json_encode($response),'_httpRequest response');
        
        return $response;
    }
    
    /**
     * Set cURL url and get the content from curl_exec() call.
     *
     * @param string $url
     * @param array|boolean|null $dataAjax If array - it will be POST request, if TRUE if will be GET, ajax request.
     * @param string $referer
     * @return string
     * @throws \PinterestPinner\PinnerException
     */
    
    public function loadContentAjax($url, $dataAjax = true, $referer = ''){
        
        $app_version = $this->_getAppVersion();
        if ( is_wp_error($app_version) ) return $app_version;
        
        if (is_array($dataAjax)) {
            
            $csrftoken = $this->get_csrftoken();
            if ( is_wp_error($csrftoken) ) return $csrftoken;
            
            $headers = array_merge($this->_get_default_headers(), array(
                'X-NEW-APP' => '1',
                'X-APP-VERSION' => $app_version,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-CSRFToken' => $csrftoken,
                'Referer' => self::$pinterest_url . $referer,
            ));
            $response = $this->_httpRequest('POST', $url, $dataAjax, $headers);
            
        } elseif ($dataAjax === true) {

            $headers = array_merge($this->_get_default_headers(), array(
                'X-NEW-APP' => '1',
                'X-APP-VERSION' => $app_version,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-Pinterest-AppState' => 'active',
            ));
            $response = $this->_httpRequest('GET', $url, null, $headers);
        }
        
        if ( is_wp_error($response) ) return $response;
        
        return $this->_populate_response($response);
    }
    
    /**
     * Set cURL url and get the content from curl_exec() call.
     *
     * @param string $url
     * @return string
     * @throws \PinterestPinner\PinnerException
     */
    
    public function loadContent($url)
    {
        $response = $this->_httpRequest('GET', $url);
        if ( is_wp_error($response) ) return $response;
        
        return $this->_populate_response($response);
    }
    
    
    /**
     * Parse the response from _httpRequest().
     *
     * @param $response
     * @return string
     * @throws \PinterestPinner\PinnerException
     */
    protected function _populate_response($response){

        $code = (int)substr($response->status_code, 0, 2);
        if ($code !== 20) {
            $error_msg = ''; //TO FIX find status message from Requests_Session
            $error = sprintf(__("HTTP error %s when requesting %s ","pinim"),$response->status_code,urldecode($response->url));
            if ($error_msg) $error.=': '.$error_msg;
            return new WP_Error('pinim',$error);
        }
        
        //headers
        //is a Requests_Response_Headers object, convert it to an array
        $headers = (array)$response->headers;
        //weird array were the (single) key is named 'data*', so extract first value
        $this->remote_response['headers'] = array_values($headers)[0]; 
        
        //body
        $this->remote_response['body'] = (string)$response->body;

        if (substr($this->remote_response['body'], 0, 1) === '{') { //is JSON
            $this->remote_response['body'] = @json_decode($this->remote_response['body'], true);
        }
        
        return true;
        
    }
    
    function get_default_username(){
        if ( !$username = $this->get_user_datas('username') ) {
            return new WP_Error('Missing username in user data.','pinim');
        }
        return $username;
    }
    
    /**
     * Get datas for a user.
     * @return \WP_Error
     */
    public function get_user_datas($keys = null,$username = null){
        
        if (!$username) $username = 'me'; //when the http request will be made, this will redirect to the logged user URL
        
        $userdatas = array();
        
        $userdatas_stored = pinim()->get_session_data('user_datas');

        if ( pinim_array_keys_exists($username, $userdatas_stored) ){
            
            $userdatas = pinim_get_array_value($username, $userdatas_stored);
            
        }else{
        
            pinim()->debug_log('get_user_datas() for user:' . $username);

            $login = $this->do_login();
            if (is_wp_error($login)) return $login;

            $loaded = $this->loadContent(sprintf('/%s/',$username));
            if ( is_wp_error($loaded) ) return $loaded;

            $json = $this->extract_header_json($this->remote_response['body']);

            if ( $userdatas = pinim_get_array_value(array('tree','data'), $json) ){
                $userdatas_stored[$username] = $userdatas;
                pinim()->set_session_data('user_datas',$userdatas_stored);
            }
        }
        
        return pinim_get_array_value($keys, $userdatas);
        
    }


    /**
     * Get boards for a username.
     * @return \WP_Error
     */
    
    public function get_user_boards($username = null){
        
        $me_username = $this->get_default_username();
        
        if (!$username){
            if ( is_wp_error($me_username) ){
                return $me_username;
            }
            $username = $me_username;
        }

        if ( ($username == $me_username) && (!$this->isLoggedIn) ){
            $message = __("We were unable to grab your private boards since you are not logged to Pinterest.",'pinim');
            pinim()->debug_log($message,' get_user_boards()');
            add_settings_error('feedback_boards', 'not-logged', $message,'inline');
        }

        $userboards_stored = pinim()->get_session_data('user_datas_boards');
        
        $userboards = array();
        
        if ( pinim_array_keys_exists($username, $userboards_stored) ){
            
            $userboards = pinim_get_array_value($username, $userboards_stored);
            
        }else{
            
            $loaded = $this->loadContentAjax('/resource/BoardsResource/get/?' . http_build_query(array(
                    'source_url' => sprintf('/%s/',$board->username),
                    'data' => json_encode(array(
                        'options' => array(
                            'filter'            => 'all', // all | public | private
                            'field_set_key'     => 'grid_item',
                            'username'          => $username,
                            'sort'              => 'profile',
                        ),
                        'context' => new stdClass,
                    )),
                    '_' => time() . '999',
                )), true);
            
            if ( is_wp_error($loaded) ) return $loaded;
            
            if ( $userboards = pinim_get_array_value(array('resource_response','data'), $this->remote_response['body']) ){
                
                //precaution - remove items that have not the "board" type (like module items)
                $userboards = array_filter(
                    $userboards,
                    function ($e) {
                        return $e['type'] == 'board';
                    }
                );  
                
                $userboards = array_values($userboards); //reset keys
                
            }

        }
        
        $userboards_stored[$username] = $userboards;
        pinim()->set_session_data('user_datas_boards',$userboards_stored);
        
        return $userboards;

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
     * @param type $bookmark
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

            $loaded = $this->loadContentAjax('/resource/UserLikesResource/get/?' . http_build_query(array(
                'source_url' => sprintf('/%s/',$board->username),
                'data' => json_encode(array(
                    'options' => array(
                        'username'          => $board->username,
                        'bookmark'          => ( $board->bookmark ) ? (array)$board->bookmark : null
                    ),
                    'context' => new stdClass,
                )),
                '_' => time() . '999',
            )), true);

        }else{
            
            $loaded = $this->loadContentAjax('/resource/BoardFeedResource/get/?' . http_build_query(array(
                'source_url' => sprintf('/%s/%s/',$board->username,$board->slug),
                'data' => json_encode(array(
                    'options' => array(
                        'board_id'                  => $board->board_id,
                        'add_pin_rep_with_place'    => null,
                        'board_url'                 => $board->get_datas('url'),
                        'page_size'                 => null,
                        'prepend'                   => true,
                        'access'                    => array('write','delete'),
                        'board_layout'              => 'default',
                        'bookmark'                  => ( $board->bookmark ) ? (array)$board->bookmark : null
                    ),
                    'context' => new stdClass,
                )),
                '_' => time() . '999',
            )), true);

            
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
        
        //bookmark
        $bookmark = null;
        if ( $bookmarks = pinim_get_array_value(array('body','resource','options','bookmarks'), $this->remote_response) ){
            $bookmark = $bookmarks[0];
        }

        return array(
            'pins'      => $page_pins,
            'bookmark'  => $bookmark
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

