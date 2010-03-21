<?php

/**
 * An example of how to handle the user authorization process for requesting accessing to a Twitter
 * account through oAuth. It displays a simple UI, storing the needed state in session variables. 
 * For a real app, you'll probably want to keep them in your database, but this arrangement makes the
 * example simpler.
 *
 * To install this example, just copy all the files in this folder onto your web server, edit config.php
 * to add the oAuth tokens you've obtained from Twitter and then load this index page in your browser.
 * To get the oAuth tokens, go to http://twitter.com/oauth_clients and register an application.
 
 Licensed under the 2-clause (ie no advertising requirement) BSD license,
 making it easy to reuse for commercial or GPL projects:
 
 (c) Pete Warden <pete@petewarden.com> http://petewarden.typepad.com/ - Mar 21st 2010
 
 Redistribution and use in source and binary forms, with or without modification, are
 permitted provided that the following conditions are met:

   1. Redistributions of source code must retain the above copyright notice, this 
      list of conditions and the following disclaimer.
   2. Redistributions in binary form must reproduce the above copyright notice, this 
      list of conditions and the following disclaimer in the documentation and/or 
      other materials provided with the distribution.
   3. The name of the author may not be used to endorse or promote products derived 
      from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR 
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
 
 */

require ('./config.php');
require ('./peteutils.php');
require ('./oauth/twitteroauth.php');

// Returns information about the oAuth state for the current user. This includes whether the process
// has been started, if we're waiting for the user to complete the authorization page on the remote
// server, or if the user has authorized us and if so the access keys we need for the API.
// If no oAuth process has been started yet, null is returned and it's up to the client to kick it off
// and set the new information.
// This is all currently stored in session variables, but for real applications you'll probably want
// to move it into your database instead.
//
// The oAuth state is made up of the following members:
//
// request_token: The public part of the token we generated for the authorization request.
// request_token_secret: The secret part of the authorization token we generated.
// access_token: The public part of the token granting us access. Initially ''. 
// access_token_secret: The secret part of the access token. Initially ''.
// state: Where we are in the authorization process. Initially 'start', 'done' once we have access.

function get_twitter_oauth_state()
{
    if (empty($_SESSION['twitteroauthstate']))
        return null;
        
    $result = $_SESSION['twitteroauthstate'];

    pete_log('oauth', "Found state ".print_r($result, true));

    return $result;
}

// Updates the information about the user's progress through the oAuth process.
function set_twitter_oauth_state($state)
{
    pete_log('oauth', "Setting OAuth state to - ".print_r($state, true));

    $_SESSION['twitteroauthstate'] = $state;
}

// Returns an authenticated object you can use to access the OAuth Twitter API
function get_twitter_oauth_accessor()
{
    $oauthstate = get_twitter_oauth_state();
    if ($oauthstate===null)
        return null;
    
    $accesstoken = $oauthstate['access_token'];
    $accesstokensecret = $oauthstate['access_token_secret'];

    $to = new TwitterOAuth(
        TWITTER_API_KEY_PUBLIC, 
        TWITTER_API_KEY_PRIVATE,
        $accesstoken,
        $accesstokensecret
    );

    return $to;
}

// Calls the Twitter API using oAuth. You need to pass in a constructed TwitterOAuth object as the
// first argument, followed by the URL for the base call, then the parameters to pass in, and
// finally whether it's a GET or POST request. If the call fails, then $g_twitter_error_message
// will be set.
// It's quite common to get occasional 50x errors from the API as the servers get overloaded, so
// the code sleeps and retries when it encounters those.

$g_twitter_error_message = null;
$g_twitter_error_code = null;

function twitter_api_call($to, $baseurl, $args, $method="GET")
{		
	$fullurl = "https://$baseurl";

	pete_log("twitter", "Calling Twitter API at '$fullurl' with ".print_r($args, true));

    $trycount = 0;
    while ($trycount<5)
    {
		$data = $to->oAuthRequest($fullurl, $args, $method);
		$httpcode = $to->lastStatusCode();
        
        $trycount += 1;
        
        if ($httpcode==200)
        {
            return $data;
        }
        else
        {
            global $g_twitter_error_message;
            global $g_twitter_error_code;
            $g_twitter_error_message = $data;
            $g_twitter_error_code = $httpcode;
            
            pete_log("twitter", "API call failed with $g_twitter_error_code: $g_twitter_error_message");
            
            if (($httpcode<500) or ($httpcode>=600))
                break;
                
            // If it was a (hopefully temporary) error, sleep for a while and try again
            pete_log("twitter", "sleeping and retrying");
            sleep(10);
        }
    }
    
    return null;
}

