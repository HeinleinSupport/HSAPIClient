<?php
namespace Heinlein\HSAPIClient;

// Autor: h.schmidt@heinlein-support.de
// Version: 1.1.7

class HSAPIClient {

    // Raw Request
    public $request = '';

    // Raw Response
    public $response = '';

    // Parsed Response
    public $parsed_response = FALSE;

    // Request-URL
    public $url = 'https://api.mx.heinlein-hosting.de/';

    // Request-Port
    protected $port = 443;

    // Host will be parsed from URL
    protected $host = '';

    // Protocol will be parsed from URL
    protected $protocol = '';

    // SSL-Port
    public $port_ssl = 443;

    // Non-SSL-Port
    public $port_non_ssl = 80;

    // optional additional headers
    public $headers = array();

    // Request-Timeout in seconds
    public $timeout = 30;

    // Chunksize for fgets() in bytes
    public $chunksize = 2048;

    // set stream blocking?
    public $blocking = TRUE;

    // connectmode: curl or fsockopen
    public $connectmode = 'curl';

    // API-Key
    protected $key = '';

    // API-User
    protected $user = '';

    // API-Pass
    protected $pass = '';

    // Commands to be executed
    public $commands = array();

    // error happened?
    public $error = NULL;

    public $error_message_internal = '';

    // verify cert? nicht nur für cURL, auch für fsockopen
    public $curl_verifypeer = TRUE;

