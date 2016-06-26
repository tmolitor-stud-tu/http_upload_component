<?php
ini_set('log_errors', FALSE);
ini_set('display_errors', TRUE);
error_reporting(E_ALL & ~E_NOTICE);
mb_internal_encoding('UTF-8');

require_once('jaxl/jaxl.php');

$configfile=dirname(__FILE__).'/config.ini';
if(!file_exists($configfile))
	trigger_error("Config file '$configfile' does not exist, aborting!", E_USER_ERROR);
$config=parse_ini_file($configfile, true);
define('MAX_FILE_SIZE', $config['xmpp']['max_file_size']);
define('ALLOWED_DOMAIN', $config['xmpp']['allowed_domain']);
define('SLOT_TIMEOUT', $config['xmpp']['slot_timeout']);
define('SLOT_DB', $config['xmpp']['slot_db']);
define('PUT_PREFIX', $config['xmpp']['put_prefix']);
define('GET_PREFIX', $config['xmpp']['get_prefix']);
define('COMPONENT_HOST', $config['xmpp']['component_host']);
define('COMPONENT_PASSWORD', $config['xmpp']['component_password']);
define('XMPP_HOST', $config['xmpp']['xmpp_host']);
define('XMPP_PORT', $config['xmpp']['xmpp_port']);
define('KEEPALIVE', $config['xmpp']['keepalive']);

define('NS_HTTPUPLOAD', 'urn:xmpp:http:upload');

$log_levels=array('DEBUG'=>JAXL_DEBUG, 'INFO'=>JAXL_INFO, 'NOTICE'=>JAXL_NOTICE, 'WARNING'=>JAXL_WARNING, 'ERROR'=>JAXL_ERROR);
$running=false;
$comp = new JAXL(array(
	'jid' => COMPONENT_HOST,
	'pass' => COMPONENT_PASSWORD,
	'host' => XMPP_HOST,
	'port' => XMPP_PORT,
	'log_level' => $log_levels[$config['xmpp']['log_level']],
	'strict' => true,
));
$comp->require_xep(array(
	'0114',		// component protocol
	'0030',		// discovery
));

$comp->xeps['0030']->set_identity('store', 'file', 'HTTP Upload Component');
$comp->xeps['0030']->add_feature(NS_HTTPUPLOAD);
$comp->xeps['0030']->set_form_data(array(
	'FORM_TYPE'=>array('type'=>'hidden', 'value'=>NS_HTTPUPLOAD),
	'max-file-size'=>MAX_FILE_SIZE,
));

$comp->add_cb('on_auth_success', function() use($comp, $running)
{
	_info("Component authenticated successfully...");
	
	//send keepalive whitespaces every KEEPALIVE seconds
	$running=true;
	JAXLLoop::$clock->call_fun_periodic(KEEPALIVE * 1000000, function() use($comp, $running) {
		if($running)
		{
			_info("Sending keep alive whitespaces...");
			$comp->send_raw('  ');
		}
	});
});

$comp->add_cb('on_auth_failure', function($reason)
{
	global $comp;
	$comp->send_end_stream();
	_info("Got authentication failure with reason $reason");
});

