<?php

class LicenseServer {

    public $version = '0.3.1';

    public $server_uri = "https://www.directadmin.com/";

    protected $uid = 0;

    protected $password = '';

    protected $license_id = 0;

    protected $curl;

    /**
     * LicenseServer constructor.
     * @param int $uid
     * @param string $password
     */
    public function __construct($uid = 0, $password = '')
    {
        $this->uid = $uid;
        $this->password = $password;
        $this->curl = new \GuzzleHttp\Client([
            'base_url' => $this->server_uri,
            'verify' => false,
            'timeout' => 300,
            'headers' => [
                'User-Agent' => 'DirectAdminLicense Class'
            ],
            'defaults' => [
                'auth' => [
                    $this->uid,
                    $this->password
                ]
            ]
        ]);
    }

    /**
     * @param string $string
     * @return object
     */
    public function parse_response($string = ''){
        parse_str($string, $output);
        return (object)$output;
    }

    /**
     * @return object
     */
    public function test_connection(){
        $response = $this->curl->get('clients/api/user_info.php');
        return $this->parse_response($response->getBody());
    }

    /**
     * @param int $id
     */
    public function set_license_id($id = 0){
        $this->license_id = $id;
    }

    /**
     * @param string $name
     * @return object
     * @throws \Exception
     */
    public function set_license_name($name = 'DirectAdmin'){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $response = $this->curl->post("/clients/api/special.php?lid={$this->license_id}",[
            'body' => [
                'savename' => 'yes',
                'name' => $name
            ]
        ]);
        return $this->parse_response($response->getBody());
    }

    /**
     * @param string $ip
     * @return object
     * @throws \Exception
     */
    public function set_license_ip($ip = '127.0.0.1'){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $response = $this->curl->post("/clients/api/special.php?lid={$this->license_id}",[
            'body' => [
                'saveip' => 'yes',
                'ip' => $ip
            ]
        ]);
        return $this->parse_response($response->getBody());
    }

    /**
     * @param string $tag
     * @param string $description
     * @throws \Exception
     */
    public function set_license_comments($tag = '', $description = ''){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $this->curl->post('/clients/api/savecomments.php', [
            'tag' => $tag,
            'desc' => $description,
            'lid' => $this->license_id
        ]);
    }

    /**
     * @return array
     */
    public function get_license_products(){
        $response = $this->curl->get('/clients/api/products.php');
        $lines = $response->getBody();
        $lines = explode("\n",$lines);
        $output = [];
        foreach($lines as $line) {
            if($line !== '') {
                list($id, $description) = explode('=', $line);
                $output[$id] = $description;
            }
        }
        return $output;
    }

    /**
     * @return array
     */
    public function get_os_list(){
        $response = $this->curl->get('/clients/api/os_list.php',['body' => ['active' => 'yes']]);
        $lines = explode("\n",$response->getBody());
        $output = [];
        foreach($lines as $line){
            if($line !== '') {
                list($osname, $osdesc) = explode("=", $line);
                if ($osname !== '' && $osdesc !== '') {
                    $output[$osname] = $osdesc;
                }
            }
        }
        return $output;
    }

    /**
     * @param string $ip
     * @param int $product_id
     * @param string $name
     * @param string $email
     * @param string $os
     * @param string $domain
     * @param string $payment
     * @return array|string[]
     * @throws \Exception
     */
    public function create_license($ip = '', $product_id = 0, $name = 'test', $email = '', $os = '', $domain = 'test', $payment = 'balance'){
        $response = $this->curl->post('/clients/api/createlicense.php', [
            'headers' => [
                'User-Agent' => 'DirectAdmin License Class',
                'Referer' => 'https://www.directadmin.com/clients/createlicense.php',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'id' => $this->uid,
                'password' => $this->password,
                'api' => "1",
                'ip' => $ip,
                'pid' => $product_id,
                'name' => $name,
                'email' => $email,
                'os' => $os,
                'domain' => $domain,
                'payment' => $payment
            ]
        ]);
        $res = $this->parse_response($response->getBody());

        if($res->error == 1){
            throw new \Exception($res->text);
        }

        return $this->get_license($ip);
    }

    /**
     * @param string $reason
     * @throws \Exception
     */
    public function suspend_license($reason = ''){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $this->curl->post("/clients/api/special.php?lid={$this->license_id}",[
            'body' => [
                'savesuspended' => $reason,
                'suspended' => 'Y'
            ]
        ]);
    }

    /**
     * @param string $reason
     * @throws \Exception
     */
    public function unsuspend_license($reason = 'good'){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $this->curl->post("/clients/api/special.php?lid={$this->license_id}",[
            'body' => [
                'savesuspended' => $reason,
                'suspended' => 'N'
            ]
        ]);
    }

    /**
     * @return object
     * @throws \Exception
     */
    public function delete_license(){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $response = $this->curl->post('/cgi-bin/deletelicense', [
            'headers' => [
              'Referer' => 'https://www.directadmin.com/clients/license.php'
            ],
            'body' => [
                'uid' => $this->uid,
                'password' => $this->password,
                'lid' => $this->license_id
            ]
        ]);
        return $this->parse_response($response->getBody());
    }

    /**
     * @param string $ip
     * @param string $active
     * @param string $recent
     * @return array
     */
    public function get_license($ip = '', $active = 'Y', $recent = ''){
        if($this->license_id != 0){
            $vars['lid'] = $this->license_id;
        }
        if($ip != ''){
            $vars['ip'] = $ip;
        }
        $vars['recent'] = $recent !== '' ? $recent : '';
        $vars['active'] = $active;
        $response = $this->curl->post('/clients/api/list.php',['body' => $vars]);
        $lines = $response->getBody();
        $lines = explode("\n", $lines);
        $output = [];
        foreach ($lines as $line) {
            if ($line !== '') {
                parse_str($line, $licinfo);
                if (isset($licinfo['lid']) && is_numeric($licinfo['lid'])) {
                    $output[$licinfo['lid']] = $licinfo;
                }
                if (!isset($this->license_id) || $this->license_id == 0 && $vars['ip'] !== '') {
                    if ($licinfo['ip'] == $ip) {
                        return $licinfo;
                    }
                }
            }
        }
        if(isset($this->license_id) && $this->license_id > 0){
            if (array_key_exists($this->license_id, $output))
                return $output[$this->license_id];
        }
        return ['error' => "License not found!"];
    }

    public function pay_license(){
        if($this->license_id == 0){
            throw new \Exception("License ID has not been set!");
        }
        $response = $this->curl->post('/cgi-bin/makepayment', [
            'headers' => [
                'Referer' => 'https://www.directadmin.com/clients/makepayment.php'
            ],
            'body' => [
                'uid' => $this->uid,
                'password' => $this->password,
                'lid' => $this->license_id,
                'action' => 'pay',
                'api' => 1
            ]
        ]);

        return $this->parse_response($response->getBody());
    }
}
