<?php

if (!defined('RAPIDLEECH')) {
	require_once('index.html');
	exit;
}

class depositfiles_com extends DownloadClass {
	public $link, $page, $domain;
	private $cookie, $pA, $DLregexp, $TryFreeDLTricks;
	public function Download($link) {
		global $premium_acc;
		$this->pA = (empty($_REQUEST['premium_user']) || empty($_REQUEST['premium_pass']) ? false : true);
		$this->link = $link;
		$this->domain = 'depositfiles.com';
		$this->cookie = array('lang_current' => 'en');
		$this->DLregexp = '@https?://fileshare\d+\.(?:depositfiles|dfiles)\.[^/:\r\n\t\"\'<>]+(?:\:\d+)?/auth-[^\r\n\t\"\'<>]+@i';
		$this->TryFreeDLTricks = true;
		if (empty($_REQUEST['step'])) {
			$this->page = $this->GetPage($this->link);
			$this->CheckDomain();
			is_present($this->page, 'This file does not exist', 'The requested file is not found');
		} else $this->CheckDomain(false);

		if (($_REQUEST['premium_acc'] == 'on' && ($this->pA || (!empty($premium_acc['depositfiles_com']['user']) && !empty($premium_acc['depositfiles_com']['pass']))))) {
			$user = ($this->pA ? $_REQUEST['premium_user'] : $premium_acc['depositfiles_com']['user']);
			$pass = ($this->pA ? $_REQUEST['premium_pass'] : $premium_acc['depositfiles_com']['pass']);
			if ($this->pA && !empty($_POST['pA_encrypted'])) {
				$user = decrypt(urldecode($user));
				$pass = decrypt(urldecode($pass));
				unset($_POST['pA_encrypted']);
			}
			$this->CookieLogin($user, $pass);
		} else {
			$this->cookie = GetCookiesArr($this->page, $this->cookie);
			$this->FreeDL();
		}
	}

	private function CheckDomain($reload = true) {
		if (empty($this->page)) $content = $this->GetPage($this->link, $this->cookie);
		else $content = $this->page;

		if (($hpos = strpos($content, "\r\n\r\n")) > 0) $content = substr($content, 0, $hpos);
		if (stripos($content, "\nLocation: ") !== false && preg_match('@\nLocation: https?://(?:[^/\r\n]+\.)?((?:depositfiles|dfiles)\.[^/:\r\n\t\"\'<>]+)(?:\:\d+)?/@i', $content, $redir_domain)) {
			$link = parse_url($this->link);
			$domain = strtolower($link['host']);
			$redir_domain = strtolower($redir_domain[1]);
			if ($domain != $redir_domain) {
				global $Referer;
				$this->domain = $link['host'] = $redir_domain;
				$Referer = $this->link = rebuild_url($link);
				if ($reload) $this->page = $this->GetPage($this->link, $this->cookie);
			}
		}
	}