$comp->add_cb('on_get_iq', function($stanza)
{
	global $comp;
	
	if(($request = $stanza->exists('request', NS_HTTPUPLOAD)))
	{
		$upload_id=gen_uuid();
		$filename=basename(get_text($request, 'filename'));
		$size=get_text($request, 'size');
		$content_type=get_text($request, 'content-type');
		_notice("[$upload_id] Got XMPP slot request for '$filename' ($content_type) with size $size...");
		
		if($stanza->from_domain!=ALLOWED_DOMAIN)
		{
			_warning("User at domain '{$stanza->from_domain}' not allowed to upload files...");
			$response=$comp->get_iq_pkt(array('type'=>'result', 'from'=>get_jid($comp)->to_string(), 'to'=>$stanza->from, 'id'=>$stanza->id), null);
			$response->c('request', NS_HTTPUPLOAD)
					 ->c('filename')->t($filename)->up()
					 ->c('size')->t($size)->up()->up()
					 ->c('error', null, array('type'=>'cancel'))
					 ->c('not-allowed', 'urn:ietf:params:xml:ns:xmpp-stanzas')->up()
					 ->c('text', 'urn:ietf:params:xml:ns:xmpp-stanzas', array(), 'You are not allowed to upload files to this host')->up();
			$comp->send($response);
		}
		else if($size>MAX_FILE_SIZE)
		{
			_warning("Filesize $size exceeds maximum of ".MAX_FILE_SIZE." bytes...");
			$response=$comp->get_iq_pkt(array('type'=>'result', 'from'=>get_jid($comp)->to_string(), 'to'=>$stanza->from, 'id'=>$stanza->id), null);
			$response->c('request', NS_HTTPUPLOAD)
					 ->c('filename')->t($filename)->up()
					 ->c('size')->t($size)->up()
					 ->up()
					 ->c('error', null, array('type'=>'cancel'))
					 ->c('not-acceptable', 'urn:ietf:params:xml:ns:xmpp-stanzas')->up()
					 ->c('text', 'urn:ietf:params:xml:ns:xmpp-stanzas', array(), 'File too large. The maximum file size is '.MAX_FILE_SIZE.' bytes')->up()
					 ->c('file-too-large', NS_HTTPUPLOAD)
					 ->c('max-file-size')->t(MAX_FILE_SIZE)->up()->up()
					 ->up();
			$comp->send($response);
		}
		else
		{
			$put_slot=PUT_PREFIX."$upload_id/".rawurlencode($filename);
			$get_slot=GET_PREFIX."$upload_id/".rawurlencode($filename);
			$response=$comp->get_iq_pkt(array('type'=>'result', 'from'=>get_jid($comp)->to_string(), 'to'=>$stanza->from, 'id'=>$stanza->id), null);
			$response->c('slot', NS_HTTPUPLOAD)
					 ->c('put')->t($put_slot)->up()
					 ->c('get')->t($get_slot)->up()
					 ->up();
			
			//open and lock slot db
			$slot_db=fopen(SLOT_DB, 'c');
			while(!flock($slot_db, LOCK_EX | LOCK_NB))		//try every 100ms for 8 seconds
			{
				if($count++>80)
				{
					$response=$comp->get_iq_pkt(array('type'=>'result', 'from'=>get_jid($comp)->to_string(), 'to'=>$stanza->from, 'id'=>$stanza->id), null);
					$response->c('request', NS_HTTPUPLOAD)
							 ->c('filename')->t($filename)->up()
							 ->c('size')->t($size)->up()
							 ->up()
							 ->c('error', null, array('type'=>'cancel'))
							 ->c('resource-constraint', 'urn:ietf:params:xml:ns:xmpp-stanzas')->up()
							 ->c('text', 'urn:ietf:params:xml:ns:xmpp-stanzas', array(), 'Server too busy, please try again in a few minutes')->up()
							 ->up();
					$comp->send($response);
					return;
				}
				usleep(100000);
			}
			//read db
			$active_slots=@unserialize(file_get_contents(SLOT_DB));
			if(!is_array($active_slots))
				$active_slots=array();
			//add new slot
			$active_slots[$upload_id]=time()+SLOT_TIMEOUT;
			//delete timed out slots
			$_active_slots=$active_slots;
			foreach($_active_slots as $uuid=>$time)
				if($time<time())
				{
					_info("Old slot $uuid expired ($time>".time()."), removing entry...");
					unset($active_slots[$uuid]);
				}
			//save and close db
			file_put_contents(SLOT_DB, serialize($active_slots));
			flock($slot_db, LOCK_UN);
			fclose($slot_db);
			
			$comp->send($response);
		}
	}
});

$comp->add_cb('on_disconnect', function() use($running)
{
	_info("Component got disconnected...");
	$running=false;
});


//
// finally start configured xmpp stream
//
$comp->start();


function get_text($node, $child)
{
	$child=$node->exists($child);
	if($child!==false)
		return (string)$child->text;
	return false;
}
function get_jid($jaxl)
{
	if(isset($jaxl->full_jid))
		return $jaxl->full_jid;
	return $jaxl->jid;
}
function gen_uuid()
{
	return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
}
?>