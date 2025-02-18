<?php
/* @author: zang_itu@yahoo.com
 * @version: 0.1 */

// api v2

class SynoFileHostingFshareVN {
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;
    private $COOKIE_JAR = '/tmp/fsharevn.cookie';
    private $LOG_FILE = '/tmp/fsharevn.log';
    private $TOKEN_FILE = '/tmp/fsharevn.token';
    private $SESSIONID_FILE = '/tmp/fsharevn.sessionid';
    private $SESSIONIDTIME_FILE = '/tmp/fsharevn.sessionidtime';
	
    public function __construct($Url, $Username, $Password, $HostInfo) {
        if(strpos($Url,'http://') !== FALSE){
            $Url = str_replace("http://", "https://", $Url);
        }else{
            if(strpos($Url,'https://') === FALSE){
                $Url = "https://" . $Url;
            }
        }

        $this->Url = $Url;
        $this->Username = $Username;
        $this->Password = $Password;

	//$this->AppId = "GUxft6Beh3Bf8qKP7GC2IplYJZz1A53JQfRwne0R";
        $this->AppId = "";
        $this->UserAgent = "";

        $this->HostInfo = $HostInfo;

        ini_set('max_execution_time', 300);
    }
    
    public function Verify($ClearCookie) {
        if(file_exists($this->COOKIE_JAR)) {
            unlink($this->COOKIE_JAR);
        }
            
        return $this->performLogin();
    }
    
    public function GetDownloadInfo() {
        $DownloadInfo = array();

        $this->logInfo("Start getting download info");

        $needLogin = FALSE;
        $this->logInfo("Checking if need to re-login");
        
        $this->Token = $this->getToken();
        if(empty($this->Token)) {
            $this->logInfo("Token is empty => need to login to get token");
            $needLogin = TRUE;
        }
		
	$this->SessionId = $this->getSessionId();
	if(empty($this->SessionId)) {
		$this->logInfo("SessionId is empty => need to login to get token");
		$needLogin = TRUE;
	}

	$this->SessionIdTime = $this->getSessionIdTime();
	if(empty($this->SessionIdTime)) {
		$this->logInfo("SessionIdTime is empty => need to login to get token");
		$needLogin = TRUE;
	}else{
		$nowdt = new DateTime(Date("Y-m-d H:i:s"));
		$dt = new DateTime($SessionIdTime);

		if ($dt < $nowdt){
			if($this->refreshToken() === RToken_FAIL){
				$needLogin = TRUE;
			}
		}
	}
        
        if(!file_exists($this->COOKIE_JAR)) {
            $this->logInfo("Cookie file is not existed => need to login to get cookie");
            $needLogin = TRUE;
        }

        if($needLogin) {
            // login to get authentication info
            if($this->Verify(FALSE) === LOGIN_FAIL) {
                $DownloadInfo[DOWNLOAD_ERROR] = "Login fail!";
                return $DownloadInfo;
            }    
        }

        $downloadUrl = $this->getLink();
        
        if(empty($downloadUrl) || $downloadUrl === "error") {
            
            // get link may fail due to use expired token / cookie
            // => login and retry once
            if(!$needLogin) {
                $this->logInfo("Token/Cookie is expired. Login and try once");
                if($this->Verify(FALSE) === LOGIN_FAIL) {
                    $DownloadInfo[DOWNLOAD_ERROR] = "Login fail!";
                    return $DownloadInfo;
                }

                $downloadUrl = $this->getLink();        
            }

            $DownloadInfo[DOWNLOAD_ERROR] = "Get link fail";   
        }
        else
        {
            $DownloadInfo[DOWNLOAD_URL] = $downloadUrl;
        }

        $this->logInfo("End getting download info");

        return $DownloadInfo;

    }
    
