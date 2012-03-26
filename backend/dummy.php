<?php
/***********************************************
* File      :   dummy.php
* Project   :   oczpush, https://github.com/gza/oczpush
* Descr     :   This backend is for items not implemented in owncloud
* Licence   :	AGPL
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');

class BackendDummy extends BackendDiff {
	public function Logon($username, $domain, $password) { return true; }
	public function Logoff() { return true; }
	public function SendMail($sm) { return false; }
	public function GetWasteBasket() { return false; }
	public function GetAttachmentData($attname) { return false; }
	public function GetFolderList() { return array(); }
	public function GetFolder($id) { return false; }
	public function StatFolder($id) { return false; }
	public function ChangeFolder($folderid, $oldid, $displayname, $type){ return false; }
	public function DeleteFolder($id, $parentid){ return false; }
	public function GetMessageList($folderid, $cutoffdate) { return false; }
	public function GetMessage($folderid, $id, $contentparameters) { return false; }
	public function StatMessage($folderid, $id) { return false; }
	public function ChangeMessage($folderid, $id, $message) { return false; }
	public function SetReadFlag($folderid, $id, $flags) { return false; }
	public function DeleteMessage($folderid, $id) { return false; }
	public function MoveMessage($folderid, $id, $newfolderid) { return false; }
}

?>
