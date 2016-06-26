<?php
ini_set('log_errors', TRUE);
ini_set('display_errors', FALSE);
error_reporting(E_ALL & ~E_NOTICE);
mb_internal_encoding('UTF-8');

$configfile=dirname(__FILE__).'/component/config.ini';
if(!file_exists($configfile))
	trigger_error("Config file '$configfile' does not exist, aborting!", E_USER_ERROR);
$config=parse_ini_file($configfile, true);
define('MAX_FILE_SIZE', $config['xmpp']['max_file_size']);
define('SLOT_DB', $config['xmpp']['slot_db']);

$active_slots=load_slots();
$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$original_filename=basename($path);
$uuid=basename(dirname($path));
if(strlen($uuid)!=36)			//this is no uuid (sloppy test)
	die(http_return(403));
$file_path=dirname(__FILE__)."/upload/$uuid/data";
$attrs_path=dirname(__FILE__)."/upload/$uuid/attrs";

if(in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD')))
{
	if(!file_exists($file_path))
		die(http_return(404));
	$finfo=finfo_open(FILEINFO_MIME_TYPE);
	$mime_type=finfo_file($finfo, $file_path);
	finfo_close($finfo);
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: '.filesize($file_path));
	header("Content-Type: $mime_type");
	header('Content-Disposition: inline');
	if($_SERVER['REQUEST_METHOD']=='GET')
		output($file_path);
	exit;
}
else if($_SERVER['REQUEST_METHOD']=='PUT')
{
	if(!isset($active_slots[$uuid]))				//slot not existent (or timed out)
		die(http_return(403));
	if($_SERVER['CONTENT_LENGTH']>MAX_FILE_SIZE)	//file too big
		die(http_return(403));
	@mkdir(dirname($file_path));
	$written=@file_put_contents($file_path, fopen('php://input', 'r'));
	if($written!=$_SERVER['CONTENT_LENGTH'])
	{
		error_log("failed to write file '$original_filename' ($written != {$_SERVER['CONTENT_LENGTH']}) [written: ".serialize($written)."]...");
		die(http_return(500));						//failed to write file
	}
	$attrs=serialize(array('original_url'=>$_SERVER['REQUEST_URI'], 'filename'=>$original_filename));
	$written=@file_put_contents($attrs_path, $attrs);
	if($written!=strlen($attrs))
	{
		error_log("failed to write attr file for '$original_filename'...");
		die(http_return(500));						//failed to write file
	}
	die(http_return(200));
}
else
	die(http_return(400));

function load_slots()
{
	//open slot db and lock it
	$slot_db=fopen(SLOT_DB, 'c');
	while(!flock($slot_db, LOCK_EX | LOCK_NB))		//8 sekunden lang jede 100 millisekunden versuchen
	{
		if($count++>80)
			die(http_return(503));
		usleep(100000);
	}
	//load db
	$active_slots=@unserialize(file_get_contents(SLOT_DB));
	if(!is_array($active_slots))
		$active_slots=array();
	//delete timed out slots
	$_active_slots=$active_slots;
	foreach($_active_slots as $uuid=>$time)
		if($time<time())
			unset($active_slots[$uuid]);
	//save db again
	file_put_contents(SLOT_DB, serialize($active_slots));
	flock($slot_db, LOCK_UN);
	fclose($slot_db);
	
	return $active_slots;
}

function output($file)
{
	$fp=fopen($file, 'r');
	while(!feof($fp))
		echo fread($fp, 4096);
}

function http_return($response_code)
{
	// 1xx informational
	$text[100]="Continue";
	$text[101]="Switching Protocols";

	// 2xx success
	$text[200]="OK";
	$text[201]="Created";
	$text[202]="Accepted";
	$text[203]="Non-Authoritative Information";
	$text[204]="No Content";
	$text[205]="Reset Content";
	$text[206]="Partial Content";
	$text[207]="Multi Status";
	$text[208]="Already Reported";
	$text[226]="IM Used";

	// 3xx redirection
	$text[301]='Moved Permanently';
	$text[302]='Found';
	$text[303]='See Other';
	$text[304]='Not Modified';
	$text[305]='Use Proxy';
	$text[306]='Switch Proxy';
	$text[307]='Temporary Redirect';
	$text[308]='Permanent Redirect';

	// 4xx client error
	$text[400]='Bad Request';
	$text[401]='Unauthorized';
	$text[402]='Payment Required';
	$text[403]='Forbidden';
	$text[404]='Not Found';
	$text[405]='Method Not Allowed';
	$text[406]='Not Acceptable';
	$text[407]='Proxy Authentication Required';
	$text[408]='Request Timeout';
	$text[409]='Conflict';
	$text[410]='Gone';
	$text[411]='Length Required';
	$text[412]='Precondition Failed';
	$text[413]='Payload Too Large';
	$text[414]='URI Too Long';
	$text[415]='Unsupported Media Type';
	$text[416]='Range Not Satisfiable';
	$text[417]='Expectation Failed';
	$text[418]='I\'m a teapot';
	$text[421]='Misdirected Request';
	$text[422]='Unprocessable Entity';
	$text[423]='Locked';
	$text[424]='Failed Dependency';
	$text[426]='Upgrade Required';
	$text[428]='Precondition Required';
	$text[429]='Too Many Requests';
	$text[431]='Request Header Fields Too Large';
	$text[451]='Unavailable For Legal Reasons';
	$text[499]='Client Closed Request'; // Nginx

	// 5xx server error
	$text[500]='Internal Server Error';
	$text[501]='Not Implemented';
	$text[502]='Bad Gateway';
	$text[503]='Service Unavailable';
	$text[504]='Gateway Timeout';
	$text[505]='HTTP Version Not Supported';
	$text[506]='Variant Also Negotiates';
	$text[507]='Insufficient Storage';
	$text[508]='Loop Detected';
	$text[509]='Bandwidth Limit Exceeded';
	$text[510]='Not Extended';
	$text[511]='Network Authentication Required';
	
	header("HTTP/1.1 $response_code {$text[$response_code]}");
	return "$response_code {$text[$response_code]}";
}
?>