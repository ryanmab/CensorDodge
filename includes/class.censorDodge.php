<?php
define('DS', DIRECTORY_SEPARATOR); //Short hand DIR separator value
define('BASE_DIRECTORY', dirname(@get_included_files()[count(get_included_files())-2]).DS); //Compile base DIR for use by script
define('cdURL', (empty($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) == 'off' ? "http" : "https")."://".$_SERVER['HTTP_HOST'].explode('?', $_SERVER['REQUEST_URI'])[0]); //Compile proxy URL base for use by script
if (count(explode("&", $q=$_SERVER['QUERY_STRING']))>count($_GET)) { $_GET = array(); parse_str($q, $_GET); }

class censorDodge {
	public $version = "1.83 BETA";
	public $cookieDIR, $isSSL = "";
	private $URL, $responseHeaders, $HTTP, $getParam, $logToFile, $miniForm = "";
	private $blacklistedWebsites = array("localhost", "127.0.0.1", cdURL);
	private $hotlinkExceptions = array(cdURL);
	private $blacklistedIPs = array();
	private static $pluginFunctions = array();

	//General settings for the 'virtual browser'
	public $encryptURLs = true;
	public $allowCookies = true;
	public $stripJS = false;
	public $stripObjects = false;
	public $customUserAgent = null;
	public $customReferrer = null;

	//Additional settings that are applied for the page request
	public $curlSettings = array();

