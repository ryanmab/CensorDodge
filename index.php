<?php
session_start(); //Start session for settings of proxy to be stored and recovered
require("includes/class.censorDodge.php"); //Load censorDodge class
$proxy = new censorDodge(@$_GET["cdURL"], true, true); //Instantiate censorDodge class

//Clear cookies and resetting settings session
if (isset($_GET["clearCookies"])) { $proxy->clearCookies(); echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; }
if (isset($_POST["resetSettings"])) { unset($_SESSION["settings"]); echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; }

$settings = $proxy->getProxySettings(); //Get all settings (plugins included) that are user intractable

//Update settings in session for changing in proxy later
if (isset($_POST["updateSettings"])) {
    foreach ($settings as $setting) {
        if (isset($proxy->{$setting[0]})) {
            $_SESSION["settings"][$setting[0]] = isset($_POST[$setting[0]]); //Store settings in session for later
            $proxy->{$setting[0]} = isset($_POST[$setting[0]]); //Update proxy instance settings
        }
    }

    echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; //Reload page using META redirect
}
else {
    foreach ($settings as $setting) {
        if (isset($proxy->{$setting[0]}) && isset($_SESSION["settings"][$setting[0]])) {
            $proxy->{$setting[0]} = $_SESSION["settings"][$setting[0]]; //Update proxy instance settings
        }
    }
}

//Find any templates which can be used as themes components
$templates = array(); foreach(glob(BASE_DIRECTORY."plugins".DS."{**/*,*}",GLOB_BRACE) as $file) { if (preg_match("~([a-z0-9\_\-]+)\.cdTheme~i",$file,$m)) { $templates[$m[1]] = $file; } }
if (@$templates["error"]) { set_exception_handler(function($e) use ($proxy,$settings,$templates) { if ($errorString=$e->getMessage()) { include("".$templates["error"].""); }}); }
if (@$templates["miniForm"]) { ob_start(); include("".$templates["miniForm"].""); $output = ob_get_contents(); ob_end_clean(); $proxy->addMiniFormCode($output); }

if (!@$_GET["cdURL"]) { //Only run if no URL has been submitted
    if (!@$templates["home"]) {
        echo "<html><head><title>".ucfirst(strtolower($_SERVER['SERVER_NAME']))." - Censor Dodge ".$proxy->version."</title><meta name='generator' content='https://www.censordodge.com'></head><body>"; //Basic title

        //Basic submission form with base64 encryption support
        echo "
        <script>function goToPage() { event.preventDefault(); var URL = document.getElementsByName('cdURL')[0].value; if (URL!='') { window.location = '?cdURL=' + ".($proxy->encryptURLs ? 'btoa(URL)' : 'URL')."; } }</script>
        <h2>Welcome to <a target='_blank' style='color:#000 !important;' href='https://www.censordodge.com/'>Censor Dodge ".$proxy->version."</a></h2>
        <form action='#' method='GET' onsubmit='goToPage();'>
            <input type='text' size='30' name='cdURL' placeholder='URL' required>
            <input type='submit' value='Go!'>
        </form>";

        echo "<hr><h3>Proxy Settings:</h3><form action='".cdURL."' method='POST'>";
        foreach($settings as $name => $setting) { //Toggle option for setting listed in array, completely dynamic
            echo '<span style="padding-right:20px;"><input type="checkbox" '.($proxy->{$setting[0]} ? "checked" : "") .' name="'.$setting[0].'" value="'.$setting[1].'"> '.$name."</span>";
        }
        echo "<br><input style='margin-top: 20px;' type='submit' name='updateSettings' value='Update Settings'><form action='".cdURL."' method='POST'><input style='margin-left: 5px;' type='submit' value='Reset' name='resetSettings'></form></form>";

        $file = $proxy->parseLogFile(date("d-m-Y").".txt"); //Parse log file of current date format
        echo "<hr><h3>Pages Viewed Today (Total - ".count($file)." By ".count($proxy->sortParsedLogFile($file, "IP"))." Users):</h3>";

        if (count($views = $proxy->sortParsedLogFile($file, "URL"))>0) {
            echo "<table><thead><td><b>Website</b></td><td><b>View Count</b></td></thead>"; //Table title
            foreach($views as $URL => $logs)  {
                echo "<tr><td style='padding-right: 80px;'>".$URL."</td><td>".count($logs)."</td></tr>"; //Table row for each parsed log
            }
            echo "</table>";
        }
        else {
            echo "<p>No pages have been viewed yet today!</p>"; //No logs in file so just display generic message
        }

        if (file_exists($proxy->cookieDIR)) {
            echo "<hr><h3>Cookie File - <a href='?clearCookies'>[Delete File]</a>:</h3>"; //Option to delete file
            echo "<p style='word-wrap: break-word;'>".nl2br(wordwrap(trim(file_get_contents($proxy->cookieDIR)),190,"\n",true))."</p>"; //Output cookie file to screen
        }
        else {
            echo "<hr><h3>Cookie File:</h3>";
            echo "<p>No cookie file could be found!</p>"; //No file found so just display generic message
        }
        echo "</body></html>";
    }
    else {
        include("".$templates["home"]."");
    }
}
else {
    echo $proxy->openPage(); //Run proxy with URL submitted when proxy class was instantiated
}