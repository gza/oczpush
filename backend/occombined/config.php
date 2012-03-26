<?php
/***********************************************
* File      :   backend/occombined/config.php
* Project   :   oczpush
* Descr     :   configuration file for the OCcombined backend.
* Adapted from original combined backend
* License   : 	AGPL (like original)
************************************************/

class BackendOCCombinedConfig {

    // *************************
    //  BackendZarafa settings
    // *************************
    public static $BackendZarafa_config = array('MAPI_SERVER' => MAPI_SERVER);

    // *************************
    //  BackendIMAP settings
    // *************************
    public static $BackendIMAP_config = array(
        // Defines the server to which we want to connect
        'IMAP_SERVER' => IMAP_SERVER,
        // connecting to default port (143)
        'IMAP_PORT' => IMAP_PORT,
        // best cross-platform compatibility (see http://php.net/imap_open for options)
        'IMAP_OPTIONS' => IMAP_OPTIONS,
        // overwrite the "from" header if it isn't set when sending emails
        // options: 'username'    - the username will be set (usefull if your login is equal to your emailaddress)
        //        'domain'    - the value of the "domain" field is used
        //        '@mydomain.com' - the username is used and the given string will be appended
        'IMAP_DEFAULTFROM' => IMAP_DEFAULTFROM,
        // copy outgoing mail to this folder. If not set z-push will try the default folders
        'IMAP_SENTFOLDER' => IMAP_SENTFOLDER,
        // forward messages inline (default false - as attachment)
        'IMAP_INLINE_FORWARD' => IMAP_INLINE_FORWARD,
        // use imap_mail() to send emails (default) - if false mail() is used
        'IMAP_USE_IMAPMAIL' => IMAP_USE_IMAPMAIL,
    );

    // *************************
    //  BackendMaildir settings
    // *************************
    public static $BackendMaildir_config = array(
        'MAILDIR_BASE' => MAILDIR_BASE,
        'MAILDIR_SUBDIR' => MAILDIR_SUBDIR,
    );

    // *************************
    //  BackendVCardDir settings
    // *************************
    // public static $BackendVCardDir_config = array('VCARDDIR_DIR' => VCARDDIR_DIR);

    // **********************
    //  BackendOCContacts settings
    // **********************
    public static $BackendOCContacts_config = array('OC_DIR' => OC_DIR);

    // *************************
    //  BackendDummy settings
    // *************************
    public static $BackendDummy_config = array();

    /**
     * Returns the configuration of the combined backend
     *
     * @access public
     * @return array
     *
     */
    public static function GetBackendOCCombinedConfig() {
        //use a function for it because php does not allow
        //assigning variables to the class members (expecting T_STRING)
	$mailtypes = array(
		'SYNC_FOLDER_TYPE_INBOX',
		'SYNC_FOLDER_TYPE_DRAFTS',
		'SYNC_FOLDER_TYPE_WASTEBASKET',
		'SYNC_FOLDER_TYPE_SENTMAIL',
		'SYNC_FOLDER_TYPE_OUTBOX',
		'SYNC_FOLDER_TYPE_OTHER',
		'SYNC_FOLDER_TYPE_USER_MAIL',
		);
	$backends =
        $conf = array(
            //the order in which the backends are loaded.
            //login only succeeds if all backend return true on login
            //sending mail: the mail is sent with first backend that is able to send the mail
            'backends' => array(
                'i' => array(
                    'name' => 'BackendIMAP',
                    'config' => self::$BackendIMAP_config,
                ),
/*                'z' => array(
                    'name' => 'BackendZarafa',
                    'config' => self::$BackendZarafa_config
                ),*/
                'm' => array(
                    'name' => 'BackendMaildir',
                    'config' => self::$BackendMaildir_config,
                ),
                'o' => array(
                    'name' => 'BackendOCContacts',
                    'config' => self::$BackendOCContacts_config,
                ),
                'd' => array(
                    'name' => 'BackendDummy',
                    'config' => self::$BackendDummy_config,
                ),
/*                'v' => array(
                    'name' => 'BackendVCardDir',
                    'config' => self::$BackendVCardDir_config,
                ),*/
            ),
            'delimiter' => '/',
            //force one type of folder to one backend
            //it must match one of the above defined backends
	    'folderbackend' => array(
//                SYNC_FOLDER_TYPE_INBOX => OC_MAIL,
//                SYNC_FOLDER_TYPE_DRAFTS => OC_MAIL,
//                SYNC_FOLDER_TYPE_WASTEBASKET => OC_MAIL,
//                SYNC_FOLDER_TYPE_SENTMAIL => OC_MAIL,
//                SYNC_FOLDER_TYPE_OUTBOX => OC_MAIL,
//                SYNC_FOLDER_TYPE_TASK => 'd',
//                SYNC_FOLDER_TYPE_APPOINTMENT => 'd',
                SYNC_FOLDER_TYPE_CONTACT => 'o',
//                SYNC_FOLDER_TYPE_NOTE => 'd',
//                SYNC_FOLDER_TYPE_JOURNAL => 'd',
//                SYNC_FOLDER_TYPE_OTHER => OC_MAIL,
//                SYNC_FOLDER_TYPE_USER_MAIL => OC_MAIL,
//                SYNC_FOLDER_TYPE_USER_APPOINTMENT => 'd',
                SYNC_FOLDER_TYPE_USER_CONTACT => 'o',
//                SYNC_FOLDER_TYPE_USER_TASK => 'd',
//                SYNC_FOLDER_TYPE_USER_JOURNAL => 'd',
//                SYNC_FOLDER_TYPE_USER_NOTE => 'd',
//                SYNC_FOLDER_TYPE_UNKNOWN => 'd',
            ),
            //creating a new folder in the root folder should create a folder in one backend
            'rootcreatefolderbackend' => 'd',
        );

	if ( defined('OC_MAIL') ) {
		foreach ( $mailtypes as $mt ) {
			$conf['folderbackend'][$mt] = OC_MAIL;
		};
		if (OC_MAIL == 'i') {
			unset($conf['backends']['m']);
		} else {
			unset($conf['backends']['i']);
		}
		$conf['rootcreatefolderbackend'] = OC_MAIL;
	} else {
		unset($conf['backends']['m']);
		unset($conf['backends']['i']);
	}
	return $conf;
    }
}
?>