    public function __construct($key = '', $pass=NULL) {
        $this->key = $key;
        if ($pass !== NULL) {
            $this->key = 'userauth';
            $this->user = $key;
            $this->pass = $pass;
        }
    }
    protected function send_api_request() {

        if ($this->connectmode == 'curl') {
            $s = curl_init();
            curl_setopt($s,CURLOPT_URL,$this->url);
            curl_setopt($s,CURLOPT_TIMEOUT,$this->timeout);
            curl_setopt($s,CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($s,CURLOPT_HTTPHEADER,     array('Content-Type: text/json'));
            curl_setopt($s,CURLOPT_FORBID_REUSE, 1);
            curl_setopt($s,CURLOPT_FRESH_CONNECT, 1);
            curl_setopt($s,CURLOPT_POST, 1);
            curl_setopt($s,CURLOPT_POSTFIELDS,$this->request);
            curl_setopt($s,CURLOPT_SSL_VERIFYPEER, $this->curl_verifypeer);
            if (!$this->curl_verifypeer) {
                curl_setopt($s, CURLOPT_SSL_VERIFYHOST, FALSE);
            }

            if (!empty($this->headers)) {
                $tmp = array();
                foreach ($this->headers AS $key => $value) {
                    $tmp[] = $key.": ".$value;
                }
                if (!empty($tmp)) {
                    curl_setopt($s, CURLOPT_HTTPHEADER, $tmp);
                }
            }

            $this->response = curl_exec($s);
            curl_close($s);

        } else {

            if ($this->connectmode == 'fsockopen') {
                $sock = fsockopen($this->protocol.$this->host, $this->port, $errno, $errstr, $this->timeout);
            } else {
                $sock = stream_socket_client($this->protocol.$this->host.':'.$this->port, $errno, $errstr, $this->timeout);
                stream_context_set_option ( $sock, 'ssl', 'verify_peer', $this->curl_verifypeer);
            }

            if(is_resource($sock)) {

                stream_set_timeout($sock, $this->timeout);
                stream_set_blocking($sock, $this->blocking);
                stream_context_set_option ($sock, 'ssl', 'verify_peer', $this->curl_verifypeer);

                fputs($sock, 'POST '.$this->path." HTTP/1.0\r\n");
                fputs($sock, 'Host: '.$this->host."\r\n");
                fputs($sock, "Content-type: application/json\r\n");
                if (!empty($this->headers)) {
                    foreach ($this->headers AS $key => $value) {
                        fputs($sock, $key.": ".$value."\r\n");
                    }
                }
                fputs($sock, 'Content-Length: '.strlen($this->request)."\r\n");
                fputs($sock, "Connection: close\r\n\r\n");
                fputs($sock, $this->request);
                $this->response ='';
                while(!feof($sock)) {
                    $reply = fgets($sock, $this->chunksize);
                    if ($reply !== FALSE) {
                        $this->response .= $reply;
                    } else {
                        $this->response = FALSE;
                        $info = stream_get_meta_data($sock);
                        if ($info['timed_out']) {
                            $this->error_message_internal = "TIMEOUT";

                        } else {
                            $this->error_message_internal = print_r($info, TRUE);
                        }
                    }
                }
                fclose($sock);
            } else {
                $this->response = FALSE;
            }
        }
    }
    public function send($payload = '') {
        $this->parse_url();
        if (empty($payload)) {
            $this->prepare_request();
        } else {
            $this->request = $payload;
        }
        $this->send_api_request();
        $this->parse_response();
        $this->cleanup();
        return $this->parsed_response;
    }
    protected function parse_url() {
        $info = parse_url($this->url);
        if ($info !== FALSE) {
            if ($info['scheme'] === 'https') {
                $this->protocol = 'ssl://';
                $this->port = $this->port_ssl;
            } else {
                $this->port = $this->port_non_ssl;
            }
            $this->host = $info['host'];
            $this->path = $info['path'];
        }
    }
    protected function parse_response() {
        if ($this->response === FALSE) {
            $this->parsed_response = FALSE;
            return;
        }
        if ($this->connectmode == 'curl') {
            $this->parsed_response = json_decode($this->response, TRUE);
        } else {
            $r = str_replace("\r", '', $this->response);
            $r = explode("\n\n", $r, 2);
            $r = $r[1];
            $this->parsed_response = json_decode($r, TRUE);
        }
    }
    public function command($name, $params = NULL, $id = '1') {
        $command = array('jsonrpc' => '2.0');
        if ($this->key === 'userauth') {
            $command['user'] = $this->user;
            $command['pass'] = $this->pass;
        } else {
            if (!empty($this->key)) {
                $command['key'] = $this->key;
            }
        }
        $command['method'] = $name;

        if (is_array($params) AND !empty($params)) {
            foreach ($params AS $key => $value) {
                $command['params'][$key] = $value;
            }
        } else {
            $command['params'] = '';
        }
        if (!is_null($id)) {
            $command['id'] = $id;
        }
        $this->commands[] = $command;
    }
    public function call($name, $params = NULL, $id = '1') {
        $this->parse_url();
        $this->command($name,$params,$id);
        $this->prepare_request();
        $this->send_api_request();
        $this->parse_response();
        $this->cleanup();
        if (is_array($this->parsed_response) AND isset($this->parsed_response['result'])) {
            return $this->parsed_response['result'];
        }
        $this->error = TRUE;
        return FALSE;
    }
    public function error_message() {
        if (is_array($this->parsed_response) AND isset($this->parsed_response['error']['message'])) {
            return $this->parsed_response['error']['message'];
        }
        return $this->error_message_internal;
    }
    public function error_code() {
        if (is_array($this->parsed_response) AND isset($this->parsed_response['error']['code'])) {
            return $this->parsed_response['error']['code'];
        }
        return NULL;
    }
    public function error_data() {
        if (is_array($this->parsed_response) AND isset($this->parsed_response['error']['data'])) {
            return $this->parsed_response['error']['data'];
        }
        return NULL;
    }
    public function bindecode($bin) {
        return gzuncompress(base64_decode($bin));
    }
    public function binencode($bin) {
        return base64_encode(gzcompress($bin));
    }
    protected function prepare_request() {
        if (count($this->commands) === 1) {
            $this->request = json_encode($this->commands[0]);
        } else {
            $this->request = json_encode($this->commands);
        }
    }
    protected function cleanup() {
        $this->request = '';
        $this->commands = array();
        $this->error = NULL;
    }
    public function add_header($key, $value) {
        $this->headers[$key] = $value;
    }
    public function set_auth_id($id) {
        $this->headers['HPLS-AUTH'] = $id;
    }
}