	function __construct($URL = "", $logToFile = true, $hotlinkExceptions = array(cdURL)) {
		set_time_limit(0); error_reporting(0);
		set_exception_handler(array($this, 'errorHandler')); //Set our custom error handler
		$this->isSSL = !(empty($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) == 'off'); //Check if the proxy is running on a SSL certificate

		if (!$this->createCookieDIR()) { throw new Exception("You need to have the file writing permissions enabled to use Censor Dodge V".$this->version."!"); } //Populate cookieDIR with directory string, and check for permission but don't create the file yet
		$this->logToFile = $logToFile; //Toggle functions used to log page URLs into files
		$this->hotlinkExceptions = $hotlinkExceptions!==false ? array_merge($this->hotlinkExceptions, is_array($hotlinkExceptions) ? $hotlinkExceptions : array()) : false; //Add domains to allow for hotlinking

		//Check that the server meets all the requirements to run proxy sustainably
		if (!(version_compare(PHP_VERSION, $required = "5.1")>=0)) { throw new Exception("You need at least PHP ".$required." to use Censor Dodge V".$this->version."!"); }
		if (!function_exists('curl_init')) { throw new Exception("You need to enable and configure cURL to use Censor Dodge V".$this->version."!"); }
		if (!is_callable(array(@new DOMDocument(),"loadHTML"))) { throw new Exception("You need to have DOMDocument installed and enabled to use Censor Dodge V".$this->version."!"); }

		if (!empty($URL)) {
			header('Content-Type:'); header('Cache-Control:'); header('Last-Modified:');
			$this->getParam = array_search($URL,$_GET,true); //Find GET param for resubmission later
			if (empty($this->getParam)) { $this->getParam = "URL"; if (isset($_GET[$this->getParam])) { $URL = @$_GET[$this->getParam]; } } //Create a GET parameter if one isn't found
			if (isset($_POST[substr(md5("cdGET"),0,20)])) { $_GET = array_merge($_GET, $_POST); $_POST = array(); } //Move POST params to GET if needed
			for ($e=$URL; strlen($e)>0; $e=substr($e,0,strlen($e)-1)) {
				if (strlen($e) % 4 != 0 || !($decode = base64_decode($e,true))) { continue; }
				if (filter_var("http://".trim($decode)."/", FILTER_VALIDATE_URL)) { $URL = str_replace($e, $decode, $URL); break; }
			}
			$this->URL = $this->modifyURL($URL); //Fix any formatting issues with the URL so it is resolvable
		}

		$form = "<div id='miniForm' style='z-index: 9999999999; position: fixed; left:15px; top:10px;'><form style='display:inline;' onsubmit='goToPage();' id='miniFormBoxes' action='".cdURL."'><input type='text' autocomplete=\"off\" style='all:initial; background:#fff; border:1px solid #a9a9a9; padding:3px;border-radius:2px;' placeholder='URL' value='' name='cdURL'>
            <input type='submit' style='all:initial; cursor:pointer; margin-left:5px; margin-right:5px; border-radius:2px;background:#fff; border:1px solid #989898; padding:3px; background: linear-gradient(to bottom, #f6f6f6 0%,#dedede 100%);' value='Go!'></form>
            <span id='toggle' style='all:initial; cursor:pointer; display:none; background:#fff; border:1px solid #ccc; border-radius:7px; padding:5px 10px 5px 10px;' onclick=\"var box = document.getElementById('miniFormBoxes'); if (box.style.display=='none') { box.style.display = 'inline'; this.innerHTML = 'X'; } else { box.style.display = 'none'; this.innerHTML = '+'; }\">+</span></div>";
		$form .= "<script>function goToPage() { event.preventDefault(); if (document.getElementsByName('cdURL')[0].value!='') { var val = document.getElementsByName(\"cdURL\")[0].value; window.location = '?cdURL=' + ".($this->encryptURLs ? 'btoa(val)' : 'escape(val)')."; } } document.getElementById('miniFormBoxes').style.display = 'none'; document.getElementById('toggle').style.display = 'inline-block';</script>";
		$this->addMiniFormCode($form);

		//Load plugins for running functions when ready
		foreach(glob(BASE_DIRECTORY."plugins".DS."*") as $plugin) {
			if (is_dir($plugin)) {
				foreach (glob($plugin.DS."*.php") as $folderPlugin) {
					include("$folderPlugin"); //Load plugin PHP file from folder into script
				}
			}
			elseif (pathinfo($plugin,PATHINFO_EXTENSION)=="php") {
				include("$plugin"); //Load plugin PHP file into script for running later
			}
		}

		$this->callAction("onStart", array("",&$this->URL,$this)); //Run onStart function for plugins
		if ($this->blacklistedIPs && preg_match('~('.implode("|", $this->blacklistedIPs).')~', (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']), $i)) { throw new Exception("You are currently not permitted on this server."); }
	}

	public function errorHandler($exception) {
		if (is_object($exception) && trim(strtolower(@get_class($exception)))=="exception") {
			$message = trim($exception->getMessage()); //Get message from exception

			//If message isn't empty output it to screen, the script will be terminated automatically
			if (!empty($message)) { echo $message; return true; }
		}

		return false;
	}

	public function addMiniFormCode($code) {
		$this->miniForm = $code; //Add the mini-form code to injecting later
		return true;
	}

	public function getResponseHeaders() {
		if (!empty($this->responseHeaders)) {
			return $this->responseHeaders; //Return cURL response headers where possible
		}
		return false;
	}

	public function getURL() {
		return $this->URL; //Return URL as it cannot be accessed publicly
	}

	public function setURL($URL) {
		if (!empty($URL)) {
			$this->URL = $this->modifyURL($URL); //Set the new URL with any changes needed
			return true;
		}

		return false;
	}

	public function makeHotlinkException($referrer) {
		if (!empty($referrer)) {
			foreach ((array)$referrer as $u) {
				if (!empty($u) && is_string($u)) { //Check that the URL is valid
					$this->hotlinkExceptions[] = $u; //Add the hotlink exception to the array
				}
			}

			return true;
		}

		return false;
	}

	public function blacklistWebsite($website) {
		if (!empty($website)) {
			foreach ((array)$website as $u) {
				if (!empty($u) && is_string($u)) { //Check that the URL is valid
					$this->blacklistedWebsites[] = $u; //Add individual URL to the array

					if (preg_match('~('.$u.')~i', $this->URL, $d)) { //Check if URL is permitted, and send an error to the stop script
						if (empty($d[0])) { $d[0] = parse_url(trim($this->URL), PHP_URL_HOST); }
						throw new Exception("Access to ".$d[0]." is not permitted on this server.");
					}
				}
			}

			return true;
		}

		return false;
	}

	public function blacklistIPAddress($IP) {
		if (!empty($IP)) {
			foreach ((array)$IP as $u) {
				if (!empty($u) && is_string($u)) { //Check that the IP is valid
					$this->blacklistedIPs[] = $u; //Add individual IP to the array

					if (preg_match('~('.$u.')~i', (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']), $i)) { //Check if IP is banned, and send an error to deny access
						throw new Exception("You are currently not permitted on this server.");
					}
				}
			}

			return true;
		}

		return false;
	}

	public function proxyURL($URL) {
		$regex = preg_replace(array("~[a-z]+://~i", "~".basename($_SERVER['PHP_SELF'])."~i"),array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"),cdURL)."\?.*?=";
		if (!empty($URL) && !preg_match("~".$regex."~i",$URL)) {
			parse_str($anchor = parse_url($URL,PHP_URL_FRAGMENT), $parseFrag); //Find anchors if any are in original URL
			if ($anchor && count($parseFrag)<=1) { $anchor = "#".$anchor; $URL = str_replace($anchor,"",$URL); } else { $anchor = ""; }

			//Recompile the new proxy URL with anchors if available
			if ($this->encryptURLs) { $URL = base64_encode($URL); } else { $URL = rawurlencode($URL); }
			$URL = cdURL."?".(empty($this->getParam) ? "URL" : $this->getParam)."=".$URL.$anchor;
		}

		return $URL; //Return compiled proxy URL
	}

	public function unProxyURL($URL) {
		$regex = preg_replace(array("~[a-z]+://~i", "~".basename($_SERVER['PHP_SELF'])."~i"),array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"),cdURL)."\?.*?=";
		if (!empty($URL) && preg_match("~".$regex."~i",$URL)) {
			$URL = preg_replace("~".$regex."~i", "", $URL); //Remove everything from the URL except the GET param value
			for ($e=$URL; strlen($e)>0; $e=substr($e,0,strlen($e)-1)) {
				if (strlen($e) % 4 != 0 || !($decode = base64_decode($e))) { continue; }
				if (filter_var("http://".trim($decode), FILTER_VALIDATE_URL)) { $URL = str_replace($e, $decode, $URL); break; }
			}
			$URL = rawurldecode($URL);
		}

		return $URL; //Return decoded proxy URL
	}

	public function modifyURL($URL) {
		if (!preg_match("~^[a-z]+://~is", $URL = htmlspecialchars_decode(stripslashes(trim($URL))))) {
			$currentURL = $this->URL; //Store the URL for the current page
			if ($URL!="/" && $URL!="#" && (@$URL[0]!="/" || strpos(substr($URL,0,3), "./")!==false || substr($URL,0,2)=="//")) {
				while (substr($URL,0,1)=="/") { $URL = substr($URL,1); } //Remove any rogue slashes
				$validDomainName = (preg_match("~^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$~i", $h=parse_url("http://".$URL, PHP_URL_HOST)) && preg_match("~^.{1,253}$~", $h)
				                    && preg_match("~^[^\.]{1,63}(\.[^\.]{1,63})*$~",$h)) && $this->convertExtension(pathinfo($h, PATHINFO_EXTENSION))=="URL";

				if (!$validDomainName && parse_url($currentURL, PHP_URL_HOST) && (!empty($URL) || trim(pathinfo($URL,PATHINFO_EXTENSION)))) {
					$path = parse_url(explode("?",$currentURL)[0], PHP_URL_PATH); //Find path from original URL
					if (pathinfo(pathinfo(explode("?",$currentURL)[0],PATHINFO_BASENAME),PATHINFO_EXTENSION)!="") {
						$path = str_replace(pathinfo(explode("?",$currentURL)[0],PATHINFO_BASENAME),"",$path); //Remove path if needed
					}

					while (substr($path,strlen($path)-1,strlen($path))=="/") { $path = substr($path,0,strlen($path)-1); } //Remove any slashes from end of URL which are not needed
					$URL = (preg_match("~(^\./|\\\\./)~i",$URL) ? preg_replace("~(^\./|/./)~i","/",$URL) : $path."/".$URL); //Recompile the URL so that it is valid
				}
			}

			$scheme = (($s=parse_url($currentURL, PHP_URL_SCHEME))=="" ? "http" : strtolower($s)); //Find a scheme for the URL as none was set originally
			$host = ((isset($validDomainName) && !$validDomainName) || @$URL[0]=="/" ? parse_url($currentURL, PHP_URL_HOST) : "");
			$URL = ($URL=="#" ? $currentURL : $scheme."://".$host.$URL); //Compile all needed URL components
			while(preg_match("~/[A-Za-z0-9_]+/\.\./~",$URL)) { $URL = preg_replace("~/[A-Za-z0-9_]+/\.\./~","/",$URL); } //Convert the "../" to the absolute location
		}

		return str_replace(array(" ","\\"),array("+",""),$URL);
	}

	public static function addAction($function, $event, $case = "") {
		if (function_exists($function) && !empty($function) && !empty($event)) { //Make sure the arguments are valid
			if (!isset(self::$pluginFunctions[$event][$function])) {
				self::$pluginFunctions[$event][$function] = $case; //Add function (and case if specified) to array
				return isset(self::$pluginFunctions[$event][$function]); //Check if array was added properly
			}
		}

		return false;
	}

	public function callAction($event, $vars = array()) {
		if (isset(self::$pluginFunctions[$event])) {
			foreach (@self::$pluginFunctions[$event] as $function => $case) {
				if (@preg_match($case, $this->URL) || empty($case)) { //If needed run against URL for specific case
					@call_user_func_array($function,$vars); //Run plugin function with variables
				}
			}
		}

		return (count(@self::$pluginFunctions[$event])>0 ? true : false); //Return a boolean for if any functions were executed
	}

	public function getRunningPlugins() {
		$plugins = array();
		foreach (get_included_files() as $file) { //Get all loaded files
			if (strpos($file, BASE_DIRECTORY."plugins".DS)!==false) { //Check if file is in plugins folder
				$plugins[] = $file; //File is a plugin, so add it to the array
			}
		}

		return $plugins; //Return any plugins found
	}

	public function getPluginFunctions() {
		$functions = array();
		foreach (@self::$pluginFunctions as $hook => $fns) { //Loop through all the hooks
			foreach ($fns as $name => $case) {
				//Validate the function and then add to array for returning later
				if (function_exists($name)) { $functions[$hook][] = $name; }
			}
		}

		return $functions; //Return the initialised functions
	}

	public function getProxySettings() {
		$hiddenVars = array("version","cookieDIR","isSSL","logToFile","preventHotlinking"); $editableVars = array();
		foreach (get_object_vars($this) as $n => $v) {
			if (is_bool($v) && !in_array($n,$hiddenVars)) {
				$id = 0; $parts = array();
				foreach (str_split($n,1) as $char) {
					if ($char!=strtolower($char) && $parts[$id]!=strtoupper($parts[$id])) { $id++; } elseif ($char=="_") { $id++; continue; }
					@$parts[$id] .= $char;
				}
				$word = trim(ucwords(str_ireplace(array("strip","js"),array("remove","javascript"),implode(" ",$parts))));
				$editableVars[$word] = array($n,$v);
			}
		}

		return $editableVars;
	}

	public function createCookieDIR() {
		$this->cookieDIR = dirname(__FILE__).DS.'cookies'.DS.base64_encode((isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'])).".txt"; //Generate cookie file directory
		return (bool)is_writable((!file_exists(dirname($this->cookieDIR)) ? dirname(__FILE__) : dirname($this->cookieDIR))); //Return whether the cookie directory is writable
	}

	public function clearCookies($serverSide = true, $clientSide = true) {
		//Delete the stored cookie file and clear the browser cookies as well
		if ($clientSide) { foreach (($c=$_COOKIE) as $n => $v) { if ($n!="PHPSESSID") { setcookie($n, '', time() - 1000); setcookie($n, '', time() - 1000, '/'); unset($c[$v]); } } }
		if ($serverSide && file_exists($this->cookieDIR)) { $clearFile = @unlink($this->cookieDIR); } else { $clearFile = true; }

		return (!$serverSide || $clearFile) && (!$clientSide || empty($c));
	}

	public function openPage() {
		if (!empty($this->URL)) {
			$page = ""; $this->callAction("preRequest", array(&$page,&$this->URL,$this)); //Run preRequest function for plugins
			if ($this->hotlinkExceptions!==false) { if (!($i=isset($_SESSION))) { session_start(); } $hl = substr(md5("cdHotlink"),0,20); if (@$_SESSION[$hl]!=true && !preg_match('~('.implode("|", $this->hotlinkExceptions).')~', $_SERVER["HTTP_REFERER"])) { throw new Exception("The use of hotlinking is strictly forbidden on this server!"); } else { $_SESSION[$hl] = true; } if (!$i) { session_destroy(); } else { session_write_close(); } }

			if ($this->allowCookies) { $this->createCookieDIR(); } //If cookies are enabled create the directory
			$return = $this->curlRequest($this->URL, $_GET, $_POST); //Run the cURL function to get the page for parsing

			$this->HTTP = $return["HTTP"]; $this->responseHeaders = $return["headers"]; //Populate the response information values for plugins
			$contentType = explode(";",$this->responseHeaders["content-type"])[0]; $charset = explode("charset=",$this->responseHeaders["content-type"])[1]; //Store content type and charset for parsing
			if (!$this->HTTP) { throw new Exception("Could not resolve host: ".(($h = parse_url($this->URL,PHP_URL_HOST))!="" ? $h : $this->URL)); } //Check that page was resolved right
			if ($this->blacklistedWebsites && preg_match('~('.implode("|", $this->blacklistedWebsites).')~', $this->URL, $d)) { throw new Exception("Access to ".$d[0]." is not permitted on this server."); }
			if ($this->URL!=$return["URL"]) { @header("Location: ".$this->proxyURL($return["URL"])); exit; } //Go to new proxy URL if cURL was redirected there

			$this->logAction($this->HTTP, $this->URL); //Log URL and HTTP code to file
			if (is_null($return["page"])) { return null; } else { $page .= $return["page"]; $return = null; } //Check that content hasn't already been outputted, so needs parsing

			$this->callAction("postRequest", array(&$page,&$this->URL,$this)); //Run postRequest function for plugins

			if (!empty($page) || strlen($page)>0) {
				$this->callAction("preParse", array(&$page,&$this->URL,$this)); //Run preParse function for plugins
				$extension = $this->convertExtension($contentType);
				if (is_object(json_decode($page))) { $extension = "json"; }

				if (in_array($extension,array("html","php"))) {
					preg_match_all('~<script.*?>(.*?)<\/script>~s', $page, $e); for ($i=0,$match="";$i<count($e[1]);$i++,$match=trim(@$e[1][$i])) { if (!empty($match)) { $page = str_replace($match,preg_replace("~<([^>]*)>~i","<\\\\$1>",$match),$page); } }

					$html = new DOMDocument();
					$html->preserveWhiteSpace = false; $html->formatOutput = false;
					@$html->loadHTML(mb_convert_encoding($page, 'HTML-ENTITIES', $charset), LIBXML_HTML_NODEFDTD|LIBXML_HTML_NOIMPLIED);
					$removeElements = array();

					//Parse META redirect URLs, fav icons and content types
					foreach($j=$html->getElementsByTagName("meta") as $i=>$element) {
						if (strtolower($element->getAttribute("http-equiv"))=="refresh") {
							$content = $element->getAttribute("content");

							if (!empty($content)) {
								$modURL = preg_replace("~[\"'](.*?)[\"']~is","$1",@explode("url=", strtolower($content))[1]); //Find URL from content attribute
								if (!empty($modURL)) {
									$moddedURL = $this->proxyURL($this->modifyURL($modURL)); //Fix and then proxy the URL
									$element->setAttribute("content",str_replace($modURL,$moddedURL,$content)); //Change old URL in content attribute
								}
							}
						}
						elseif (strtolower($element->getAttribute("http-equiv"))=="content-type" || $element->getAttribute("charset")) {
							if ($element->getAttribute("charset")) {
								$element->setAttribute("charset", $charset); //Ensure the charset is correctly updated
							}
							else{
								$content = $element->getAttribute("content"); //Change the charset in any meta tags to the returned charset
								$element->setAttribute("content", trim(preg_replace("~(;\s)charset=(.*)~is","$1charset=".$charset,$content)));
							}
						}
						elseif (strtolower($element->getAttribute("itemprop"))=="image" || in_array(strtolower($element->getAttribute("property")), array("og:image","og:url")) || in_array(strtolower($element->getAttribute("rel")), array("shortcut icon","icon"))) {
							$t = ($element->hasAttribute("href") ? "href" : "content"); $modURL = $element->getAttribute($t);

							if (!empty($modURL) && !empty($t)) {
								$moddedURL = $this->proxyURL($this->modifyURL($modURL)); //Fix and proxy URL from href
								$element->setAttribute($t,$moddedURL); //Set href attribute to new URL
							}
						}

						if ((strtolower($element->getAttribute("name"))=="generator" || $j->length-1<=$i) && !isset($u)) {
							$e = $j->length-1<=$i ? $html->getElementsByTagName("head")->item(0)->appendChild($html->createElement('meta')) : $element; $u = true;
							foreach (array("bmFtZQ==" => "Z2VuZXJhdG9y", "Y29udGVudA==" => "aHR0cHM6Ly93d3cuY2Vuc29yZG9kZ2UuY29tLw==") as $k => $t) { $e->setAttribute(base64_decode($k),base64_decode($t)); }
						}
					}

					foreach (array("img","a","area","script","noscript","link","iframe","frame", "base") as $tag) {
						foreach($html->getElementsByTagName($tag) as $element) {
							if ($this->stripJS && $element->tagName=="script") {
								$removeElements[] = $element; //Remove script tags if stripJS is enabled
							}
							elseif ($element->tagName=="noscript") {
								if ($this->stripJS) {
									while($element->hasChildNodes()) { //Check if the noscript element has any content
										$child = $element->removeChild($element->firstChild);
										$element->parentNode->insertBefore($child, $element); //Prepend contents of noscript
									}
									$removeElements[] = $element; //Remove noscript as content has been prepended now
								}
							}
							else{
								if ($element->hasAttribute("data-thumb") && $element->tagName=="img") { $element->setAttribute("src",$element->getAttribute("data-thumb")); $element->setAttribute("data-thumb",$this->proxyURL($element->getAttribute("data-thumb"))); } //Relocate data-thumb vars to src
								if ($element->hasAttribute("data-src") && filter_var($element->getAttribute("data-src"), FILTER_VALIDATE_URL) && $element->tagName=="img") { $element->setAttribute("src",$element->getAttribute("data-src")); $element->setAttribute("data-src",$this->proxyURL($element->getAttribute("data-src"))); } //Relocate data-src vars to src
								if ($element->hasAttribute("srcset")) { $element->removeAttribute("srcset"); } //Remove srcset vars
								if (method_exists($element->parentNode,"hasAttribute")) { if ($element->parentNode->hasAttribute("data-ip-src") && $element->tagName=="img") { $element->setAttribute("src",$element->parentNode->getAttribute("data-ip-src")); $element->setAttribute("data-ip-src",$this->proxyURL($element->getAttribute("data-ip-src"))); } } //Relocate data-ip-src vars to src

								$t = ($element->hasAttribute("href") ? "href" : "src"); $modURL = $element->getAttribute($t);

								if (!preg_match("~^(javascript|mailto|data)\:~is",$modURL) && isset($modURL) && !empty($modURL)) {
									$moddedURL = $this->modifyURL($modURL); //Fix URL from element
									$element->setAttribute($t,$this->proxyURL($moddedURL)); //Use proxyURL then set the element value to it
								}
							}
						}
					}

					foreach (array("video","source","param","embed","object") as $tag) {
						foreach($html->getElementsByTagName($tag) as $element) {
							if (!$this->stripObjects) {
								if ($element->tagName=="embed" || $element->tagName=="source" || $element->tagName=="video") {
									if ($element->getAttribute("src")!="") {
										$moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("src")));
										$element->setAttribute("src", $moddedURL); //Set src attribute of video elements
									}
									if ($element->getAttribute("poster")!="") { $element->setAttribute("poster", $this->proxyURL($this->modifyURL($element->getAttribute("poster")))); } //Modify the poster attribute if needed
								}
								elseif($element->tagName=="object") {
									if ($element->getAttribute("data")!="") {
										$moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("data")));
										$element->setAttribute("data", $moddedURL); //Set data attribute of object elements
									}
								}
								elseif($element->tagName=="param" && $element->getAttribute("name")=="movie") {
									$moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("value")));
									$element->setAttribute("value",$moddedURL); //Set value attribute of param elements
								}
							}
							else{
								$removeElements[] = $element; //Remove element if stripObjects is enabled
							}
						}
					}

					foreach($html->getElementsByTagName("form") as $element) {
						if (!$action = $element->getAttribute("action")) { $action = "#"; }
						$element->setAttribute("action",$this->proxyURL($this->modifyURL($action)));

						if (strtoupper(trim($element->getAttribute("method")))!="POST") {
							$element->setAttribute("method","POST"); //Force method to be POST

							$newE = $html->createElement("input","");
							$newE->setAttribute("type","hidden");
							$newE->setAttribute("name", substr(md5("cdGET"),0,20));

							$element->appendChild($newE); //Add new attribute to be intercepted later
						}

						//Form is multi-part so more parsing needed to allow PHP to use it properly
						if ($element->getAttribute("enctype")=="multipart/form-data") {
							foreach($element->getElementsByTagName("input") as $input) {
								if ($input->getAttribute("name")!="") { //Check for valid input name
									$name = rawurlencode($input->getAttribute("name")); //Safely include names of inputs for key values
									$input->setAttribute("name", str_replace(array('%5B','%5D'), array('[',']'), $name)); //Reinsert name since it has been parsed
								}
							}
						}
					}

					foreach($html->getElementsByTagName("input") as $element) {
						$modURL = $element->getAttribute("formaction");

						if (!empty($modURL)) {
							$moddedURL = $this->modifyURL($modURL); //Fix URL from element
							$element->setAttribute("formaction", $this->proxyURL($moddedURL)); //Use proxyURL then set the element value to it
						}
					}

					if (count($removeElements)>0)  { //Check for any elements to remove
						foreach ($removeElements as $element) {
							$element->parentNode->removeChild($element); //Remove each element
						}
					}

					$page = @$html->saveHTML();
				}

				if (in_array($extension, array("html","php","js","css","json"))) {
					$codeSnippets = array();

					if ($extension=="js") {
						if (!$this->stripJS) {
							$codeSnippets[] = array("regex" => array("~(?<URL>[^\"]+)~", '~(?<URL>[^\']+)~'), "snippets" => (array)$page);  //If the page is JS, add page to array for parsing
						}
					}
					elseif($extension=="json") {
						$codeSnippets[] = array("regex" => array("~(?<URL>[^\"]+)~", '~(?<URL>[^\']+)~'), "snippets" => (array)$page);  //If the page is JSON, add page to array for parsing
					}
					elseif($extension=="css") {
						$codeSnippets[] = array("regex" => array('~(@import([\\S]+|)|\=)[\'"](?<URL>.*?)[\'"]~i',"~url\((\"|'|)(?<URL>\S+)(\"|'|)\s*\)~iU"), "snippets" => (array)$page); //If page is CSS, add page to array for parsing
					}
					else {
						preg_match_all("~<!--(.*?)-->~s", $page, $commentTags); //Find all html comments (could contain if IE code)
						if (!empty($commentTags[0])>0) { $codeSnippets[] = array("regex" => array("~(?<URL>[^\"]+)~", '~(?<URL>[^\']+)~'), "snippets" => $commentTags[0]); } //Add html comments to array

						if (!$this->stripJS) { //Find any JS values if stripJS is set to false
							preg_match_all('~(\son[a-zA-Z]+)\s*=\s*([\"\'])(.*?)\\2~si', $page, $events); //Find attribute scripts
							preg_match_all("~<script.*?>(.*?)<\/script>~is", $page, $scripts); //Find all script elements

							if (!empty($events[3])) {
								$codeSnippets[] = array("regex" => array("~(?<URL>[^\"]+)~", '~(?<URL>[^\']+)~'), "snippets"=>@$events[3]);
							}
							if (!empty($scripts[1])) {
								$codeSnippets[] = array("regex" => array("~(?<URL>[^\"]+)~", '~(?<URL>[^\']+)~'), "snippets"=>@$scripts[1]);
							}
						}

						preg_match_all('~style\s*=\s*([\"\'])(.*?)\\1~si', $page, $inline); //Find inline CSS in attributes
						preg_match_all("~<style.*?>(.*?)<\/style>~is", $page, $styles); //Find all script elements

						if (!empty($inline[2])) {
							$codeSnippets[] = array("regex" => array('~(@import([\\S]+|)|\=)[\'"](?<URL>.*?)[\'"]~i', "~url\((\"|'|)(?<URL>\S+)(\"|'|)\s*\)~iU"), "snippets"=>@$inline[2]);
						}
						if (!empty($styles[1])) {
							$codeSnippets[] = array("regex" => array('~(@import([\\S]+|)|\=)[\'"](?<URL>.*?)[\'"]~i', "~url\((\"|'|)(?<URL>\S+)(\"|'|)\s*\)~iU"), "snippets"=>@$styles[1]);
						}
					}

					//Sort snippets in descending order (better for fast parsing)
					usort($codeSnippets, function ($a, $b) { return count($b["snippets"])-count($a["snippets"]); });

					foreach ($codeSnippets as $key => $snippet) {
						foreach ($snippet["snippets"] as $snippetIndex => $codeSnippet) {
							if (strpos($page,$codeSnippet)===false) { continue; } //Check code hasn't already been overwritten

							foreach ($snippet["regex"] as $regex) {
								preg_match_all($regex,$codeSnippet,$jcURLs); //Look for any possible URLs in the code

								for($c=0,$modURL=$jcURLs["URL"][0]; count($jcURLs["URL"])>$c; $c++, $modURL = $jcURLs["URL"][$c]) {
									$ext = ""; $moddedURL = preg_replace("~[\\\\]+/~is","/",urldecode($modURL)); //Standardise URL to make sure its readable
									$moddedURL = preg_replace_callback('~\\\\u([0-9a-f]{4})~i', (function ($match) use ($charset) { return mb_convert_encoding(pack('H*', $match[1]), $charset, 'UCS-2BE'); }), $moddedURL);

									if (strpos($moddedURL,".")!==false && $moddedURL!=".") { //Attempt to establish a extension (com, co.uk, js, php, ect)
										$ext = pathinfo(parse_url(explode("?", $moddedURL)[0],PHP_URL_HOST),PATHINFO_EXTENSION); //Try multiple method of getting a valid extension
										if (empty($ext)) { $ext = pathinfo(parse_url("http://".explode("?", $moddedURL)[0], PHP_URL_HOST),PATHINFO_EXTENSION); }
										if (empty($ext)) { $ext = pathinfo(explode("?",preg_replace('~#.*~', '', $moddedURL))[0], PATHINFO_EXTENSION); }
									}

									$extType = $this->convertExtension($ext); //Convert extension to content type to check if URL is valid
									if (!empty($extType) && $moddedURL!=".".$ext && strpos($moddedURL,"'")===false && !(($moddedURL[0]=="." && strpos(substr($moddedURL,0,3),"./")===false) || $moddedURL[0]=="$") && strpos($moddedURL," ")===false) {
										$filter = "http://".explode("?",preg_replace("~^(http(s):|)(//|\.|\/)~is","",$moddedURL))[0];

										//Check if string is a URL of some kind, if so edit it as required
										if (($extType!="URL" && pathinfo($filter,PATHINFO_FILENAME)!="")
										    || ($extType=="URL" && filter_var($filter, FILTER_VALIDATE_URL)!==false)) {
											$moddedURL = $this->proxyURL($this->modifyURL($moddedURL)); //Modify the URL and then proxy it
											$codeSnippet = preg_replace('~'.preg_quote($modURL).'~', $moddedURL.(substr($modURL, -1) =="\\" ? "\\" : ""), $codeSnippet, 1); //Add the new URL back into the array
										}
									}
								}
							}
							if ($codeSnippet!=@$codeSnippets[$key]["snippets"][$snippetIndex]) { $page = str_replace($codeSnippets[$key]["snippets"][$snippetIndex], $codeSnippet, $page); } //Replace old code with fixed code in JS values
						}
					}
				}

				if (in_array($extension, array("html","php"))) {
					//Attempt to find a text box to place the URL in, it isn't necessary though
					$this->miniForm = preg_replace(array("~(type\=[\"']text[\"'].*value\=[\"'])([\"'])~i", "~(value\=[\"'])([\"'].*type\=[\"']text[\"'])~i"),"$1".$this->URL."$2",$this->miniForm);
					$injectCode = "<div style='".base64_decode("cG9zaXRpb246Zml4ZWQ7IGJhY2tncm91bmQ6IzAwMDsgcG9pbnRlci1ldmVudHM6IG5vbmU7IHotaW5kZXg6OTk5OTk5OTk5OTsgcmlnaHQ6MTVweDsgYm90dG9tOjEwcHg7IHBhZGRpbmc6NXB4OyBvcGFjaXR5OjAuMzsgY29sb3I6I2ZmZjsgZm9udC1zaXplOjEzcHg7IGZvbnQtZmFtaWx5OiAiVGltZXMgTmV3IFJvbWFuIiwgc2VyaWY7IGxpbmUtaGVpZ2h0OiBpbml0aWFsOyB3aWR0aDphdXRvOw==")."'>".sprintf(base64_decode("UG93ZXJlZCBCeSBDZW5zb3IgRG9kZ2UgViVz"),$this->version)."</div>";

					$page = preg_replace("~<body( ([^>]*)|)>~is", "<body$1>".PHP_EOL.preg_replace('~[\s\t\n\r\s]+~', ' ', $this->miniForm).$injectCode, $page); //Inject the mini-form code to the top of the body
				}

				$this->callAction("postParse", array(&$page,&$this->URL,$this)); //Run postParse function for plugins
			}
			else {
				throw new Exception("Unable to load page content."); //Page was resolved but no content was returned
			}

			$this->callAction("onFinish", array(&$page,&$this->URL,$this)); //Run onFinish function for plugins
			return $page; //Return fully parsed page
		}

		return null; //Return null as no URL was set
	}

	private function convertExtension($convert) {
		$rules = array(
			"text/javascript" => "js",
			"application/javascript" => "js",
			"application/x-javascript" => "js",
			"application/x-shockwave-flash" => "swf",
			"audio/x-wav" => "wav",
			"video/quicktime" => "mov",
			"video/x-msvideo" => "avi",
			"text/html" => array("html","htm"),
			"text/*" => array("php","css","xml","plain"),
			"application/*" => array("pdf","zip","xml","rss","xhtml"),
			"font/*" => array("ttf","otf","woff","eot"),
			"image/*" => array("jpeg","jpg","gif","png","svg"),
			"video/*" => array("3gp","mreg","mpg","mpe","mp3"),
			"application/json" => "json",
			"URL" => array("a[c-gilmoq-uwxz]","arpa","asia","b[abd-jm-or-twyz]","biz","c[acdf-ik-oru-z]","cat","com","coop","d[ejkmoz]","e[cegr-u]","edu","f[i-kmor]","g[ad-il-np-uwy]",
				"gov","h[kmnrtu]","icu","i[del-oq-t]","info","int","j[emop]","jobs","k[eg-imnprwyz]","l[a-cikr-vy]","m[ac-eghk-z]","mil","mobi","museum","n[ace-gilopruz]",
				"net","om","onion","org","p[ae-hkmnr-twy]","post","pro","qa","r[eosuw]","s[a-eg-ik-ort-vx-z]","t[cdf-hj-otvwz]","travel","u[agksyz]","v[aceginu]","w[fs]","y[te]","xxx","z[amw]")
		);

		if (!empty($convert)) {
			$isContentType = strpos($convert,"/")!==false; //Check if value is a content type
			$cExt = ""; if ($isContentType) { $cExt = explode("/",$convert)[1]; }
			foreach ($rules as $key => $ext) {
				if (str_replace("*",$cExt,$key)==$convert || !$isContentType) {
					foreach((array)$ext as $e) {
						if ($isContentType && (preg_match("~^".$e."$~i", explode("+",$cExt)[0]) || count($ext)==1)) {
							return $e; //Return validated content type
						}
						elseif (!$isContentType && preg_match("~^".$e."$~i", $convert)) {
							return str_replace("*",$e,$key); //Return validated extension
						}
					}
				}
			}
		}

		return false; //No matching conversion has been found
	}

	public function logAction($HTTP, $URL) {
		if ($this->logToFile && !empty($URL)) {
			$dir = BASE_DIRECTORY.DS."logs".DS; if (!file_exists($dir)) { mkdir($dir, 0777); } //Create logs DIR if not found already
			$line = "[".date("H:i:s d-m-Y")."][".(isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'])."][$HTTP] ".$URL.PHP_EOL;
			$attempt = file_put_contents($dir.date("d-m-Y").".txt", $line, FILE_APPEND | LOCK_EX);

			return ($attempt!==false); //Return whether the write was successful
		}

		return false; //Logging is disabled or no URL was passed
	}

	public function clearLogs() {
		$files = glob(BASE_DIRECTORY.DS."logs".DS."*");
		foreach ($files as $n => $file) { if (@unlink($file)) { unset($files[$n]); } } //Delete all log files in the folder

		return empty($files); //Return whether the logs folder is empty
	}

	public function parseLogFile($logFileName = "ALL") {
		$parsedFile = array();

		if (file_exists(BASE_DIRECTORY.DS."logs".DS.$logFileName) || trim(strtoupper($logFileName))=="ALL") {
			if (trim(strtoupper($logFileName))=="ALL") { $logFileName = "*.txt"; } //Loop through all files with when flagged as "ALL" files
			foreach (glob(BASE_DIRECTORY."logs".DS.$logFileName) as $file) {
				if ($handle = fopen($file, "r")) {
					while (($line = fgets($handle)) !== false) {
						preg_match("~\[(.*?)\]\[([0-9\.\:]+)\]\[([a-zA-Z0-9]+)\]\s(.*?)~isU", $line, $matches); unset($matches[0]); //Parse format of log files
						if (count($matches)>0) { $parsedFile[] = array_combine(array("time", "IP", "HTTP", "URL"), $matches); } //Add array to complete parsed file array
					}
					fclose($handle);
				}
			}
		}

		return $parsedFile; //Return array format of file
	}

	public function sortParsedLogFile($parsedLogArray, $sortingVar) {
		$sortedArray = array();

		if ((is_array($parsedLogArray) && count($parsedLogArray)>0) && !empty($sortingVar)) { //Check for all needed vars before sorting
			foreach ($parsedLogArray as $log) {
				$var = @array_change_key_case($log, CASE_LOWER)[trim(strtolower($sortingVar))]; //Make array lookup of sorting var case insensitive

				if (parse_url(trim(explode("?",$var)[0]),PHP_URL_HOST)) { //Check if string is URL, and normalize it for better sorting
					preg_match("~(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$~i", parse_url(trim(explode("?",$var)[0]),PHP_URL_HOST), $d);
					if (isset($d["domain"]) && !empty($d["domain"])) { $var = $d["domain"]; } else { $var = parse_url(trim($var),PHP_URL_HOST); }
				}

				if (!is_array(@$sortedArray[$var])) { @$sortedArray[$var] = array(); } //Set a default value for each item
				$sortedArray[$var][] = $log; //Add new log to into the array
			}
		}

		return $sortedArray; //Return dynamically sorted array
	}

	public function curlRequest($URL, $getParameters = array(), $postParameters = array()) {
		unset($shouldStream); unset($getParameters[$this->getParam]); unset($getParameters[substr(md5("cdGET"),0,20)]);
		$insideOrigin = (bool)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]["class"]==get_class($this));

		$curl = curl_init((count($getParameters)>0) ? $URL.(strpos($URL,"?")===false ? "?" : "&").http_build_query($getParameters) : $URL); //Add GET params to base URL
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); //Allow cURL to download the source code
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); //Follow any page redirects provided in headers
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_ENCODING, "gzip, UTF-8, deflate"); //Force encoding to be UTF-8, gzip or deflated
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Accept:")); //Add a basic Accept header to emulate browser headers
		curl_setopt($curl, CURLOPT_NOPROGRESS, true); //Save memory and processing power by disabling calls to unused progress callbacks
		curl_setopt($curl, CURLOPT_RANGE, isset($_SERVER['HTTP_RANGE']) ? substr($_SERVER['HTTP_RANGE'], 6) : null);