// Deals with the workflow of oAuth user authorization. At the start, there's no oAuth information and
// so it will display a link to the Twitter site. If the user visits that link they can authorize us,
// and then they should be redirected back to this page. There should be some access tokens passed back
// when they're redirected, we extract and store them, and then try to call the Twitter API using them.
function handle_twitter_oauth()
{
	$oauthstate = get_twitter_oauth_state();
    
    // If there's no oAuth state stored at all, then we need to initialize one with our request
    // information, ready to create a request URL.
	if (!isset($oauthstate))
	{
		pete_log('oauth', "No OAuth state found");

		$to = new TwitterOAuth(TWITTER_API_KEY_PUBLIC, TWITTER_API_KEY_PRIVATE);
		
        // This call can be unreliable if the Twitter API servers are under a heavy load, so
        // retry it with an increasing amount of back-off if there's a problem.
		$maxretrycount = 10;
		$retrycount = 0;
		while ($retrycount<$maxretrycount)
		{		
			$tok = $to->getRequestToken();
			if (isset($tok['oauth_token'])&&
				isset($tok['oauth_token_secret']))
				break;
			
			$retrycount += 1;
			sleep($retrycount*5);
		}
		
		$tokenpublic = $tok['oauth_token'];
		$tokenprivate = $tok['oauth_token_secret'];
		$state = 'start';
		
        // Create a new set of information, initially just containing the keys we need to make
        // the request.
		$oauthstate = array(
			'request_token' => $tokenpublic,
			'request_token_secret' => $tokenprivate,
			'access_token' => '',
			'access_token_secret' => '',
			'state' => $state,
		);

		set_twitter_oauth_state($oauthstate);
	}

    // If there's an 'oauth_token' in the URL parameters passed into us, and we don't already
    // have access tokens stored, this is the user being returned from the authorization page.
    // Retrieve the access tokens and store them, and set the state to 'done'.
	if (isset($_REQUEST['oauth_token'])&&
		($oauthstate['access_token']==''))
	{
		$urlaccesstoken = $_REQUEST['oauth_token'];
		pete_log('oauth', "Found access tokens in the URL - $urlaccesstoken");

		$requesttoken = $oauthstate['request_token'];
		$requesttokensecret = $oauthstate['request_token_secret'];

		pete_log('oauth', "Creating API with $requesttoken, $requesttokensecret");			
	
		$to = new TwitterOAuth(
			TWITTER_API_KEY_PUBLIC, 
			TWITTER_API_KEY_PRIVATE,
			$requesttoken,
			$requesttokensecret
		);
		
		$tok = $to->getAccessToken();
		
		$accesstoken = $tok['oauth_token'];
		$accesstokensecret = $tok['oauth_token_secret'];

		pete_log('oauth', "Calculated access tokens $accesstoken, $accesstokensecret");			
		
		$oauthstate['access_token'] = $accesstoken;
		$oauthstate['access_token_secret'] = $accesstokensecret;
		$oauthstate['state'] = 'done';

		set_twitter_oauth_state($oauthstate);		
	}

	$state = $oauthstate['state'];
	
	if ($state=='start')
	{
        // This is either the first time the user has seen this page, or they've refreshed it before
        // they've authorized us to access their information. Either way, display a link they can
        // click that will take them to the authorization page.
		$tokenpublic = $oauthstate['request_token'];
		$to = new TwitterOAuth(TWITTER_API_KEY_PUBLIC, TWITTER_API_KEY_PRIVATE);
		$requestlink = $to->getAuthorizeURL($tokenpublic);
?>
        <center><h1>Click this link to authorize the example application to access your Twitter data</h1></center>
        <br><br>
        <center><a href="<?=$requestlink?>" target="_blank"><?=$requestlink?></a></center>
<?php
	}
	else
	{
        // We've been given some access tokens, so try and use them to make an API call, and
        // display the results.
        
        $accesstoken = $oauthstate['access_token'];
        $accesstokensecret = $oauthstate['access_token_secret'];

		$to = new TwitterOAuth(
			TWITTER_API_KEY_PUBLIC, 
			TWITTER_API_KEY_PRIVATE,
			$accesstoken,
			$accesstokensecret
		);
        
		$content = twitter_api_call($to, 'twitter.com/account/verify_credentials.json', array(), 'GET');
        if (empty($content))
        {
            global $g_twitter_error_message;
            global $g_twitter_error_code;        
?>
            <center><h1>Authorization failed</h1></center>
            <br><br>
            <center>Authorization tokens were returned, but when I tried an API call I received the following error:</center>
            <br><br>
            <center><?=htmlspecialchars($g_twitter_error_message)?>:<?=htmlspecialchars($g_twitter_error_code)?></center>
<?php
        }
        else
        {
            $accountinfo = json_decode($content, true);

            pete_log('oauth', print_r($accountinfo, true));
            
            $twittername = $accountinfo['screen_name'];
?>
            <center><h1>Successfully authorized <?=htmlspecialchars($twittername)?></h1></center>
<?php
        }
	}
		
}

// This is important! The example code uses session variables to store the user and token information,
// so without this call nothing will work. In a real application you'll want to use a database
// instead, so that the information is stored more persistently.
session_start();

?>
<html>
<head>
<title>Example page for Twitter oAuth</title>
</head>
<body style="font-family:'lucida grande', arial;">
<div style="padding:20px;">
<?php

handle_twitter_oauth();

?>
<br><br><br>
<center>
<i>An example demonstrating the Twitter oAuth workflow in PHP by <a href="http://petewarden.typepad.com">Pete Warden</a></i>
</center>
</div>
</body>
</html>