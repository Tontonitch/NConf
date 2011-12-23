<?php
header('Content-type: text/html; charset=utf-8');
session_start();

// Functions
function getBrowser()
{
    $u_agent = $_SERVER['HTTP_USER_AGENT'];
    $bname = 'Unknown';
    $platform = 'Unknown';
    $version= "";

    //First get the platform?
    if (preg_match('/linux/i', $u_agent)) {
        $platform = 'linux';
    }
    elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
        $platform = 'mac';
    }
    elseif (preg_match('/windows|win32/i', $u_agent)) {
        $platform = 'windows';
    }
   
    // Next get the name of the useragent yes seperately and for good reason
    if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent))
    {
        $bname = 'Internet Explorer';
        $ub = "MSIE";
    }
    elseif(preg_match('/Firefox/i',$u_agent))
    {
        $bname = 'Mozilla Firefox';
        $ub = "Firefox";
    }
    elseif(preg_match('/Chrome/i',$u_agent))
    {
        $bname = 'Google Chrome';
        $ub = "Chrome";
    }
    elseif(preg_match('/Safari/i',$u_agent))
    {
        $bname = 'Apple Safari';
        $ub = "Safari";
    }
    elseif(preg_match('/Opera/i',$u_agent))
    {
        $bname = 'Opera';
        $ub = "Opera";
    }
    elseif(preg_match('/Netscape/i',$u_agent))
    {
        $bname = 'Netscape';
        $ub = "Netscape";
    }
   
    // finally get the correct version number
    $known = array('Version', $ub, 'other');
    $pattern = '#(?<browser>' . join('|', $known) .
    ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';
    if (!preg_match_all($pattern, $u_agent, $matches)) {
        // we have no matching number just continue
    }
   
    // see how many we have
    $i = count($matches['browser']);
    if ($i != 1) {
        //we will have two since we are not using 'other' argument yet
        //see if version is before or after the name
        if (strripos($u_agent,"Version") < strripos($u_agent,$ub)){
            $version= $matches['version'][0];
        }
        else {
            $version= $matches['version'][1];
        }
    }
    else {
        $version= $matches['version'][0];
    }
   
    // check if we have a number
    if ($version==null || $version=="") {$version="?";}
   
    return array(
        'userAgent' => $u_agent,
        'name'      => $bname,
        'version'   => $version,
        'platform'  => $platform,
        'pattern'    => $pattern
    );
} 

// Load basic nconf files and modules
if (file_exists('config/nconf.php')){
    require_once('main.php');
}

// Clean cache (session)
if ( isset($_GET["clear"]) ){
    if ( !empty($_GET["class"]) ){
        unset($_SESSION["cache"][$_GET["class"]]);
    }else{
        unset($_SESSION["cache"]);
    }
}


// Logout
if ( isset($_GET["logout"]) ){
    if ( defined("AUTH_METHOD") AND AUTH_METHOD == "basic") {
        unset($_SERVER['PHP_AUTH_USER']);
        unset($_SERVER['PHP_AUTH_PW']);
        //$_POST["authenticate"] = 1;
        $auth_logout = TRUE;
        // HTTP Auth is some kind of special, there is no logout possibility
        // Send authentication after logout prevents user to stay authenticated
        Header("WWW-Authenticate: Basic realm=\"".BASICAUTH_REALM."\"");
        Header("HTTP/1.0 401 Unauthorized");
    }
    // Unset all of the session variables.
    $_SESSION = array();
    
    // If it's desired to kill the session, also delete the session cookie.
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_unset();
}

if ( defined("AUTH_METHOD") AND AUTH_METHOD == "basic" && !isset($_SESSION['group']) ){
    unset($_GET["logout"]);
    $_POST["authenticate"] = 1;
}

