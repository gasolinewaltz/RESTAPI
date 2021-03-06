<?php

require_once 'API.class.php';
require_once __DIR__.'./../Models/User.class.php';
require_once __DIR__.'./../Models/Database.class.php';
require_once __DIR__.'./../Models/SQL_Statements.class.php';
require_once __DIR__.'./../Models/Cms.class.php';
require_once __DIR__.'./../Models/Utilities.class.php';
//require endpoint models
require_once __DIR__.'./../Endpoint_Models/Pages.class.php';

class REST_API extends API
{
    protected $User;
    private $db;
    private $lists;
    private $sql;
    private $cms;
    private $util;
    private $pages;

    private function check_auth_session(){
        $user = $_SERVER['PHP_AUTH_USER'];
        $pw = $_SERVER['PHP_AUTH_PW'];
        $valid = $this->User->valid_token($pw, $user);
        if( !$valid ){
            throw new Exception(401);
        }else{
            return $valid;
        };

    }
    private function authorize_user(){
        $protected_methods = Array(
            'PUT', 'PUSH', 'PATCH', 'DELETE', 'POST'
        );
        //first, enforce login for protected methods,
        //  i.e., the user is altering (or attempting) something...
        if(in_array($this->method, $protected_methods)){
            $this->check_auth_session();
        }
        /*
        Next, the user isn't trying to alter anythign, but is sending auth headers.
          So, if they're wrong, issue a 401.
        Exception: if the user is trying to login, we handle the auth differently;

        Note to self: At the moment, it seems that we should only check the auth session if AUTH_USER and AUTH_PW
              is present
        */
        elseif($_SERVER['PHP_AUTH_USER'] && $_SERVER['PHP_AUTH_PW'] && $this->endpoint !== 'login'){
            $this->check_auth_session();
        }

    }
    private function initialize(){
        $this->db = Database::get_instance();
        //models
        $this->lists = new List_functions();
        $this->sql = new SQL_Statements();
        $this->cms = new Cms();
        $this->util = new Utilities($this->method);
        $this->User = new User();
        //endpoint models
        $this->pages = new Pages($this->args, $this->method);
    }
    public function __construct($request)
    {
        parent::__construct($request);
        //Database instance
        $this->initialize();
        $this->authorize_user();
    }
    /**
     * Endpoint methods
     */

    protected function API()
    {
        return Array(
            'version' => '',
            'site' => '',
            'links' => Array(
                'pages' => Array(
                    'ref' => SERVER_ROOT.'/pages',
                    'description' => "Retrieve page data."
                )
            )
        );
    }
    protected function login()
    {
        $this->util->allowed_methods('GET');
        if($this->method === 'OPTIONS'){
            return NULL;
        }
        $user = $_SERVER['PHP_AUTH_USER'];
        $pw = $_SERVER['PHP_AUTH_PW'];
        if(!$user || !$pw){
            throw new Exception(401);
        }
        $this->User = new User();
        $this->User->login($user, $pw);
        return Array('username'=>$this->User->username, 'token'=>$this->User->token);
    }

    protected function check_login()
    {
        $response = false;
        $this->util->allowed_methods($this->method, "GET");
        if($this->method === "OPTIONS"){
            return null;
        }
        return $this->check_auth_session();
    }

    protected function logout()
    {
        $this->util->allowed_methods('GET');
        if($this->method === 'OPTIONS'){
            return NULL;
        }
        if($this->check_auth_session()){
            $this->User->logout();
        }else{
            throw new Exception(400);
        }
    }

    /*
     * url structure:
     *
     *  /pages
     *      returns array of pages that exist
     *  _____________________
     *  /pages/menus
     *      returns an array of menus that exist
     * -or-
     *  /pages/merch
     *      returns an relation of links / items
     *  _____________________
     *  /pages/menus/food?item=1
     *      returns item with the id of 1
     * -or-
     *  /pages/merch?item=1
     *      returns an item with the id of 1
     * -or-
     * /pages/menus?item=1
     *      returns the menu with the id of 1
     */
    protected function pages(){
        //deal with options first
        if($this->method === 'OPTIONS'){
            $this->util->allowed_methods('GET PUT POST PATCH DELETE');
            return null;
        }
        $page = $this->util->check($this->args[0]);
        $response = null;
        $item = $this->util->check($this->request['item']);
        switch($page){
            case(false):
                $response =  $this->db->fetch_all_query( $this->sql->get('available_pages') );
                break;
            case('menus'):
                $response = $this->pages->get_menu($item);
                break;
            case('merch'):
                $response = $this->pages->get_merch($item);
                break;
            case('press'):
                $response = $this->pages->get_press($item);
                break;
            default:
                throw new Exception(404);
                break;
        }

        return $response;
    }
    public function user(){
        $this->util->allowed_methods('POST');
        $option = $this->args[0];
        switch($option){
            case('change-password'):
                $old_pw = $this->util->check($this->request['password']);
                $new_pw = $this->util->check($this->request['new_password']);
                if(!$old_pw || !$new_pw){
                    //bad request, requires old and new
                    throw new Exception(400);
                }else{
                    $username = $this->User->username;
                    $this->User->login($username, $old_pw);
                    $this->User->change_password($new_pw);
                    return Array('token'=>$this->User->token);
                }
                break;
            case('change-username'):
                $pw = $this->util->check($this->request['password']);
                $new_username = $this->util->check($this->request['new_username']);
                if(!$pw || !$new_username){
                    throw new Exception(400);
                }else{
                    $this->User->change_username($new_username, $pw);
                    return Array(
                        'username' => $this->User->username,
                        'token' => $this->User->token
                    );
                }
                break;
            default:
                throw new Exception(404);
        }
    }
}