		curl_setopt_array($curl, array( //Add some settings to make the cURL request more efficient
			CURLOPT_TIMEOUT => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_DNS_CACHE_TIMEOUT => 200,
			CURLOPT_SSL_VERIFYHOST => ($this->isSSL ? 2 : 0), CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_LOW_SPEED_LIMIT => 5, CURLOPT_LOW_SPEED_TIME => 20,
		));

		curl_setopt($curl, CURLOPT_WRITEFUNCTION, (function ($curl, $p) use (&$body, &$headers, &$shouldStream, $insideOrigin) {
			if (!is_bool($shouldStream)) {
				$shouldStream = (!preg_match("~(application|text)/~i",$headers["content-type"]) || $headers["content-length"]>5000000) && $insideOrigin; //Check if the file needs to be parsed (stream type, or content length is larger than 5MB)
				if ($shouldStream) { if (preg_match("~2[0-9]+~i", $headers[0])) { header($headers[0]); } header("Content-Length: ".$headers["content-length"]); }
			}
			if ($shouldStream && $curl) { $body = null; echo $p; }  else { $body .= $p; }
			return strlen($p);
		}));
		curl_setopt($curl, CURLOPT_HEADERFUNCTION, (function ($curl, $hl) use ($URL, &$headers, $insideOrigin) {
			$allowedHeaders = array('content-disposition', 'last-modified', 'cache-control', 'content-type', 'content-language', 'expires', 'pragma', 'accept-ranges', 'content-range');
			$split = explode(":",$hl,2); $hn = trim(strtolower((count($split)>1 ? $split[0] : count($headers)))); $hv=trim(strtolower($split[(count($split)>1 ? 1 : 0)])); //Split the header into the name and value respectively
			if (in_array($hn, $allowedHeaders) && $insideOrigin && $curl) { $headers[$hn] = $hv; header($hl); } elseif (!empty(trim($hl))) { $headers[$hn] = $hv; } //Control which headers are set as we receive them from cURL
			return strlen($hl);
		}));