// Authenticate
if (AUTH_ENABLED == 1){
    if ( isset($_POST["authenticate"]) AND empty($auth_logout)){
        # check credentials
        require_once(NCONFDIR.'/include/login_check.php');
    }
    # Basic authentication and not yet authorized
    if ( defined("AUTH_METHOD") AND AUTH_METHOD == "basic" && !isset($_SESSION['group']) ){
        if ( defined("BASICAUTH_REALM") ){
            $realm = BASICAUTH_REALM;
        }else{
            $realm = "NConf Basic Auth";
        }
        Header("WWW-Authenticate: Basic realm=\"" . $realm . "\"");
        Header("HTTP/1.0 401 Unauthorized");
    }

}else{
    // NO authentication
    $_SESSION['group'] = GROUP_ADMIN;
    $_SESSION["userinfos"]['username'] = GROUP_ADMIN;
    message($debug, 'authentication is disabled');
    message($debug, $_SESSION["group"].' access granted');
}



# create Permission class
$NConf_PERMISSIONS = new NConf_PERMISSIONS;

?>




<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
    <meta http-equiv="Content-type" content="text/html;charset=UTF-8">
    <?php
    // Choose template file from config
    if ( defined('TEMPLATE_DIR') ){
        echo '<link rel="stylesheet" type="text/css" href="design_templates/'.TEMPLATE_DIR.'/new.css">';
        echo '<link rel="stylesheet" type="text/css" href="design_templates/'.TEMPLATE_DIR.'/main.css">';
        echo '<link rel="shortcut icon" href="design_templates/'.TEMPLATE_DIR.'/favicon.ico">';
    }
    ?>

    <!-- Load nconf js functions -->
    <script src="include/js/nconf.js" type="text/javascript"></script>
    <script src="include/js/ajax.js" type="text/javascript"></script>

    
    <?php
    if ( defined('AUTO_COMPLETE') ){
        echo '
        <!-- Load autocomplete -->
        <script src="include/modules/sunrise/autocomplete/autocomplete.js" type="text/javascript"></script>
        <script src="include/modules/sunrise/autocomplete/ajax_ip.js" type="text/javascript"></script>
        ';
    }

    /* Load juery script files */
    include_once('design_templates/'.TEMPLATE_DIR.'/jQuery/init.php');
    if ( defined('JQUERY') AND JQUERY == 1 ){
        echo '<!-- Load jQuery -->
            <script src="include/js/jquery.js" type="text/javascript"></script>
            <script src="include/js/jquery-ui.custom.min.js" type="text/javascript"></script>
            ';
        echo '<!-- Load jQuery plugins (also nconf-jquery plugins/functions -->
            <script src="include/js/jquery_plugins/jquery.nconf_ajax_debug.js" type="text/javascript"></script>
            <script src="include/js/jquery_plugins/jquery.nconf_help_admin.js" type="text/javascript"></script>
            <script src="include/js/jquery_plugins/jquery.nconf_tooltip.js" type="text/javascript"></script>
            <script src="include/js/jquery_plugins/jquery.nconf_accordion_list.js" type="text/javascript"></script>
            <script src="include/js/jquery_plugins/jquery.nconf_head.js" type="text/javascript"></script>
            ';

        // jquery theme switcher
        
        if ( defined('JQUERY_THEME_SWITCHER') AND JQUERY_THEME_SWITCHER == 1 ){
            echo '<script type="text/javascript" src="include/js/themeswitchertool.js"></script>';
            echo js_prepare("
                  $(document).ready(function(){
                    $('#switcher').themeswitcher({
                        height: 450
                    });
                  });
            ");
        }
        

    }

    


    /* NConf design by jQuery UI Themes*/
    if ( !defined("JQUERY_THEME") ) define("JQUERY_THEME", "nconf");
    echo '<link rel="stylesheet" type="text/css" href="design_templates/'.TEMPLATE_DIR.'/jQuery/'.JQUERY_THEME.'/jquery-ui.custom.css">';

    echo '<link rel="stylesheet" type="text/css" href="design_templates/'.TEMPLATE_DIR.'/jQuery/jquery.table.css">';
    echo '<link rel="stylesheet" type="text/css" href="design_templates/'.TEMPLATE_DIR.'/jQuery/nconf-widget.css">';

    ?>

    <title>NConf</title>
</head>




<body>
    <div id="switcher" style="position: absolute; right: 0"></div>
<div id="title">
    <center>
        <div id="logo"></div>
    </center>
</div>
<div id="titlesub">
    <center>
        <div>
            <table>
                <tr>
                    <td>Welcome&nbsp;<?php if( isset($_SESSION["userinfos"]['username']) ) echo $_SESSION["userinfos"]['username']; ?></td>
                    <td><div align="right"><a title="Get help on nconf.org" class="jQ_tooltip" href="http://www.nconf.org/dokuwiki/doku.php?id=nconf:help:main" target="_blank">[ Help ]</a></div></td>
                    <td><div align="right"><?php $not_compatible_browser = array('Internet Explorer 7.0','Internet Explorer 8.0');
                    $ua=getBrowser(); $yourbrowser = $ua['name'] . " " . $ua['version'];
                    if( in_array($yourbrowser, $not_compatible_browser )) echo "<span style=\"color:#D00000\">Unsupported browser !</span>"; ?>&nbsp;&nbsp;<a title="Get help on nconf.org" class="jQ_tooltip" href="http://www.nconf.org/dokuwiki/doku.php?id=nconf:help:main" target="_blank">[ Help ]</a></div></td>
                </tr>
            </table>
        </div>
    </center>
</div>
<div id="mainwindow">
    <?php
    if ( isset($_SERVER["REQUEST_URI"]) AND preg_match( '/'.preg_quote('INSTALL.php').'/', $_SERVER['REQUEST_URI']) ){
        # Installation
        require_once(NCONFDIR."/include/menu/menu_start.html");
        require_once(NCONFDIR."/include/menu/menu_install.php");
        require_once(NCONFDIR."/include/menu/menu_end.php");

        echo '<div id="maincontent">';
    }elseif ( ( isset($_SERVER["REQUEST_URI"]) AND preg_match( '/'.preg_quote('UPDATE.php').'/', $_SERVER['REQUEST_URI']) )
            AND (file_exists('config/nconf.php')) ){
        # UPDATE
            require_once(NCONFDIR."/include/menu/menu_start.html");
            require_once(NCONFDIR."/include/menu/menu_update.php");
            require_once(NCONFDIR."/include/menu/menu_end.php");

        echo '<div id="maincontent">';
    }elseif ( ( isset($_SERVER["REQUEST_URI"]) AND preg_match( '/'.preg_quote('UPDATE.php').'/', $_SERVER['REQUEST_URI']) )
            AND (!file_exists('config/nconf.php')) ){
        # UPDATE not possible when nconf not installed yet
        echo '<div id="maincontent">';
                message($critical, 'Setup required. To install NConf <b><a href="INSTALL.php">click here</a></b><br>');
    }else{
        # not a install or update call
        if ( file_exists('config/nconf.php') AND (!file_exists('INSTALL.php') AND !file_exists('INSTALL') )
            AND ( !file_exists('UPDATE.php') AND !file_exists('UPDATE') )  ){
            # check must have vars / constanst
            # when something fails, will set $error
            require_once(NCONFDIR."/include/check_vars.php");
            require_once(NCONFDIR."/include/check_files.php");

            # basic DB check (is there a class and a attribute)
            $query = 'SELECT * FROM ConfigAttrs LIMIT 1;';
            $check_ConfigAttrs = db_handler($query, "num_rows", "Check ConfigAttrs content");
            $query = 'SELECT * FROM ConfigClasses LIMIT 1;';
            $check_ConfigClasses = db_handler($query, "num_rows", "Check ConfigClasses content");
            if ( !($check_ConfigAttrs AND $check_ConfigClasses) ){
                message($critical, 'NConf has detected a possible database problem.<br>Check database connection settings, credentials and permissions.<br>For system requirements and installation instructions, please refer to the NConf documentation.<br>');
            }
            if ( NConf_DEBUG::status('CRITICAL') OR NConf_DEBUG::status('ERROR') ){
                # do not show a menu if there is a error/critical
                    echo '<div id="centercontent">';
            }else{
                if ( !isset($_SESSION["group"]) ) {

                    # User seems not logged in
                    echo '<div id="centercontent">';
                } elseif (  ( isset($_SESSION["group"]) ) AND ($_SESSION["group"] == "user") ) { 

                    require_once(NCONFDIR."/include/menu/menu_start.html");
                    require_once(NCONFDIR."/include/menu/menu_user.php");
                    require_once(NCONFDIR."/include/menu/menu_end.php");
                    echo '<div id="maincontent">';

                } elseif (  ( isset($_SESSION["group"]) ) AND ($_SESSION["group"] == "admin") ) {

                    require_once(NCONFDIR."/include/menu/menu_start.html");
                    require_once(NCONFDIR."/include/menu/menu_user.php");
                    require_once(NCONFDIR."/include/menu/menu_admin.php");
                    require_once(NCONFDIR."/include/menu/menu_end.php");
                    echo '<div id="maincontent">';

                }
            }

        }elseif ( file_exists('config/nconf.php') AND
                ( file_exists('INSTALL.php') OR file_exists('INSTALL') OR file_exists('UPDATE') OR file_exists('UPDATE.php') )
            ){
            # One of the INSTALL Files are still existing, remove theme first!
            echo '<div id="centercontent">';
                message($critical, 'NConf has detected update or installation files in the main folder.<br><br>
                    To update NConf, go to the <b><a href="UPDATE.php">update page</a></b>
                    <br><br>
                    If you have just finished installing or updating NConf, make sure you delete the following<br> 
                    files and directories to continue:<br>
                    <br>- INSTALL
                    <br>- INSTALL.php
                    <br>- UPDATE
                    <br>- UPDATE.php
                    <br>
                ');

        }else{
            # config not available, first run INSTALL.php
            require_once(NCONFDIR."/include/menu/menu_start.html");
            require_once(NCONFDIR."/include/menu/menu_install.php");
            require_once(NCONFDIR."/include/menu/menu_end.php");

            echo '<div id="maincontent">';
                message($critical, 'Setup required. To install NConf <b><a href="INSTALL.php">click here</a></b><br>');
        }

    }

    # Check for critical error, continue or abort
    if ( NConf_DEBUG::status('CRITICAL') ){
        $msg_critical = NConf_DEBUG::show_debug('CRITICAL');
        echo NConf_HTML::show_error('Error', $msg_critical);
        require_once(NCONFDIR.'/include/foot.php');
        exit;
    }


    ###
    ## Page authorisation check
    ###
    require_once(NCONFDIR.'/include/access_rules.php');

    # Show page or EXIT the script ? (based on above auth-checks)
    if ( $NConf_PERMISSIONS->checkPageAccess() === TRUE AND $NConf_PERMISSIONS->checkIdAuthorization() !== FALSE){
        NConf_DEBUG::set("Access granted", 'DEBUG', "ACL");
        # go ahead in file

    }elseif ( !isset($_SESSION["group"]) AND ( empty($_GET["goto"]) ) ){
        # not logged in
        # Go to login page, and redirect it to called page
        $url = 'index.php?goto='.urlencode($_SERVER['REQUEST_URI']);
        # Redirect to login page with url as goto
        echo '<meta http-equiv="refresh" content="0; url='.$url.'">';
        message($info, '<b>redirecting to:</b> <a href="'.$url.'"> [ this page ] </a>');
        require_once(NCONFDIR.'/include/foot.php');
        exit;
        
    }elseif ( !isset($_SESSION["group"]) AND ( !empty($_GET["goto"]) ) ){
        # do nothing, login page will be displayed
         message($debug, "display login page");

    }else{
        $message = $NConf_PERMISSIONS->message;
        NConf_DEBUG::set($message, 'INFO');
        NConf_DEBUG::set("Access denied", 'DEBUG', "ACL");

        //echo $message;
        echo NConf_HTML::limit_space( NConf_HTML::show_error('Error', $message) );

        require_once(NCONFDIR.'/include/foot.php');
        # EXIT because of no access
        exit;

    }

    # close header-part in DEBUG section
    $debug_entry = NConf_HTML::line().NConf_HTML::text("Page specific debugging:", FALSE, 'b');
    NConf_DEBUG::set($debug_entry);


?>