    private function performLogin() {
        $ret = LOGIN_FAIL;

        $this->logInfo("Start login");

        $service_url = 'https://api2.fshare.vn/api/user/login';
        $curl = curl_init($service_url);
        $data = array(
            "app_key" => $this->AppId,
            "password" => $this->Password,
            "user_email" => $this->Username
        );

        $data_string = json_encode($data);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_JAR);

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            //"User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36",
            "User-Agent:" . $this->UserAgent,
            "Content-Type: application/json",
            'Content-Length: ' . strlen($data_string)
        ));
        
        $curl_response = curl_exec($curl);

        if(!$this->isOK($curl, $curl_response))
        {
            $this->logError("Login error: " . curl_error($curl));
        }
        else
        {
	    // save token to disk
            $this->Token = json_decode($curl_response)->{'token'};
            $this->saveToken($this->Token);
	    // save SessionId to disk
	    $this->SessionId = json_decode($curl_response)->{'session_id'};
	    $this->saveSessionId($this->SessionId);
	    // save SessionIdTime to disk
	    $dt = new DateTime(Date("Y-m-d H:i:s"));
	    $dt->add(new DateInterval('PT6H'));
	    $this->SessionIdTime = $dt->format('Y-m-d H:i:s');
	    $this->saveSessionIdTime($this->SessionIdTime);
			
            $this->logInfo("Login ok");
    
            $ret = USER_IS_PREMIUM;
        }

        $this->logInfo("End login");

        curl_close($curl);

        return $ret;
        
    }
	
	private function refreshToken() {
        $ret = RToken_FAIL;

        $this->logInfo("Start Refresh Token");

        $service_url = 'https://api.fshare.vn/api/user/refreshToken';
        $curl = curl_init($service_url);
        $data = array(
            "app_key" => $this->AppId,
            "token" => $this->Token
        );

        $data_string = json_encode($data);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_JAR);

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            //"User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36",
	    "User-Agent:" . $this->UserAgent,
            "Content-Type: application/json",
            'Content-Length: ' . strlen($data_string)
        ));
        
        $curl_response = curl_exec($curl);

        if(!$this->isOK($curl, $curl_response))
        {
            $this->logError("Refresh Token error: " . curl_error($curl));
        }
        else
        {
	    // save token to disk
            $this->Token = json_decode($curl_response)->{'token'};
            $this->saveToken($this->Token);
            // save SessionId to disk
            $this->SessionId = json_decode($curl_response)->{'session_id'};
            $this->saveSessionId($this->SessionId);
            // save SessionIdTime to disk
            $dt = new DateTime(Date("Y-m-d H:i:s"));
            $dt->add(new DateInterval('PT6H'));
            $this->SessionIdTime = $dt->format('Y-m-d H:i:s');
            $this->saveSessionIdTime($this->SessionIdTime);
			
            $this->logInfo("Refresh Token ok");
    
            $ret = USER_IS_PREMIUM;
        }

        $this->logInfo("End Refresh Token");

        curl_close($curl);

        return $ret;
        
    }

    private function getLink() {

        $ret = "error";

        $this->logInfo("Start Get Link: " . $this->Url);

        $service_url = 'https://api2.fshare.vn/api/session/download';

        $curl = curl_init($service_url);
        $data = array(
            "url" => $this->Url,
            "password" => "",
            "token" => $this->Token,
            "zipflag" => false
        );

        $data_string = json_encode($data);


        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_JAR);

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            //"User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36",
            "User-Agent:" . $this->UserAgent,
            "Cookie: session_id=" . $this->SessionId,
            "Content-Type: application/json",
            'Content-Length: ' . strlen($data_string)
        ));
        
        $curl_response = curl_exec($curl);

        if(!$this->isOK($curl, $curl_response))
        {
            $this->logError("Get link error: " . curl_error($curl));
        }
        else
        {
            $downloadUrl = json_decode($curl_response)->{'location'};

            $this->logInfo("Get link ok");

            $ret = $downloadUrl;
        }

        $this->logInfo("End Get Link");

        curl_close($curl);

        return $ret;
    }

    private function logError($msg) {
        $this->log("[ERROR]", $msg);
    }

    private function logInfo($msg) {
        $this->log("[INFO]", $msg);
    }

    private function log($prefix, $msg) {
        error_log($prefix . " - " . date('Y-m-d H:i:s') . " - " . $msg . "\n", 3, $this->LOG_FILE);
    }

    //save and get
    //Token
    private function saveToken($token) {
        $myfile = fopen($this->TOKEN_FILE, "w");
        fwrite($myfile, $token);
        fclose($myfile);
    }
	
    private function getToken() {
        if(file_exists($this->COOKIE_JAR)) {
            $myfile = fopen($this->TOKEN_FILE, "r");
            $token = fgets($myfile);
            fclose($myfile);
            return $token;
        }
        else
        {
            return "";
        }
    }
	//SessionId
	private function saveSessionId($sessionid) {
        $myfile = fopen($this->SESSIONID_FILE, "w");
        fwrite($myfile, $sessionid);
        fclose($myfile);
    }
	
    private function getSessionId() {
        if(file_exists($this->COOKIE_JAR)) {
            $myfile = fopen($this->SESSIONID_FILE, "r");
            $sessionid = fgets($myfile);
            fclose($myfile);
            return $sessionid;
        }
        else
        {
            return "";
        }
    }
	//SessionIdTime
	private function saveSessionIdTime($sessionidtime) {
        $myfile = fopen($this->SESSIONIDTIME_FILE, "w");
        fwrite($myfile, $sessionidtime);
        fclose($myfile);
    }
	
    private function getSessionIdTime() {
        if(file_exists($this->COOKIE_JAR)) {
            $myfile = fopen($this->SESSIONIDTIME_FILE, "r");
            $sessionidtime = fgets($myfile);
            fclose($myfile);
            return $sessionidtime;
        }
        else
        {
            return "";
        }
    }
	//save and get
	
    private function isOK($curl, $curl_response) {
        $this->logInfo("HTTP Response: " . $curl_response);
        
        if($curl_response === false) {
            return false;
        }

        $info = curl_getinfo($curl);
        $this->logInfo("HTTP CODE: " . $info['http_code']);
        if($info['http_code'] !== 200) {
            return false;
        }

        $code = json_decode($curl_response)->{'code'};
        $this->logInfo("CODE: " .$code);
        if( !empty($code) && $code !== 200) {
            return false;
        }

        return true;
    }

}

/* $url = "https://www.fshare.vn/file/EJ6XXM22W5MIE7G";
$username = "@gmail.com";
$password = "As";

$client = new SynoFileHostingFshareVN($url, $username, $password, NULL);
$client->GetDownloadInfo();
echo "DONE"; */

?>