	private function FreeDL() {
		$purl = 'http://' . $this->domain . '/';
		if (!empty($_POST['step']) && $_POST['step'] == 1) {
			if (empty($_POST['recaptcha_response_field'])) html_error('You didn\'t enter the image verification code.');
			$this->cookie = StrToCookies(decrypt(urldecode($_POST['cookie'])));
			if (empty($_POST['fid'])) html_error('FileID not found after POST.');

			$query = array('fid' => $_POST['fid'], 'challenge' => $_POST['recaptcha_challenge_field'], 'response' => $_POST['recaptcha_response_field']);
			$page = $this->GetPage($purl . 'get_file.php?'.http_build_query($query), $this->cookie);
			is_present($page, 'load_recaptcha()', 'Error: Wrong CAPTCHA entered.');

			if (!preg_match($this->DLregexp, $page, $dlink)) html_error('Download link Not Found.');
			$this->RedirectDownload($dlink[0], basename(urldecode(parse_url($dlink[0], PHP_URL_PATH))));
		} else {
			$page = $this->GetPage($this->link, $this->cookie, array('gateway_result' => '1'));
			is_present($page, 'This file does not exist', 'The requested file is not found');
			$this->cookie = GetCookiesArr($this->page, $this->cookie);
			if ($this->TryFreeDLTricks) $Mesg = lang(300);

			if (stripos($page, 'Connection limit has been exhausted for your IP address!') !== false) {
				if (preg_match('@<span class="html_download_api-limit_interval">[\s\t\r\n]*(\d+)[\s\t\r\n]*</span>@i', $page, $limit)) {
					$x = 0;
					if ($this->TryFreeDLTricks && $limit[1] > 45) while ($x < 3) {
						$page = $this->GetPage($purl . 'get_file.php?fd=clearlimit', $this->cookie);
						if (($fd2 = cut_str($page, 'name="fd2" value="', '"')) == false) break;
						insert_timer(30, 'Trying to reduce ip-limit waiting time.');
						$page = $this->GetPage($purl . 'get_file.php?fd2='.urlencode($fd2), $this->cookie);
						$page = $this->GetPage($this->link, $this->cookie, array('gateway_result' => '1'));
						if (!preg_match('@<span class="html_download_api-limit_interval">[\s\t\r\n]*(\d+)[\s\t\r\n]*</span>@i', $page, $_limit)) {
							$Mesg .= '<br /><br />Skipped the remaining '.($limit[1] - 30).' secs of ip-limit wait time.';
							$this->changeMesg($Mesg);
							$limit[1] = 0;
							break;
						}
						$diff = ($limit[1] - 30) - $_limit[1];
						$limit[1] = $_limit[1];
						$Mesg .= "<br /><br />Skipped $diff secs of ip-limit wait time.";
						$this->changeMesg($Mesg);
						if ($diff < 1) break; // Error?
						$x++;
					}
					if ($limit[1] > 0) return $this->JSCountdown($limit[1], $this->DefaultParamArr($this->link), 'Connection limit has been exhausted for your IP address');
				} else html_error('Connection limit has been exhausted for your IP address. Please try again later.');
			}

			if (!preg_match('@var[\s\t]+fid[\s\t]*=[\s\t]*\'(\w+)\'@i', $page, $fid)) html_error('FileID not found.');
			if (!preg_match('@Recaptcha\.create[\s\t]*\([\s\t]*[\'\"]([\w\-]+)[\'\"]@i', $page, $cpid)) html_error('reCAPTCHA Not Found.');
			if (!preg_match('@setTimeout\(\'load_form\(fid, msg\)\',[\s\t]*(\d+)\);@i', $page, $cd)) html_error('Countdown not found.');
			$cd = $cd[1] / 1000;
			if ($cd > 0) $this->CountDown($cd);

			if ($this->TryFreeDLTricks) {
				$page = $this->GetPage($purl . 'get_file.php?fd2='.urlencode($fid[1]), $this->cookie);
				if (preg_match($this->DLregexp, $page, $dlink)) return $this->RedirectDownload($dlink[0], basename(urldecode(parse_url($dlink[0], PHP_URL_PATH))));
				$Mesg .= '<br /><br /><b>Cannot skip captcha.</b>';
				$this->changeMesg($Mesg);
			}

			$page = $this->GetPage($purl . 'get_file.php?fid='.urlencode($fid[1]), $this->cookie);
			is_notpresent($page, 'load_recaptcha()', 'Error: Countdown skipped?.');

			$data = $this->DefaultParamArr($this->link, encrypt(CookiesToStr($this->cookie)));
			$data['step'] = '1';
			$data['fid'] = urlencode($fid[1]);
			$this->Show_reCaptcha($cpid[1], $data);
		}
	}

	private function PremiumDL() {
		$page = $this->GetPage($this->link, $this->cookie);
		is_present($page, 'This file does not exist', 'The requested file is not found');

		if (!preg_match_all($this->DLregexp, $page, $dlink)) html_error('Download-link Not Found.');
		$dlink = $dlink[0][array_rand($dlink[0])];
		$fname = basename(urldecode(parse_url($dlink, PHP_URL_PATH)));
		$this->RedirectDownload($dlink, $fname);
	}

