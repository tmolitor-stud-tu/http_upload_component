<?php
/**
 * Jaxl (Jabber XMPP Library)
 *
 * Copyright (c) 2009-2012, Abhinav Singh <me@abhinavsingh.com>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in
 * the documentation and/or other materials provided with the
 * distribution.
 *
 * * Neither the name of Abhinav Singh nor the names of his
 * contributors may be used to endorse or promote products derived
 * from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRIC
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 */

require_once JAXL_CWD.'/xmpp/xmpp_xep.php';

define('NS_DISCO_INFO', 'http://jabber.org/protocol/disco#info');
define('NS_DISCO_ITEMS', 'http://jabber.org/protocol/disco#items');

class XEP_0030 extends XMPPXep {
	
	private $identity_category = 'bot';
	private $identity_type = 'text';
	private $identity_name = 'uninitialized';
	private $features = array();
	private $forms = array();
	
	//
	// abstract method
	//
	
	public function init() {
		return array(
			'on_get_iq' => 'on_get_iq'
		);
	}
	
	//
	// api methods
	//
	
	public function add_feature($feature) {
		$this->features[(string)$feature]=true;
	}
	
	public function remove_feature($feature) {
		unset($this->features[(string)$feature]);
	}
	
	public function set_form_data($data) {
		$this->forms=$data;
	}
	
	public function set_identity($category, $type, $name='uninitialized') {
		$this->identity_category=$category;
		$this->identity_type=$type;
		$this->identity_name=$name;
	}
	
	public function get_info_pkt($entity_jid) {
		return $this->jaxl->get_iq_pkt(
			array('type'=>'get', 'from'=>$this->get_jid()->to_string(), 'to'=>$entity_jid),
			new JAXLXml('query', NS_DISCO_INFO)	
		);
	}
	
	public function get_info($entity_jid, $callback=null) {
		$pkt = $this->get_info_pkt($entity_jid);
		if($callback) $this->jaxl->add_cb('on_stanza_id_'.$pkt->id, $callback);
		$this->jaxl->send($pkt);
	}
	
	public function get_items_pkt($entity_jid) {
		return $this->jaxl->get_iq_pkt(
			array('type'=>'get', 'from'=>$this->get_jid()->to_string(), 'to'=>$entity_jid),
			new JAXLXml('query', NS_DISCO_ITEMS)
		);
	}
	
	public function get_items($entity_jid, $callback=null) {
		$pkt = $this->get_items_pkt($entity_jid);
		if($callback) $this->jaxl->add_cb('on_stanza_id_'.$pkt->id, $callback);
		$this->jaxl->send($pkt);
	}
	
	//
	// event callbacks
	//
	
	public function on_get_iq($stanza) {
		if($stanza->exists('query', NS_DISCO_INFO)) {
			$query=new JAXLXml('query', NS_DISCO_INFO);
			$query->c('identity', null, array('category'=>$this->identity_category, 'type'=>$this->identity_type, 'name'=>$this->identity_name))->up();
			foreach(array_keys($this->features) as $f)
				$query->c('feature', null, array('var'=>$f))->up();
			
			if(count($this->forms))
			{
				$form=new JAXLXml('x', 'jabber:x:data', array('type'=>'result'));
				foreach($this->forms as $name=>$data)
					if(is_array($data))
						$form->c('field', null, array('var'=>$name, 'type'=>$data['type']))->c('value')->t($data['value'])->up()->up();
					else
						$form->c('field', null, array('var'=>$name))->c('value')->t($data)->up()->up();;
				$query->cnode($form)->up();
			}
			
			$response=$this->jaxl->get_iq_pkt(
				array('type'=>'result', 'from'=>$this->get_jid()->to_string(), 'to'=>$stanza->from, 'id'=>$stanza->id),
				$query
			);
			$this->jaxl->send($response);
		}
	}
	
	private function get_jid() {
		if(isset($this->jaxl->full_jid))
			return $this->jaxl->full_jid;
		return $this->jaxl->jid;
	}
}

?>
