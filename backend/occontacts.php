<?php
/***********************************************
* File      :   owncloud.php (based on vcarddir.php from z-push)
* Project   :   oczpush, https://github.com/gza/oczpush
* Descr     :   This backend is for owncloud (contacts only, for now)
* Licence   :	AGPL
************************************************/


if (! defined('STORE_SUPPORTS_UNICODE') ) define('STORE_SUPPORTS_UNICODE', true);
setlocale(LC_CTYPE, "en_US.UTF-8");
if (! defined('STORE_INTERNET_CPID') ) define('STORE_INTERNET_CPID', INTERNET_CPID_UTF8);

include_once('lib/default/diffbackend/diffbackend.php');

// OC4 fix
if(isset($_SERVER['HTTPS']) and $_SERVER['HTTPS']<>'') $protocol='https://'; else $protocol='http://';
if(! isset($_SERVER['HTTP_REFERER'])) $_SERVER['HTTP_REFERER']=$protocol.$_SERVER['SERVER_NAME'].'/index.php';
// End OC4 fix

require_once(OC_DIR.'/lib/config.php');
require_once(OC_DIR.'/lib/base.php');

// Check if we are a user
OC_Util::checkAppEnabled('contacts');

class BackendOCContacts extends BackendDiff {
    
    var $addressBookId;
    var $userTZ;

    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user - NOT EFFECTIVELY IMPLEMENTED
     * Normally some kind of password check would be done here.
     * Alternatively, the password could be ignored and an Apache
     * authentication via mod_auth_* could be done
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
    	ZLog::Write(LOGLEVEL_DEBUG,'OCContacts::Logon('.$username.')');
        if(OC_User::login($username,$password)){
            OC_Util::setUpFS();
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::Logon : Logged');
	    $addressBooks = OC_Contacts_Addressbook::All($username);
	    $this->addressBookId = $addressBooks[0]['id'];
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::Logon : addressBook selected :'.$addressBooks[0]['displayname']);
	    $this->userTZ=\OCP\Config::getUserValue(\OCP\USER::getUser(), 'calendar', 'timezone', date_default_timezone_get());
	    ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::Logon : TZ Selected: '.$this->userTZ);
	    return true;
        }
        else {
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::Logon : Not Logged');
            return false;
        }
    }

    /**
     * Logs off
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        return true;
    }

    /**
     * Sends an e-mail
     * Not implemented here
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        return false;
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        return false;
    }

    /**
     * Returns the content of the named attachment as stream
     * not implemented
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        return false;
    }

    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */

    /**
     * Returns a list (array) of folders.
     * In simple implementations like this one, probably just one folder is returned.
     *
     * @access public
     * @return array
     */
    public function GetFolderList() {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetFolderList()');
        $contacts = array();
        $folder = $this->StatFolder("contacts");
        $contacts[] = $folder;

        return $contacts;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetFolder('.$id.')');
        if($id == "contacts") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "Contacts";
            $folder->type = SYNC_FOLDER_TYPE_CONTACT;

            return $folder;
        } else return false;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::StatFolder('.$id.')');
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * not implemented
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        return false;
    }

    /**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetMessageList('.$folderid.')');
        $messages = array();

	foreach ( OC_Contacts_VCard::all($this->addressBookId) as $cardEntry ) {
		$message["id"] = substr($cardEntry['uri'],0,-4);		
		$message["mod"] = $cardEntry['lastmodified'];
		$message["flags"] = 1;

		$messages[] = $message;
	}
//	ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetMessageList: $message = ('.print_r($message,true));	
	return $messages;

    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetMessage('.$folderid.', '.$id.', ..)');
        if($folderid != "contacts")
            return;

        $types = array ('dom' => 'type', 'intl' => 'type', 'postal' => 'type', 'parcel' => 'type', 'home' => 'type', 'work' => 'type',
            'pref' => 'type', 'voice' => 'type', 'fax' => 'type', 'msg' => 'type', 'cell' => 'type', 'pager' => 'type',
            'bbs' => 'type', 'modem' => 'type', 'car' => 'type', 'isdn' => 'type', 'video' => 'type',
            'aol' => 'type', 'applelink' => 'type', 'attmail' => 'type', 'cis' => 'type', 'eworld' => 'type',
            'internet' => 'type', 'ibmmail' => 'type', 'mcimail' => 'type',
            'powershare' => 'type', 'prodigy' => 'type', 'tlx' => 'type', 'x400' => 'type',
            'gif' => 'type', 'cgm' => 'type', 'wmf' => 'type', 'bmp' => 'type', 'met' => 'type', 'pmb' => 'type', 'dib' => 'type',
            'pict' => 'type', 'tiff' => 'type', 'pdf' => 'type', 'ps' => 'type', 'jpeg' => 'type', 'qtime' => 'type',
            'mpeg' => 'type', 'mpeg2' => 'type', 'avi' => 'type',
            'wave' => 'type', 'aiff' => 'type', 'pcm' => 'type',
            'x509' => 'type', 'pgp' => 'type', 'text' => 'value', 'inline' => 'value', 'url' => 'value', 'cid' => 'value', 'content-id' => 'value',
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding',
        );


        // Parse the vcard
        $message = new SyncContact();

	$card=OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId, $id.'.vcf');
	$data = $card['carddata'];

        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);

        $lines = explode("\n", $data);

        $vcard = array();
        foreach($lines as $line) {
            if (trim($line) == '')
                continue;
            $pos = strpos($line, ':');
            if ($pos === false)
                continue;

            $field = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos+1));

            $fieldparts = preg_split('/(?<!\\\\)(\;)/i', $field, -1, PREG_SPLIT_NO_EMPTY);

            $type = strtolower(array_shift($fieldparts));

            $fieldvalue = array();

            foreach ($fieldparts as $fieldpart) {
                if(preg_match('/([^=]+)=(.+)/', $fieldpart, $matches)){
                    if(!in_array(strtolower($matches[1]),array('value','type','encoding','language')))
                        continue;
                    if(isset($fieldvalue[strtolower($matches[1])]) && is_array($fieldvalue[strtolower($matches[1])])){
                        $fieldvalue[strtolower($matches[1])] = array_merge($fieldvalue[strtolower($matches[1])], preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY));
                    }else{
                        $fieldvalue[strtolower($matches[1])] = preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY);
                    }

					if (strtolower($matches[1]) == "type") {
						foreach ($fieldvalue["type"] as &$tmp) {
							$tmp = strtolower($tmp);
						}
					}
                }else{
                    if(!isset($types[strtolower($fieldpart)]))
                        continue;
                    $fieldvalue[$types[strtolower($fieldpart)]][] = $fieldpart;
                }
            }
            //
            switch ($type) {
                case 'categories':
                    //case 'nickname':
                    $val = preg_split('/(?<!\\\\)(\,)/i', $value);
                    $val = array_map("w2ui", $val);
                    break;
                default:
                    $val = preg_split('/(?<!\\\\)(\;)/i', $value);
                    break;
            }
            if(isset($fieldvalue['encoding'][0])){
                switch(strtolower($fieldvalue['encoding'][0])){
                    case 'q':
                    case 'quoted-printable':
                        foreach($val as $i => $v){
                            $val[$i] = quoted_printable_decode($v);
                        }
                        break;
                    case 'b':
                    case 'base64':
                        foreach($val as $i => $v){
                            $val[$i] = base64_decode($v);
                        }
                        break;
                }
            }else{
                foreach($val as $i => $v){
                    $val[$i] = $this->unescape($v);
                }
            }
            $fieldvalue['val'] = $val;
            $vcard[$type][] = $fieldvalue;
        }

	
//	ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetMessage: $vcard = ('.print_r($vcard,true));
        
		if(isset($vcard['email'])) {
			foreach($vcard['email'] as $email) {
				if(!isset($tel['type'])) $tel['type'] = array();
				if(!isset($email['type'])) $email['type'] = array();
				if(in_array('home', $email['type'])) {
					$message->email1address = $email['val'][0];
				} elseif(in_array('work', $email['type'])) {
					$message->email2address = $email['val'][0];
				} else {
					$message->email3address = $email['val'][0];
				}
			}
		}

        if(isset($vcard['tel'])){
            foreach($vcard['tel'] as $tel) {
                if(!isset($tel['type'])){
                    $tel['type'] = array();
                }
                if(in_array('car', $tel['type'])){
                    $message->carphonenumber = $tel['val'][0];
                }elseif(in_array('pager', $tel['type'])){
                    $message->pagernumber = $tel['val'][0];
                }elseif(in_array('cell', $tel['type'])){
                    $message->mobilephonenumber = $tel['val'][0];
                }elseif(in_array('home', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->homefaxnumber = $tel['val'][0];
                    }elseif(empty($message->homephonenumber)){
                        $message->homephonenumber = $tel['val'][0];
                    }else{
                        $message->home2phonenumber = $tel['val'][0];
                    }
                }elseif(in_array('work', $tel['type'])){
                    if(in_array('fax', $tel['type'])){
                        $message->businessfaxnumber = $tel['val'][0];
                    }elseif(empty($message->businessphonenumber)){
                        $message->businessphonenumber = $tel['val'][0];
                    }else{
                        $message->business2phonenumber = $tel['val'][0];
                    }
                }elseif(empty($message->homephonenumber)){
                    $message->homephonenumber = $tel['val'][0];
                }elseif(empty($message->home2phonenumber)){
                    $message->home2phonenumber = $tel['val'][0];
                }else{
                    $message->radiophonenumber = $tel['val'][0];
                }
            }
        }
        //;;street;city;state;postalcode;country
        if(isset($vcard['adr'])){
            foreach($vcard['adr'] as $adr) {
                if(empty($adr['type'])){
                    $a = 'other';
                }elseif(in_array('home', $adr['type'])){
                    $a = 'home';
                }elseif(in_array('work', $adr['type'])){
                    $a = 'business';
                }else{
                    $a = 'other';
                }
                if(!empty($adr['val'][2])){
                    $b=$a.'street';
                    $message->$b = w2ui($adr['val'][2]);
                }
                if(!empty($adr['val'][3])){
                    $b=$a.'city';
                    $message->$b = w2ui($adr['val'][3]);
                }
                if(!empty($adr['val'][4])){
                    $b=$a.'state';
                    $message->$b = w2ui($adr['val'][4]);
                }
                if(!empty($adr['val'][5])){
                    $b=$a.'postalcode';
                    $message->$b = w2ui($adr['val'][5]);
                }
                if(!empty($adr['val'][6])){
                    $b=$a.'country';
                    $message->$b = w2ui($adr['val'][6]);
                }
            }
        }

        if(!empty($vcard['fn'][0]['val'][0]))
            $message->fileas = w2ui($vcard['fn'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][0]))
            $message->lastname = w2ui($vcard['n'][0]['val'][0]);
        if(!empty($vcard['n'][0]['val'][1]))
            $message->firstname = w2ui($vcard['n'][0]['val'][1]);
        if(!empty($vcard['n'][0]['val'][2]))
            $message->middlename = w2ui($vcard['n'][0]['val'][2]);
        if(!empty($vcard['n'][0]['val'][3]))
            $message->title = w2ui($vcard['n'][0]['val'][3]);
        if(!empty($vcard['n'][0]['val'][4]))
            $message->suffix = w2ui($vcard['n'][0]['val'][4]);
        if(!empty($vcard['bday'][0]['val'][0])){
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $message->birthday = strtotime($vcard['bday'][0]['val'][0]);
            date_default_timezone_set($tz);
        }
        if(!empty($vcard['org'][0]['val'][0]))
            $message->companyname = w2ui($vcard['org'][0]['val'][0]);
        if(!empty($vcard['note'][0]['val'][0])){
            $message->body = w2ui($vcard['note'][0]['val'][0]);
            $message->bodysize = strlen($vcard['note'][0]['val'][0]);
            $message->bodytruncated = 0;
        }
        if(!empty($vcard['role'][0]['val'][0]))
            $message->jobtitle = w2ui($vcard['role'][0]['val'][0]);//$vcard['title'][0]['val'][0]
        if(!empty($vcard['url'][0]['val'][0]))
            $message->webpage = w2ui($vcard['url'][0]['val'][0]);
        if(!empty($vcard['categories'][0]['val']))
            $message->categories = $vcard['categories'][0]['val'];
        if(!empty($vcard['photo'][0]['val'][0])) {
		if ( $b64picture = $this->jpegWithSize($vcard['photo'][0]['val'][0], 49152)) { //49152 Value from lib/syncobjects/synccontact.php:177
			$message->picture=$b64picture;
		} else {
			ZLog::Write(LOGLEVEL_ERROR, 'OCContacts::GetMessage: enable to initiate OC_Image object for contact');
		}
	}
	ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::GetMessage: $message = ('.print_r($message,true));
        return $message;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::StatMessage('.$folderid.', '.$id.')');
        if($folderid != "contacts")
            return false;

	if($id == '')
		return false;

        $message = array();

	$cardEntry=OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId,$id.'.vcf');
//	ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::StatMessage('.print_r($cardEntry,true));
        $message["id"] = substr($cardEntry['uri'],0,-4);
	$message["mod"] = $cardEntry['lastmodified'];
        $message["flags"] = 1;

        return $message;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param SyncXXX       $message        the SyncObject containing a message
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message) {
        ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::ChangeMessage('.$folderid.', '.$id.', ..)');
        $mapping = array(
            'fileas' => 'FN',
            'lastname;firstname;middlename;title;suffix' => 'N',
            'email1address' => 'EMAIL;TYPE=INTERNET;TYPE=HOME',
            'email2address' => 'EMAIL;TYPE=INTERNET;TYPE=WORK',
            'email3address' => 'EMAIL;TYPE=INTERNET',
            'businessphonenumber' => 'TEL;TYPE=WORK',
            'business2phonenumber' => 'TEL;TYPE=WORK',
            'businessfaxnumber' => 'TEL;TYPE=WORK;TYPE=FAX',
            'homephonenumber' => 'TEL;TYPE=HOME',
            'home2phonenumber' => 'TEL;TYPE=HOME',
            'homefaxnumber' => 'TEL;TYPE=HOME;TYPE=FAX',
            'mobilephonenumber' => 'TEL;TYPE=CELL',
            'carphonenumber' => 'TEL;TYPE=CAR',
            'pagernumber' => 'TEL;TYPE=PAGER',
            ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;TYPE=WORK',
            ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;TYPE=HOME',
            ';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR',
            'companyname' => 'ORG',
            'body' => 'NOTE',
            'jobtitle' => 'ROLE',
            'webpage' => 'URL',
        );

	$oldNote = "";
	$hasNote = false;
	if(!$id){
		$newvcard = true;
		$id = substr(md5(rand().time()),0,10);
		ZLog::Write(LOGLEVEL_DEBUG, 'id: $id');
// @TODO gere collisions	        while( is_null(OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId,$id.'.vcf'))){
//			ZLog::Write(LOGLEVEL_DEBUG, 'idN: $id');
//            		$id = substr(md5(rand().time()),0,10); 
//		}
	} else {
		$newvcard = false;

		$card = OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId, $id.'.vcf');
		$data = $card['carddata'];
		$data = str_replace("\x00", '', $data);
		$data = str_replace("\r\n", "\n", $data);
		$data = str_replace("\r", "\n", $data);
		$data = preg_replace('/(\n)([ \t])/i', '', $data);
		$lines = explode("\n", $data);

		foreach($lines as $line) {
		    if (trim($line) == '')
			continue;
		    $pos = strpos($line, ':');
		    if ($pos === false)
			continue;
		    $field = trim(substr($line, 0, $pos));
		    $value = trim(substr($line, $pos+1));

		    if (strtolower($field) === "note") {
			$oldNote = $value;
			ZLog::Write(LOGLEVEL_DEBUG, "Old note: " . $oldNote);
			break;
		    }

		}
	}

        $data = "BEGIN:VCARD\nVERSION:2.1\nPRODID:Z-Push\n";
        foreach($mapping as $k => $v){
            $val = '';
            $ks = explode(';', $k);
	    foreach($ks as $i){
                if(!empty($message->$i))
		{
			
		    ZLog::Write(LOGLEVEL_DEBUG,"\$message->\$i=".$message->$i);
                    $val .= $this->escape($message->$i);
		}
                $val.=';';
	    
		if ($i === 'body' && isset($message->$i))
		    $hasNote = true;
            }
	    
	    if(preg_match('/^[;]*$/',$val))
		continue;

	    ZLog::Write(LOGLEVEL_DEBUG,"\$val=$val");
            $val = substr($val,0,-1);
            if(strlen($val)>50){
                $data .= $v.":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
            }else{
                $data .= $v.':'.$val."\n";
	    }
        }
        if(!empty($message->categories))
            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
        if(!empty($message->picture))
            $data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n\t".substr(chunk_split($message->picture, 50, "\n\t"), 0, -1);
        if(isset($message->birthday))
	    $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
	if(!$hasNote) {
	    ZLog::Write(LOGLEVEL_DEBUG, "Keeping old note");
	    $data .= 'NOTE:'.$oldNote."\n";
	} else {
	    ZLog::Write(LOGLEVEL_DEBUG, "Using new note");
	}

// not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf
	$data .= "UID:$id\n";
	$data .= "END:VCARD";

	if ($newvcard)
	{
		ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::ChangeMessage, OC_Contacts_VCard::add('.$this->addressBookId.', '.$data);
		OC_Contacts_VCard::addFromDAVData($this->addressBookId,$id.".vcf",$data);
        } else {
		ZLog::Write(LOGLEVEL_DEBUG, 'OCContacts::ChangeMessage, OC_Contacts_VCard::edit('.$this->addressBookId.', '.$data);
		OC_Contacts_VCard::editFromDAVData($this->addressBookId,$id.".vcf",$data);
	}
        return $this->StatMessage($folderid, $id);
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags) {
        return false;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id) {
	$card=OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId,$id.'.vcf');
	return OC_Contacts_VCard::delete($card['id']);
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     * not implemented
     *
     * @param string        $folderid       id of the source folder
     * @param string        $id             id of the message
     * @param string        $newfolderid    id of the destination folder
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }


    /**----------------------------------------------------------------------------------------------------------
     * private vcard-specific internals
     */

    /**
     * Escapes a string
     *
     * @param string        $data           string to be escaped
     *
     * @access private
     * @return string
     */
    function escape($data){
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        return u2wi($data);
    }

    /**
     * Un-escapes a string
     *
     * @param string        $data           string to be un-escaped
     *
     * @access private
     * @return string
     */
    function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }

    /**
     * resize an image to fit in a limited weight
     *
     * @param string	          $picture         image string (png, jpeg, etc..)
     * @param integer		  $maxSize         maximum weight in octets
     * @param float		  $stepsize	   after first resize try, iterate at this stepsize until good
     *
     * @access private
     * @return string		  $b64		   base64 encoded image
     */

    function jpegWithSize($picture,$maxSize,$stepsize=0.25) {
    	$image=new OC_Image($picture);
	$b64=$this->gd2jpeg($image);
	ZLog::Write(LOGLEVEL_DEBUG, 'jpegWithSize : start with curSize: '.strlen($b64).' o / '.$maxSize.' o');
        if (strlen($b64) > $maxSize) {
                ZLog::Write(LOGLEVEL_DEBUG, 'jpegWithSize : curSize: '.strlen($b64).' o >'.$maxSize.' o');
                $b64=$this->gd2jpeg($image,$maxSize/strlen($b64));
                $i=0;
                while (strlen($b64) > $maxSize and $i<10) {
                        $i++;
                        $b64=$this->gd2jpeg($image,$stepsize);
                        ZLog::Write(LOGLEVEL_DEBUG, 'jpegWithSize : curSize: '.strlen($b64).' o >'.$maxSize.' o');
                }
                if (strlen($b64) > $maxSize) {
                        ZLog::Write(LOGLEVEL_WARN, 'jpegWithSize : not in bounds after '.$i.' sizes !!!! bizarre...');
                        return null;
                }
        }
	ZLog::Write(LOGLEVEL_DEBUG, 'jpegWithSize : return with curSize: '.strlen($b64).' o / '.$maxSize.' o');
        return $b64;
     }


    /**
     * convert image from gd to jpeg string (optionaly with resize by ratio)
     *
     * @param gd ressource        $image           image to be converted
     * @param float		  $ratio	   resize factor
     *
     * @return string                              jpeg image (base64 encoded)
     */

     function gd2jpeg(& $image,$ratio = 1) {
     	if ( $ratio != 1 ) {
	        $maxPix=max(imageSX($image->resource()),imageSY($image->resource()));
		$image->resize($maxPix*$ratio);
	}
	ob_start();
	$res = imagejpeg($image->resource());
        if (!$res) {
        	ZLog::Write(LOGLEVEL_DEBUG, 'gd2jpeg : Error getting image data.');
        }
        return base64_encode(ob_get_clean());
     }

};
?>