	private function Login($user, $pass) {
		$purl = 'http://' . $this->domain . '/';
		$errors = array('CaptchaInvalid' => 'Wrong CAPTCHA entered.', 'InvalidLogIn' => 'Invalid Login/Pass.', 'CaptchaRequired' => 'Captcha Required.');
		if (!empty($_POST['step']) && $_POST['step'] == '1') {
			if (empty($_POST['recaptcha_response_field'])) html_error('You didn\'t enter the image verification code.');
			$post = array('recaptcha_challenge_field' => $_POST['recaptcha_challenge_field'], 'recaptcha_response_field' => $_POST['recaptcha_response_field']);
			$post['login'] = urlencode($user);
			$post['password'] = urlencode($pass);

			$page = $this->GetPage($purl.'api/user/login', $this->cookie, $post, $purl.'login.php?return=%2F');
			$json = $this->Get_Reply($page);
			if (!empty($json['error'])) html_error('Login Error'. (!empty($errors[$json['error']]) ? ': ' . $errors[$json['error']] : '..'));
			elseif ($json['status'] != 'OK') html_error('Login Failed');

			$this->cookie = GetCookiesArr($page, $this->cookie);
			if (empty($this->cookie['autologin'])) html_error('Login Error: Cannot find "autologin" cookie');

			$this->SaveCookies($user, $pass); // Update cookies file
			if ($json['data']['mode'] == 'free') html_error('Login Error: Account isn\'t gold');

			return $this->PremiumDL();
		} else {
			$post = array();
			$post['login'] = urlencode($user);
			$post['password'] = urlencode($pass);

			$page = $this->GetPage($purl.'api/user/login', $this->cookie, $post, $purl.'login.php?return=%2F');
			$json = $this->Get_Reply($page);
			if (!empty($json['error']) && $json['error'] != 'CaptchaRequired') html_error('Login Error'. (!empty($errors[$json['error']]) ? ': ' . $errors[$json['error']] : '.'));
			elseif ($json['status'] == 'OK') {
				$this->cookie = GetCookiesArr($page, $this->cookie);
				if (empty($this->cookie['autologin'])) html_error('Login Error: Cannot find "autologin" cookie.');
				$this->SaveCookies($user, $pass); // Update cookies file
				if ($json['data']['mode'] == 'free') html_error('Login Error: Account isn\'t gold.');
				return $this->PremiumDL();
			} elseif (empty($json['error']) || $json['error'] != 'CaptchaRequired') html_error('Login Failed.');

			// Captcha Required
			$page = $this->GetPage($purl.'login.php?return=%2F', $this->cookie, 0, $purl);
			$this->cookie = GetCookiesArr($page, $this->cookie);

			if (!preg_match('@(https?://([^/\r\n\t\s\'\"<>]+\.)?(?:depositfiles|dfiles)\.[^/:\r\n\t\"\'<>]+(?:\:\d+)?)/js/base2\.js@i', $page, $jsurl)) html_error('Cannot find captcha.');
			$jsurl = (empty($jsurl[1])) ? 'http://' . $this->domain . $jsurl[0] : $jsurl[0];
			$page = $this->GetPage($jsurl, $this->cookie, 0, $purl.'login.php?return=%2F');

			if (!preg_match('@recaptcha_public_key\s*=\s*[\'\"]([\w\-]+)@i', $page, $cpid)) html_error('reCAPTCHA Not Found.');

			$data = $this->DefaultParamArr($this->link);
			$data['step'] = '1';
			$data['premium_acc'] = 'on';
			if ($this->pA) {
				$data['pA_encrypted'] = 'true';
				$data['premium_user'] = urlencode(encrypt($user));
				$data['premium_pass'] = urlencode(encrypt($pass));
			}
			$this->Show_reCaptcha($cpid[1], $data, 'Login');
		}
	}

	private function Get_Reply($page) {
		if (!function_exists('json_decode')) html_error('Error: Please enable JSON in php.');
		$json = substr($page, strpos($page, "\r\n\r\n") + 4);
		$json = substr($json, strpos($json, '{'));$json = substr($json, 0, strrpos($json, '}') + 1);
		$rply = json_decode($json, true);
		if (!$rply || count($rply) == 0) html_error('Error reading json.');
		return $rply;
	}

	private function Show_reCaptcha($pid, $inputs, $sname = 'Download File') {
		global $PHP_SELF;
		if (!is_array($inputs)) html_error('Error parsing captcha data.');

		// Themes: 'red', 'white', 'blackglass', 'clean'
		echo "<script language='JavaScript'>var RecaptchaOptions = {theme:'red', lang:'en'};</script>\n\n<center><form name='recaptcha' action='$PHP_SELF' method='POST'><br />\n";
		foreach ($inputs as $name => $input) echo "<input type='hidden' name='$name' id='C_$name' value='$input' />\n";
		echo "<script type='text/javascript' src='http://www.google.com/recaptcha/api/challenge?k=$pid'></script><noscript><iframe src='http://www.google.com/recaptcha/api/noscript?k=$pid' height='300' width='500' frameborder='0'></iframe><br /><textarea name='recaptcha_challenge_field' rows='3' cols='40'></textarea><input type='hidden' name='recaptcha_response_field' value='manual_challenge' /></noscript><br /><input type='submit' name='submit' onclick='javascript:return checkc();' value='$sname' />\n<script type='text/javascript'>/*<![CDATA[*/\nfunction checkc(){\nvar capt=document.getElementById('recaptcha_response_field');\nif (capt.value == '') { window.alert('You didn\'t enter the image verification code.'); return false; }\nelse { return true; }\n}\n/*]]>*/</script>\n</form></center>\n</body>\n</html>";
		exit;
	}

