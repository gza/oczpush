<?php
/***********************************************
* File      :   owncloud.php (based on vcarddir.php from z-push + functions from php-push https://github.com/dupondje/PHP-Push)
* Project   :   oczpush, https://github.com/gza/oczpush
* Descr     :   This backend is for owncloud (calendar only)
* Licence   :	AGPL
************************************************/

if (! defined('STORE_SUPPORTS_UNICODE') ) define('STORE_SUPPORTS_UNICODE', true);
setlocale(LC_CTYPE, "en_US.UTF-8");
if (! defined('STORE_INTERNET_CPID') ) define('STORE_INTERNET_CPID', INTERNET_CPID_UTF8);

include_once('lib/default/diffbackend/diffbackend.php');
include_once('lib/oc/z_RTF.php');
include_once('lib/oc/class_ical_client.php');

require_once(OC_DIR.'/lib/config.php');
require_once(OC_DIR.'/lib/base.php');

// Check if we are a user
OC_Util::checkAppEnabled('calendar');

class BackendOCCalendar extends BackendDiff {
    
    var $calendarId;
    var $_currentTimezone = null;

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
    	ZLog::Write(LOGLEVEL_DEBUG,'OCCalendar::Logon('.$username.')');
        if(OC_User::login($username,$password)){
            OC_Util::setUpFS();
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::Logon : Logged');
	    $calendars = OC_Calendar_Calendar::allCalendars($username);
	    $this->calendarId = $calendars[0]['id'];
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::Logon : Calendar selected :'.$calendars[0]['displayname']);
	    return true;
        }
        else {
     	    ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::Logon : Not Logged');
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

    public function Fetch($folderid, $id, $contentparameters) {
    	ZLog::Write(LOGLEVEL_DEBUG,  "OCCalendar::Fetch: $folderid, $id, ...");
        $msg = $this->GetMessage($folderid, $id, $contentparameters); 
	if ($msg === false)
	     throw new StatusException("BackendDiff->Fetch('%s','%s'): Error, unable retrieve message from backend", SYNC_STATUS_OBJECTNOTFOUND);
	return $msg;
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetFolderList()');
        $folders = array();
        $folder = $this->StatFolder("calendar");
        $folders[] = $folder;
        $folder = $this->StatFolder("tasks");
        $folders[] = $folder;

        return $folders;
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetFolder('.$id.')');
	 if ($id == "calendar") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "Calendar";
            $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
            return $folder;
        }
        if ($id == "tasks") {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "Tasks";
            $folder->type = SYNC_FOLDER_TYPE_TASK;
            return $folder;
        }
        return false;
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::StatFolder('.$id.')');
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetMessageList('.$folderid.')');
        $messages = array();
//@TODO filter on objecttype
	foreach ( OC_Calendar_Object::all($this->calendarId) as $objectEntry ) {
		if ($objectEntry['lastmodified']>=$cutoffdate) {
			$message["id"] = substr($objectEntry['uri'],0,-4);		
			$message["mod"] = $objectEntry['lastmodified'];
			$message["flags"] = 1;
			if ($message["id"] == '') break;
			
			$messages[] = $message;
		} else {
			ZLog::Write(LOGLEVEL_DEBUG, "OCCalendar::GetMessageList cutoffdate" . $objectEntry['lastmodified']." < ".$cutoffdate);
		}
	}
	ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetMessageList: $messages = ('.print_r($messages,true));	
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetMessage('.$folderid.', '.$id.', ..)');
	
	$event = new SyncAppointment();

	$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

        if ($folderid != "calendar" and $folderid != "tasks")
		return;

	if ( $id == '' ) return;

	$calob=OC_Calendar_Object::findWhereDAVDataIs($this->calendarId, $id.'.ics');
//	ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetMessage $calob '.$id.'.ics'.'-'.print_r($calob,true));
	$v = new vcalendar();
	$v->parse($calob['calendardata']);
	$v->sort();

	if ($vtimezone = $v->getComponent( 'vtimezone' )) {
        	$this->_currentTimezone = $vtimezone->getProperty('tzid');
	}

	$vcounter = 1;
        if ($folderid == "tasks") {
            while ($vtodo = $v->getComponent('vtodo', $vcounter)) {
                $message = $this->converttotask($vtodo,$truncsize);
                $vcounter++;
            }
        } else {
            $fullexceptionsarray = array();
            while ($vevent = $v->getComponent( 'vevent', $vcounter)) {
                $val = $vevent->getProperty("RECURRENCE-ID");
                if ($val === false) {
                    $message = $this->converttoappointment($vevent,$truncsize);
                } else {
                    $tmp = $this->converttoappointment($vevent,$truncsize);
                    $tmp->deleted = "0";
                    //The exceptionstarttime is the ORIGINAL starttime of the event
                    //On Thunderbird this is equal to the RECCURENCE-ID (which is in $val)
                    $tmp->exceptionstarttime = $this->makeGMTTime($val);
                    unset($tmp->uid);
                    unset($tmp->exceptions);
                    array_push($fullexceptionsarray, $tmp);
                    unset($tmp);
                }
                $vcounter++;
            }
            $message->exceptions = array_merge($message->exceptions, $fullexceptionsarray);
        }

        if ($vtimezone = $v->getComponent( 'vtimezone' )) {
            $message = $this->setoutlooktimezone($message, $vtimezone);
        } 
//	ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::GetMessage: $message = ('.print_r($message,true));
	if ($message->Check())
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::StatMessage('.$folderid.', '.$id.')');
//        if (in_array($folderid,array('calendar','tasks')))
//            return false;

	if($id == '')
		return false;

        $message = array();

	$objectEntry=OC_Calendar_Object::findWhereDAVDataIs($this->calendarId,$id.'.ics');
	$object = OC_VObject::parse($objectEntry['calendardata']);
	ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::StatMessage('.print_r($objectEntry,true));
        $message["id"] = $id;
	$message["mod"] = $objectEntry['lastmodified'];
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
        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage('.$folderid.', '.$id.', ..)');

	$new = false;

        if (trim($id) != "") {
            $return = $this->StatMessage($folderid, $id);
        } else {
            $return = false;
        }
        if ($return === false) {
            ZLog::Write(LOGLEVEL_DEBUG,'CalDAV::Found new message on device');
		$new = true;
            #create new id, this is a new record from device.
            $date = date('Ymd\THisT');
            $unique = substr(microtime(), 2, 4);
            $base = 'aAbBcCdDeEfFgGhHiIjJkKlLmMnNoOpPrRsStTuUvVxXuUvVwWzZ1234567890';
            $start = 0;
            $end = strlen( $base ) - 1;
            $length = 6;
            $str = null;
            for($p = 0; $p < $length; $p++)
                $unique .= $base{mt_rand( $start, $end )};
            $id = $date.'-'.$unique;
        } else {
            ZLog::Write(LOGLEVEL_DEBUG,'CalDAV::Event Already On Server');
        }

        $task = false;

        if ($folderid == "tasks") {
            $vtodo = $this->converttovtodo($message);
            if (substr($id, strlen($id)-4) == ".ics") {
                $vtodo->setProperty( "UID", substr($id, 0, -4));
            } else {
                $vtodo->setProperty( "UID", $id);
            }
        } else {
            $vevent = $this->converttovevent($message);
            $exarray = array();
            if (isset($message->exceptions) && is_array($message->exceptions)) {
                $deletedarray = array();
                foreach ($message->exceptions as $ex) {
                    if ($ex->deleted == "1") {
                        array_push($deletedarray, $this->parseDate($ex->exceptionstarttime));
                    } else {
                        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV::Found non deleted exception Converting...');
                        $tmpevent = $this->converttovevent($ex);
                        if (isset($ex->alldayevent) && $ex->alldayevent == "1") {
                            $tmpevent->setProperty("recurrence-id", $this->parseDate($ex->exceptionstarttime), array('VALUE'=>'DATE'));
                        } else {
                            $tmpevent->setProperty("recurrence-id", $this->parseDate($ex->exceptionstarttime));
                        }
                        array_push($exarray, $tmpevent);
                    }
                }
                ZLog::Write(LOGLEVEL_DEBUG,"CalDAV:: ".print_r($deletedarray ,true));
                if (count($deletedarray) > 0) {
                    $vevent->setProperty("exdate", $deletedarray);
                }
            }
            if (substr($id, strlen($id)-4) == ".ics") {
                $vevent->setProperty( "UID", substr($id, 0, -4));
            } else {
                $vevent->setProperty( "UID", $id);
            }
        }
        
        // Set mod date to current
        $mod = $this->parseGMTDate(time());

        # $somethingelse = convert2ical();
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV::Converted to iCal: ');
        $v = new vcalendar();
        
        if ($folderid == "tasks") {
            $vtodo->setProperty("LAST-MODIFIED", $mod);
            $v->setComponent( $vtodo );
        } else {
            $vevent->setProperty("LAST-MODIFIED", $mod);
            $v->setComponent( $vevent );
            if (count($exarray) > 0) {
                foreach($exarray as $exvevent) {
                    $sdt = $exvevent->getProperty("dtstart");
    
                    if (substr($id, strlen($id)-4) == ".ics") {
                        $exvevent->setProperty( "UID", substr($id, 0, -4));
                    } else {
                        $exvevent->setProperty( "UID", $id);
                    }
    
                    $v->setComponent( $exvevent );
                }
            }
        }
        
        $output = $v->createCalendar();
        ZLog::Write(LOGLEVEL_DEBUG,"CalDAV::putting to ".$id);

	if ($new)
	{
		ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage, OC_Calendar_Object::addFromDAVData('.$this->calendarId.', '.$output);
		OC_Calendar_Object::addFromDAVData($this->calendarId,$id.".ics",$output);
        } else {
		ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage, OC_Calendar_Object::editFromDAVData('.$this->calendarId.', '.$output);
		OC_Calendar_Object::editFromDAVData($this->calendarId,$id.".ics",$output);
	}

        ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage,StatMessage='.print_r($this->StatMessage($folderid, $id),true));
        return $this->StatMessage($folderid, $id);

//        $mapping = array(
//            'fileas' => 'FN',
//            'lastname;firstname;middlename;title;suffix' => 'N',
//            'email1address' => 'EMAIL;INTERNET',
//            'email2address' => 'EMAIL;INTERNET',
//            'email3address' => 'EMAIL;INTERNET',
//            'businessphonenumber' => 'TEL;WORK',
//            'business2phonenumber' => 'TEL;WORK',
//            'businessfaxnumber' => 'TEL;WORK;FAX',
//            'homephonenumber' => 'TEL;HOME',
//            'home2phonenumber' => 'TEL;HOME',
//            'homefaxnumber' => 'TEL;HOME;FAX',
//            'mobilephonenumber' => 'TEL;CELL',
//            'carphonenumber' => 'TEL;CAR',
//            'pagernumber' => 'TEL;PAGER',
//            ';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;WORK',
//            ';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;HOME',
//            ';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR',
//            'companyname' => 'ORG',
//            'body' => 'NOTE',
//            'jobtitle' => 'ROLE',
//            'webpage' => 'URL',
//        );
//        $data = "BEGIN:VCARD\nVERSION:2.1\nPRODID:Z-Push\n";
//        foreach($mapping as $k => $v){
//            $val = '';
//            $ks = explode(';', $k);
//	    foreach($ks as $i){
//                if(!empty($message->$i))
//		{
//			
//	    	ZLog::Write(LOGLEVEL_DEBUG,"\$message->\$i=".$message->$i);
//                    $val .= $this->escape($message->$i);
//		}
//                $val.=';';
//            }
//            if(preg_match('/^[;]*$/',$val))
//                continue;
//	    ZLog::Write(LOGLEVEL_DEBUG,"\$val=$val");
//            $val = substr($val,0,-1);
//            if(strlen($val)>50){
//                $data .= $v.":\n\t".substr(chunk_split($val, 50, "\n\t"), 0, -1);
//            }else{
//                $data .= $v.':'.$val."\n";
//            }
//        }
//        if(!empty($message->categories))
//            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
//        if(!empty($message->picture))
//            $data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n\t".substr(chunk_split($message->picture, 50, "\n\t"), 0, -1);
//        if(isset($message->birthday))
//            $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
//
//// not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf
//
//        if(!$id){
//		$newvcard = true;
//		$id = substr(md5(rand().time()),0,10);
//		ZLog::Write(LOGLEVEL_DEBUG, 'id: $id');
//// @TODO gere collisions	        while( is_null(OC_Contacts_VCard::findWhereDAVDataIs($this->addressBookId,$id.'.vcf'))){
////			ZLog::Write(LOGLEVEL_DEBUG, 'idN: $id');
////            		$id = substr(md5(rand().time()),0,10); 
////		}
//	} else {
//		$newvcard = false;
//	};
//	$data .= "UID:$id\n";
//	$data .= "END:VCARD";
//
//	if ($newvcard)
//	{
//		ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage, OC_Contacts_VCard::add('.$this->addressBookId.', '.$data);
//		OC_Contacts_VCard::addFromDAVData($this->addressBookId,$id.".vcf",$data);
//        } else {
//		ZLog::Write(LOGLEVEL_DEBUG, 'OCCalendar::ChangeMessage, OC_Contacts_VCard::edit('.$this->addressBookId.', '.$data);
//		OC_Contacts_VCard::editFromDAVData($this->addressBookId,$id.".vcf",$data);
//	}
//        return $this->StatMessage($folderid, $id);
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
	$objectEntry=OC_Calendar_Object::findWhereDAVDataIs($this->calendarId,$id.'.ics');
	return OC_Calendar_Object::delete($objectEntry['id']);
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
    
    private function setoutlooktimezone($message, $vtimezone) {
        $message->timezone = $this->getTimezoneString($this->_currentTimezone);
        return $message;
    }

    private function getdeletedexceptionobject($val) {
        $rtn = new SyncAppointment();
        $rtn->deleted = "1";
        if (is_array($val)) {
            $val = $this->makeGMTTime($val);
        } else {
            $val = $this->parseDateToOutlook($val);
        }
        $rtn->exceptionstarttime = $val;
        
        return $rtn;
    }

    private function converttoappointment($vevent, $truncsize) {
        ZLog::Write(LOGLEVEL_DEBUG,"OCCalendar::converting vevent to outlook appointment");
        $message = new SyncAppointment();
        $message->alldayevent = "0";
        $message->sensitivity = "0";
        $message->meetingstatus = "0";
        $message->busystatus = "2";

        $mapping = array(
            "dtstart" => array("starttime", 3),
            "dtstamp" => array("dtstamp", 3),
            "dtend" => array("endtime", 3)
        );

        $message = $this->converttooutlook($message, $vevent, $truncsize, $mapping);

        if (($message->endtime-$message->starttime) >= 24*60*60) {
            ZLog::Write(LOGLEVEL_DEBUG,"CalDAV:: alldayevent sdt edt diff : Endtime: " . $message->endtime . " Startime: " . $message->starttime);
            $message->alldayevent = "1";
        }

        $mapping = array(
            "class" => array("sensitivity", 1),
            "description" => array("body", 2),
            "location" => array("location", 0),
            "organizer" => array("organizername", 4),
            "status" => array("meetingstatus", 1),
            "summary" => array("subject", 9),
            "transp" => array("busystatus", 1),
            "uid" => array("uid", 8),
            "rrule" => array("recurrence", 5),
            "duration" => array("endtime", 6),
            "attendee" => array("attendees", 13),
            "categories" => array("categories", 10),
            "valarm" => array("reminder", 7)
        );

        $message = $this->converttooutlook($message, $vevent, $truncsize, $mapping, new SyncRecurrence());

        $excounter = 1;
        $tmparray = array();
        while (is_array($vevent->getProperty("exdate", $excounter))) {
            $val = $vevent->getProperty("exdate", $excounter);
            if (!array_key_exists("year", $val)) {
                foreach ($val as $exdate) {
                    if (is_array($exdate)) {
                        array_push($tmparray, $this->getdeletedexceptionobject($exdate));
                    }
                }
            }
            $excounter++;
        }
        $message->exceptions = $tmparray;
        return $message;
    }
   
  function converttotask($vtodo, $truncsize) {
        ZLog::Write(LOGLEVEL_DEBUG,"CalDAV::converting vtodo to outlook appointment");
        $message = new SyncTask();
        $message->sensitivity = "0";
    
        $mapping = array(
            "class" => array("sensitivity", 1),
            "description" => array("body", 2),
            "completed" => array("datecompleted", 11),
            "status" => array("complete", 1),
            "due" => array("duedate", 11),
            "dtstart" => array("startdate", 11),
            "summary" => array("subject", 9),
            "priority" => array("importance", 1),
            "uid" => array("uid", 8),
            "rrule" => array("recurrence", 5),
            "categories" => array("categories", 10),
            "valarm" => array("reminder", 12)
        );

        $message = $this->converttooutlook($message, $vtodo, $truncsize, $mapping, new SyncTaskRecurrence());
        return $message;
    }

        function converttooutlook($message, $icalcomponent, $truncsize, $mapping, $rruleobj = false) {
        foreach($mapping as $k => $e) {
            $val = $icalcomponent->getProperty($k);
            if ($val !== false) {
            // if found $k in event convert and put in message
                if ($e[1] == 0) {
                    $val = trim($val);
                }
                if ($e[1] == 1) {
                    $val = trim(strtoupper($val));
                    switch ($e[0]) {
                        case "importance":
                            if ($val > 6) {
                                $val = "0";
                            } else  if ($val > 3 && $val < 7) {
                                $val = "1";
                            } else if ($val < 4) {
                                $val = "2";
                            }
                        break;

                        case "sensitivity":
                            switch ( $val ) {
                                case "PUBLIC":
                                    $val = 0;
                                    break;
                                case "PRIVATE":
                                    $val = 2;
                                    break;
                                case "CONFIDENTIAL":
                                    $val = 3;
                                    break;
                            }    
                        break;
                        case "meetingstatus":
                            switch ( $val ) {
                                case "TENTATIVE":
                                    $val = 1;
                                    break;
                                case "CONFIRMED":
                                    $val = 3;
                                    break;
                                case "CANCELLED":
                                    $val = 5;
                                    break;
    
                            }    
                        break;
                        case "busystatus":
                            switch ( $val ) {
                                case "TRANSPARENT":
                                    $val = 0;
                                    break;
                                case "OPAQUE":
                                    $val = 2;
                                    break;
                                default:
                                    $val = 2;
                            }    
                        break;
                        case "complete":
                            switch ($val) {
                                case "NEEDS-ACTION":
                                case "IN-PROCESS":
                                case "CANCELLED":
                                    $val = "0";
                                    break;
                                case "COMPLETED":
                                default:
                                    $val = "1";
                            }
                    } 
                }
                if ($e[1] == 2) {
                    if ($truncsize != 0 && strlen($val) > $truncsize) {
                        $message->bodytruncated = 1;
                        $val = substr($val, 0, $truncsize);
                    }
                    $val = str_replace("\\n", "\r\n", $val);
                }
                if ($e[1] == 3) {
                    // convert to date
                    if (is_array($val)) {
                        if (!empty($val['TZID'])) {
                            $message->timezone = $this->getTimezoneString($val['TZID']);
                        }
                        $val = $this->makeGMTTime($val);
                    } else {
                        $val =  $this->parseDateToOutlook($val);
                    }
                }
                if ($e[1] == 4) {
                    // extract organizers name and email
                    $val = trim($val);
                    $message->organizeremail = $val;
                    $val = $this->parseOrganizer($val);
                }
                if ($e[1] == 5) {
                    // recurrence?
                    $val = $this->getrecurrence($val, $message->starttime, $rruleobj);
                }
                if ($e[1] == 6) {
                    // duration
                    $starttime = $this->parseDate($vevent->getProperty("dtstart"));
                    $duration = $val;
                    $week = $this->parseDuration($duration, "W");
                    $starttime = $this->dateAdd("W", $week, $starttime);
                    $hour = $this->parseDuration($duration, "H");
                    $starttime = $this->dateAdd("H", $hour, $starttime);
                    $minute = $this->parseDuration($duration, "M");
                    $starttime = $this->dateAdd("M", $minute, $starttime);
                    $second = $this->parseDuration($duration, "S");
                    $starttime = $this->dateAdd("S", $second, $starttime);
                    $day = $this->parseDuration($duration, "D");
                    $starttime = $this->dateAdd("D", $day, $starttime);
                    if ($week > 0 || $day > 0) {
                        $message->alldayevent = "1";
                    } else {
                        $message->alldayevent = "0";                
                    }
                    $val = $startime;
                }
                if ($e[1] == 8) {
                    $val = bin2hex($val);
                }
                if ($e[1] == 9) {
                    $val = str_replace("\n", "", trim($val));
                }
                if ($e[1] == 10) {
                    $val = explode(",", $val);
                    foreach ($val as $k => $v) {
                        $val[$k] = trim($v);
                    }
                }
                if ($e[1] == 11) {
                    if (is_array($val)) {
                        if (!empty($val['TZID'])) {
                            $message->timezone = $this->getTimezoneString($val['TZID']);
                        }
                        $val = $this->makeGMTTime($val);
                    } else {
                        $val = $this->parseDateToOutlook($val);
                    }
                }
                if ($e[1] == 13) {
                    $tmpcounter = 1;
                    $val = array();
                    while ($tmpval = $icalcomponent->getProperty($k, $tmpcounter)) {
                        $tmp = new SyncAttendee();
                        $tmp->email = trim($tmpval);
                        $tmpval2 = $icalcomponent->getProperty($k, $tmpcounter, TRUE);
                        if (isset($tmpval2['params']['CN'])) $tmp->name = $tmpval2['params']['CN'];
                        array_push($val, $tmp);
                        $tmpcounter++;
                    }
                }
                $message->$e[0] = $val;             
            }
            if ($e[1] == 7) {
                $val = $icalcomponent->getComponent($k);
                if (is_object($val)) {
                    $trigger = $val->getProperty("trigger");
                    if (is_array($trigger)) {
                        $reminder = 0;
                        if (array_key_exists("min", $trigger))
                        {
                            $reminder += $trigger["min"];
                        }
                        if (array_key_exists("hour", $trigger))
                        {
                            $reminder += $trigger["hour"] * 60;
                        }
                        $message->$e[0] = $reminder;
                    } else {
                        $message->$e[0] = "";                   
                    }
                } else {
                    $message->$e[0] = "";
                }
            }
            if ($e[1] == 12) {
                $val = $icalcomponent->getComponent($k);
                if (is_object($val)) {
                    $trigger = $val->getProperty("trigger");
                    if (is_array($trigger)) {
                        $message->$e[0] = $trigger["min"];
                    }
                }
            }
        }
        return $message;
    }   
     
    function getrecurrence($args, $sdt, $rtn) {
        switch (trim(strtoupper($args['FREQ']))) {
            case "DAILY":
                $rtn->type = "0";
                break;
            case "WEEKLY":
                $rtn->type = "1";
                $day = date('N', $sdt);
                if ($day == 7) $daybin = 1;
                if ($day == 1) $daybin = 2;
                if ($day == 2) $daybin = 4;
                if ($day == 3) $daybin = 8;
                if ($day == 4) $daybin = 16;
                if ($day == 5) $daybin = 32;
                if ($day == 6) $daybin = 64;
                $rtn->dayofweek = $daybin;
                break;
            case "MONTHLY":
                $rtn->type = "2";
                $rtn->dayofmonth = date('d', $sdt);
                break;
            case "YEARLY":
                $rtn->type = "5";
                $rtn->dayofmonth = date('d', $sdt);
                $rtn->monthofyear = date('m', $sdt);
                break;
        }

        if (array_key_exists("BYDAY", $args) && is_array($args['BYDAY'])) {
            $daybin = 0;
            $single = false;
            foreach ($args['BYDAY'] as $day) {
                if (is_array($day)) {
                    if (count($day) == 2) {
                        $rtn->weekofmonth = $day[0];
                        if ($rtn->type == "2") $rtn->type = "3";
                        if ($rtn->type == "5") $rtn->type = "6";
                        $rtn->dayofmonth = "";
                    }
                    if ($day["DAY"] == "SU") $daybin += 1;
                    if ($day["DAY"] == "MO") $daybin += 2;
                    if ($day["DAY"] == "TU") $daybin += 4;
                    if ($day["DAY"] == "WE") $daybin += 8;
                    if ($day["DAY"] == "TH") $daybin += 16;
                    if ($day["DAY"] == "FR") $daybin += 32;
                    if ($day["DAY"] == "SA") $daybin += 64;
                } else {
                    $single = true;
                    break;
                }
            }
            if ($single) {
                if (count($args['BYDAY']) == 2) {
                    $rtn->weekofmonth = $args['BYDAY'][0];
                    if ($rtn->type == "2") $rtn->type = "3";
                    if ($rtn->type == "5") $rtn->type = "6";
                }
                if ($args['BYDAY']["DAY"] == "SU") $daybin += 1;
                if ($args['BYDAY']["DAY"] == "MO") $daybin += 2;
                if ($args['BYDAY']["DAY"] == "TU") $daybin += 4;
                if ($args['BYDAY']["DAY"] == "WE") $daybin += 8;
                if ($args['BYDAY']["DAY"] == "TH") $daybin += 16;
                if ($args['BYDAY']["DAY"] == "FR") $daybin += 32;
                if ($args['BYDAY']["DAY"] == "SA") $daybin += 64;
            }
            $rtn->dayofweek = $daybin;
        }
        if (array_key_exists("DAYOFMONTH", $args)) {
            if (is_numeric($args['DAYOFMONTH'])) $rtn->dayofmonth = $args['DAYOFMONTH'];
        }
        if (array_key_exists("MONTHOFYEAR", $args)) {
            if (is_numeric($args['MONTHOFYEAR'])) $rtn->monthofyear = $args['MONTHOFYEAR'];
        }
    
        if (array_key_exists("COUNT", $args)) $rtn->occurrences = $args['COUNT'];
        if (array_key_exists("INTERVAL", $args))
            $rtn->interval = $args['INTERVAL'];
        else
            $rtn->interval = "1";
        if (array_key_exists("UNTIL", $args)) $rtn->until = gmmktime($args['UNTIL']['hour'], $args['UNTIL']['min'], $args['UNTIL']['sec'], $args['UNTIL']['month'], $args['UNTIL']['day'], $args['UNTIL']['year']);
        
        return $rtn;
    }
    
    function parseDuration($duration, $interval) {
        $temp = strpos($duration, $interval);
        if ($temp !== false) {
            $end = $temp;
            while ($temp > 0 && isdigit(substr($duration, $temp, 1))) {
                $temp--;
            }
            return substr($duration, $temp, $end - $temp);
        } else {
            return 0;
        }
    }
    
    function isdigit($char) {
        return in_array($char, array("0", "1", "2", "3", "4", "5", "6", "7", "8", "9"));
    }
    
    function parseDate($ts, $extradays = 0) {
        $ts = $ts + ($extradays*24*60*60);
        return date('Ymd\THis', $ts);
    }
    function parseGMTDate($ts, $extradays = 0) {
        $ts = $ts + ($extradays*24*60*60);
        return gmdate('Ymd\THis\Z', $ts);
    }
 
    function parseDateToOutlook($ts) {
        return strtotime($ts);
    }
    
    function parseOrganizer($val) {
        $name = substr($val, 0, strpos($val, "@"));
        return $name;
    }
    
    function dateAdd($interval, $number, $date) {
        $date_time_array = getdate($date);
        $hours = $date_time_array['hours'];
        $minutes = $date_time_array['minutes'];
        $seconds = $date_time_array['seconds'];
        $month = $date_time_array['mon'];
        $day = $date_time_array['mday'];
        $year = $date_time_array['year'];
    
        switch ($interval) {
            case 'D':
                $day+=$number;
                break;
            case 'W':
                $day+=($number*7);
                break;
            case 'H':
                $hours+=$number;
                break;
            case 'M':
                $minutes+=$number;
                break;
            case 'S':
                $seconds+=$number;
                break;
        }
        $timestamp= mktime($hours,$minutes,$seconds,$month,$day,$year);
        return $timestamp;
    }
   
    function makeGMTTime($val)
    {
        $tz = timezone_open('GMT');
        if (!empty($val['TZID'])) {
            $tz = timezone_open($val['TZID']);
        }
        elseif ($this->_currentTimezone)
        {
            $tz = timezone_open($this->_currentTimezone);
        }
        $timestr = null;
        $date = null;
        if (array_key_exists('hour', $val) && array_key_exists('min', $val) && array_key_exists('sec', $val)) {
            $timestr = sprintf("%d-%d-%d %d:%02d:%02d", $val['year'], $val['month'], $val['day'], $val['hour'], $val['min'], $val['sec']);
        }
        else {
            $timestr = sprintf("%d-%d-%d %d:%02d:%02d", $val['year'], $val['month'], $val['day'], 0, 0, 0);
        }
        $date = date_create_from_format('Y-m-d H:i:s', $timestr, $tz);
        return date_timestamp_get($date);
    }

    // This returns a timezone that matches the timezonestring.
    // We can't be sure this is the one you chose, as multiple timezones have same timezonestring
    function getTimezoneFromString($tz_string)
    {
        //Get a list of all timezones
        $identifiers = DateTimeZone::listIdentifiers();
        //Try the default timezone first
        array_unshift($identifiers, date_default_timezone_get());
        foreach ($identifiers as $tz)
        {
            $str = $this->getTimezoneString($tz, false);
            if ($str == $tz_string)
            {
                ZLog::Write(LOGLEVEL_DEBUG,"getTimezoneFromString: timezone is " . $tz);
                return $tz;
            }
        }
        return date_default_timezone_get();
    }

    function getTimezoneString($timezone, $with_names = true)
    {
        // UTC needs special handling
        if ($timezone == "UTC")
            return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
        try {
            //Generate a timezone string (PHP 5.3 needed for this)
            ZLog::Write(LOGLEVEL_DEBUG,"getTimezoneString: Generating timezone base64");
            $timezone = new DateTimeZone($timezone);
            $trans = $timezone->getTransitions(time());
            $stdTime = null;
            $dstTime = null;
            if (count($trans) < 3)
            {
                throw new Exception();
            }
            if ($trans[1]['isdst'] == 1)
            {
                $dstTime = $trans[1];
                $stdTime = $trans[2];
            }
            else
            {
                $dstTime = $trans[2];
                $stdTime = $trans[1];
            }
            $stdTimeO = new DateTime($stdTime['time']);
            $stdFirst = new DateTime(sprintf("first sun of %s %s", $stdTimeO->format('F'), $stdTimeO->format('Y')));
            $stdInterval = $stdTimeO->diff($stdFirst);
            $stdDays = $stdInterval->format('%d');
            $stdBias = $stdTime['offset'] / -60;
            $stdName = $stdTime['abbr'];
            $stdYear = 0;
            $stdMonth = $stdTimeO->format('n');
            $stdWeek = floor($stdDays/7)+1;
            $stdDay = $stdDays%7;
            $stdHour = $stdTimeO->format('H');
            $stdMinute = $stdTimeO->format('i');
            $stdTimeO->add(new DateInterval('P7D'));
            if ($stdTimeO->format('n') != $stdMonth)
            {
                $stdWeek = 5;
            }
            $dstTimeO = new DateTime($dstTime['time']);
            $dstFirst = new DateTime(sprintf("first sun of %s %s", $dstTimeO->format('F'), $dstTimeO->format('Y')));
            $dstInterval = $dstTimeO->diff($dstFirst);
            $dstDays = $dstInterval->format('%d');
            $dstName = $dstTime['abbr'];
            $dstYear = 0;
            $dstMonth = $dstTimeO->format('n');
            $dstWeek = floor($dstDays/7)+1;
            $dstDay = $dstDays%7;
            $dstHour = $dstTimeO->format('H');
            $dstMinute = $dstTimeO->format('i');
            if ($dstTimeO->format('n') != $dstMonth)
            {
                $dstWeek = 5;
            }
            $dstBias = ($dstTime['offset'] - $stdTime['offset']) / -60;
            if ($with_names)
            {
                return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, $stdName, 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, $dstName, 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
            }
            else
            {
                return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', $stdBias, '', 0, $stdMonth, $stdDay, $stdWeek, $stdHour, $stdMinute, 0, 0, 0, '', 0, $dstMonth, $dstDay, $dstWeek, $dstHour, $dstMinute, 0, 0, $dstBias));
            }
        }
        catch (Exception $e) {
            // If invalid timezone is given, we return UTC
            return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
        }
        return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
    }
    
    function converttovevent($message) {
    
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV:: About to create new event.');
        $vevent = new vevent();
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV:: About to create mapping array.');
      
        if (isset($message->timezone))
        {
            $this->_currentTimezone = $this->getTimezoneFromString($message->timezone);
        }

        $allday = false;
        if (isset($message->alldayevent)) {
            $val = $message->alldayevent;
            if (trim($val) == '1') {
                $allday = true;
            }
        }

        $mapping = array(
            "dtstart" => array("starttime", 3),
            "dtstamp" => array("dtstamp", 3),
            "dtend" => array("endtime", 3),
            "class" => array("sensitivity", 1),
            "description" => array("rtf", 10),
            "description" => array("body", 2),
            "location" => array("location", 0),
            "organizer" => array("organizername", 4),
            "organizer" => array("organizeremail", 4),
            "status" => array("meetingstatus", 1),
            "summary" => array("subject", 0),
            "transp" => array("busystatus", 1),
            "uid" => array("uid", 0),
            "rrule" => array("recurrence", 5),
            "attendee" => array("attendees", 0),
            "categories" => array("categories", 2),
            "valarm" => array("reminder", 7),
            "attendee" => array("attendees", 9)
        );
        
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV:: About to loop through calendar array.');
        $vevent = $this->converttoical($vevent, $message, $mapping, $allday);
        return $vevent;
    }

    function converttovtodo($message) {
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV:: About to create new todo.');
        $vtodo = new vtodo();
    
        $mapping = array(
            "class" => array("sensitivity", 1),
            "description" => array("rtf", 10),
            "description" => array("body", 2),
            "completed" => array("datecompleted", 6),
            "status" => array("complete", 11),
            "due" => array("duedate", 3),
            "dtstart" => array("startdate", 3),
            "priority" => array("importance", 1),
            "summary" => array("subject", 0),
            "uid" => array("uid", 0),
            "rrule" => array("recurrence", 5),
            "categories" => array("categories", 2),
            "valarm" => array("remindertime", 8)
        );
        
        ZLog::Write(LOGLEVEL_DEBUG,'CalDAV:: About to loop through calendar array.');
        $vtodo = $this->converttoical($vtodo, $message, $mapping, false);
        return $vtodo;
    }
    
    function converttoical($icalcomponent, $message, $mapping, $allday = false) {
        foreach($mapping as $k => $e) {
            if (isset($message->$e[0])) {
                $val = $message->$e[0];
                if (!is_object($val) && !is_array($val)) {
                    $val = trim($val);
                }
                if ($val != '') {
                    $k = strtoupper($k);
                    // if found $k in message convert and put in event
                    if ($e[1] == 0) {
                        $icalcomponent->setProperty( $k, $val);
                    }
                    if ($e[1] == 1) {
                        $val = trim($val);
                        switch ($k) {
                            case "CLASS":
                            switch ( $val ) {
                                case "0":
                                $val = "PUBLIC";
                                break;
                                case "1":
                                $val = "PRIVATE";
                                break;
                                case "2":
                                $val = "PRIVATE";
                                break;
                                case "3":
                                $val = "CONFIDENTIAL";
                                break;
                            }
                            break;
    
                            case "STATUS":
                            switch ( $val ) {
                                case "1":
                                $val = "TENTATIVE";
                                break;
                                case "3":
                                $val = "CONFIRMED";
                                break;
                                case "5":
                                $val = "CANCELLED";
                                break;
                            }
                            break;
    
                            case "TRANSP":
                            switch ( $val ) {
                                case "0":
                                    $val = "TRANSPARENT";
                                    break;
                                case "2":
                                    $val = "OPAQUE";
                                    break;
                                default:
                                    $val = "OPAQUE";
                            }
                            break;
                            
                            case "PRIORITY":
                            switch ( $val ) {
                                case "0":
                                    $val = "9";
                                    break;
                                case "1":
                                    $val = "5";
                                    break;
                                case "2":
                                    $val = "1";
                                    break;
                                default:
                                    $val = "";
                            }
                            break;
                        }
                        $icalcomponent->setProperty( $k, $val);
                    }
                    if ($e[1] == 2) {
                        $icalcomponent->setProperty( $k, $val);
                    }
                    if ($e[1] == 3) {
                        // convert to date
                        if ($allday) {
                            $date = date_create_from_format("U", $val);
                            $tz = timezone_open ($this->_currentTimezone);
                            date_timezone_set($date, $tz);
                            $val = date_format($date, 'Ymd');
                            $icalcomponent->setProperty( $k, $val, array('VALUE'=>'DATE'));
                        } else {
                            $val = $this->parseGMTDate($val);
                            $icalcomponent->setProperty( $k, $val);
                        }
                    }
                    if ($e[1] == 4) {
                        // extract organizers name and email
                        if (trim($val) != '') {
                            $icalcomponent->setProperty( $k, $val);
                        }
                    }
                    if ($e[1] == 5) {
                        // recurrence?
                        switch ( trim($val->type) ) {
                            case "0":
                                $args['FREQ'] = "DAILY";
                                break;
                            case "1":
                                $args['FREQ'] = "WEEKLY";
                                break;
                            case "2":
                                $args['FREQ'] = "MONTHLY";
                                break;
                            case "3":
                                $args['FREQ'] = "MONTHLY";
                                break;
                            case "5":
                                $args['FREQ'] = "YEARLY";
                                break;
                            case "6":
                                $args['FREQ'] = "YEARLY";
                                break;
                        }
                        if (isset($val->dayofweek) && $val->dayofweek != "" && is_numeric($val->dayofweek)) {
                            $tmp = "0000000".decbin($val->dayofweek);
                            $args["BYDAY"] = array();
                            $len = strlen($tmp);
                            if (isset($val->weekofmonth) && $val->weekofmonth != "" && is_numeric($val->weekofmonth)) {
                                $wn = $val->weekofmonth;
                                if (substr($tmp,$len-1,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "SU"));
                                if (substr($tmp,$len-2,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "MO"));
                                if (substr($tmp,$len-3,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "TU"));
                                if (substr($tmp,$len-4,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "WE"));
                                if (substr($tmp,$len-5,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "TH"));
                                if (substr($tmp,$len-6,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "FR"));
                                if (substr($tmp,$len-7,1) == "1") array_push($args["BYDAY"], array($wn, "DAY" => "SA"));
                            } else {
                                if (substr($tmp,$len-1,1) == "1") array_push($args["BYDAY"], array("DAY" => "SU"));
                                if (substr($tmp,$len-2,1) == "1") array_push($args["BYDAY"], array("DAY" => "MO"));
                                if (substr($tmp,$len-3,1) == "1") array_push($args["BYDAY"], array("DAY" => "TU"));
                                if (substr($tmp,$len-4,1) == "1") array_push($args["BYDAY"], array("DAY" => "WE"));
                                if (substr($tmp,$len-5,1) == "1") array_push($args["BYDAY"], array("DAY" => "TH"));
                                if (substr($tmp,$len-6,1) == "1") array_push($args["BYDAY"], array("DAY" => "FR"));
                                if (substr($tmp,$len-7,1) == "1") array_push($args["BYDAY"], array("DAY" => "SA"));
                            }
                        }
                        if (isset($val->dayofmonth) && $val->dayofmonth != "" && is_numeric($val->dayofmonth)) {
                            $args['BYMONTHDAY'] = $val->dayofmonth;
                        }
                        if (isset($val->monthofyear) && $val->monthofyear != "" && is_numeric($val->monthofyear)) {
                            $args['BYMONTH'] = $val->monthofyear;
                        }

                        $args['INTERVAL'] = 1;
                        if (isset($val->interval) && $val->interval != "") $args['INTERVAL'] = $val->interval;
                        if (isset($val->until) && $val->until != "") $args['UNTIL'] = $this->parseGMTDate($val->until);
                        if (isset($val->occurrences) && $val->occurrences != "") $args['COUNT'] = $val->occurrences;

                        $icalcomponent->setProperty( $k, $args);
                    }
                    if ($e[1] == 6) {
                        if ($val != "") {
                            $val = $this->parseDate($val);
                            $icalcomponent->setProperty( $k, $val);
                            $icalcomponent->setProperty( "PERCENT_COMPLETE", 100);
                            $icalcomponent->setProperty( "STATUS", "COMPLETED");
                        }
                    }
                    if ($e[1] == 7) {
                        $valarm = new valarm();
                        $valarm->setProperty( "ACTION", "DISPLAY");
                        $valarm->setProperty( "DESCRIPTION", $icalcomponent->getProperty( "SUMMARY" ));
                        $valarm->setProperty( "TRIGGER", "-PT0H".$val."M0S");
                        $icalcomponent->setComponent( $valarm );
                    }
                    if ($e[1] == 8) {
                        $valarm = new valarm();
                        $valarm->setProperty( "ACTION", "DISPLAY");
                        $valarm->setProperty( "DESCRIPTION", $icalcomponent->getProperty( "SUMMARY" ));
                        $valarm->setProperty( "TRIGGER", array("timestamp" => $val));
                        
                        $icalcomponent->setComponent( $valarm );
                    }
                    if ($e[1] == 9 && is_array($val)) {
                        foreach ($val as $att) {
                            $icalcomponent->setProperty( $k, $att->email, array("CN" => $att->name));
                        }
                    }
                    if ($e[1] == 10) {
                        require_once('z_RTF.php');
                        $rtfparser = new rtf();
                        $rtfparser->loadrtf(base64_decode($val));
                        $rtfparser->output("ascii");
                        $rtfparser->parse();
                        $icalcomponent->setProperty( $k, $rtfparser->out);
                    }
                    if ($e[1] == 11) {
                        ZLog::Write(LOGLEVEL_DEBUG,"converttoical: completed is $val");
                        if ($val == "1") {
                            $icalcomponent->setProperty( "PERCENT_COMPLETE", 100);
                            $icalcomponent->setProperty( "STATUS", "COMPLETED");
                        }
                        else {
                            $icalcomponent->setProperty( "PERCENT_COMPLETE", 0);
                            $icalcomponent->setProperty( "STATUS", "NEEDS-ACTION");
                        }
                    }
                }
            }
        }
        return $icalcomponent;
    }
};
?>