		//Set user agent, referrer, cookies and post parameters based on 'virtual' browser values
		if (!is_null($this->customUserAgent)) { curl_setopt($curl, CURLOPT_USERAGENT, $this->customUserAgent); } else { curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); }
		if (!is_null($this->customReferrer)) { curl_setopt($curl, CURLOPT_REFERER, $this->customReferrer); } else { curl_setopt($curl, CURLOPT_REFERER, (!preg_match("~".preg_replace(array("~[a-z]+://~i", "~".basename($_SERVER['PHP_SELF'])."~i"), array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"), cdURL)."~is", $r = $this->unProxyURL(@$_SERVER["HTTP_REFERER"]))) ? $r : "" ); }
		if ($this->allowCookies) { $cookies = $_COOKIE; unset($cookies["PHPSESSID"]); $cs = ""; foreach( $cookies as $key => $value ) {  if (!is_array($value)) { $cs .= "$key=".$value."; "; } } curl_setopt($curl, CURLOPT_COOKIE, $cs); if (!file_exists($p=pathinfo($this->cookieDIR,PATHINFO_DIRNAME))) { mkdir($p); } curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookieDIR); curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookieDIR); } //Set cookie file in cURL
		foreach ($_FILES as $upload => $files) { for ($i=0; $i<count($files["name"]); $i++) { if ($files["error"][$i]==false) { $name = $upload.(count($files["name"])>1 ? "[$i]" : ""); $postParameters[$name] = new CURLFile($files["tmp_name"][$i], $files["type"][$i], $files["name"][$i]); } } } //Parse any uploaded files into the POST values for submission
		if (count($postParameters)>0) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, (count($_FILES)>0 ? $postParameters : http_build_query($postParameters))); } //Send POST values using cURL
		curl_setopt_array($curl, $this->curlSettings); //Add additional cURL settings array before running

		curl_exec($curl); //Run request with settings added previously
		$vars = array("URL" => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
		              "HTTP" => curl_getinfo($curl, CURLINFO_HTTP_CODE), "headers" => $headers,
		              "error" => curl_error($curl), "page" => $body,
		);

		curl_close($curl); //Close cURL connection safely once complete
		return $vars;
	}
}