	private function IWillNameItLater($cookie, $decrypt=true) {
		if (!is_array($cookie)) {
			if (!empty($cookie)) return $decrypt ? decrypt(urldecode($cookie)) : urlencode(encrypt($cookie));
			return '';
		}
		if (count($cookie) < 1) return $cookie;
		$keys = array_keys($cookie);
		$values = array_values($cookie);
		$keys = $decrypt ? array_map('decrypt', array_map('urldecode', $keys)) : array_map('urlencode', array_map('encrypt', $keys));
		$values = $decrypt ? array_map('decrypt', array_map('urldecode', $values)) : array_map('urlencode', array_map('encrypt', $values));
		return array_combine($keys, $values);
	}

	private function CookieLogin($user, $pass, $filename = 'depositfiles_dl.php') {
		global $secretkey;
		if (empty($user) || empty($pass)) html_error('Login Failed: User or Password is empty.');

		$filename = DOWNLOAD_DIR . basename($filename);
		if (!file_exists($filename) || (!empty($_POST['step']) && $_POST['step'] == '1')) return $this->Login($user, $pass);

		$file = file($filename);
		$savedcookies = unserialize($file[1]);
		unset($file);

		$hash = hash('crc32b', $user.':'.$pass);
		if (array_key_exists($hash, $savedcookies)) {
			$_secretkey = $secretkey;
			$secretkey = sha1($user.':'.$pass);
			$this->cookie = (decrypt(urldecode($savedcookies[$hash]['enc'])) == 'OK') ? $this->IWillNameItLater($savedcookies[$hash]['cookie']) : '';
			$secretkey = $_secretkey;
			if (empty($this->cookie) || (is_array($this->cookie) && count($this->cookie) < 1)) return $this->Login($user, $pass);

			$page = $this->GetPage('http://' . $this->domain . '/', $this->cookie);
			if (stripos($page, '/logout.php">Logout</a>') === false) return $this->Login($user, $pass);
			is_present($page, 'user_icon user_member', 'Account isn\'t premium');
			$this->SaveCookies($user, $pass); // Update cookies file
			return $this->PremiumDL();
		}
		return $this->Login($user, $pass);
	}

	private function SaveCookies($user, $pass, $filename = 'depositfiles_dl.php') {
		global $secretkey;
		$maxdays = 7; // Max days to keep cookies saved
		$filename = DOWNLOAD_DIR . basename($filename);
		if (file_exists($filename)) {
			$file = file($filename);
			$savedcookies = unserialize($file[1]);
			unset($file);

			// Remove old cookies
			foreach ($savedcookies as $k => $v) if (time() - $v['time'] >= ($maxdays * 24 * 60 * 60)) unset($savedcookies[$k]);
		} else $savedcookies = array();
		$hash = hash('crc32b', $user.':'.$pass);
		$_secretkey = $secretkey;
		$secretkey = sha1($user.':'.$pass);
		$savedcookies[$hash] = array('time' => time(), 'enc' => urlencode(encrypt('OK')), 'cookie' => $this->IWillNameItLater($this->cookie, false));
		$secretkey = $_secretkey;

		file_put_contents($filename, "<?php exit(); ?>\r\n" . serialize($savedcookies), LOCK_EX);
	}

	public function CheckBack($header) {
		$statuscode = intval(substr($header, 9, 3));
		if ($statuscode == 400) {
			if (stripos($header, "\nGuest-Limit: Wait") !== false) html_error('[Depositfiles] FreeDL Limit Reached, try downloading again for countdown.');
			else html_error('Error: 400 Bad Request');
		}
	}
}

//[13-1-2013]  Written by Th3-822.
//[20-1-2013] Updated for df's new domains. - Th3-822

?>