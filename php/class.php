<?php
/**
 *  PHPDocumentation Online Editor
 *
 *  This class is used to manage PhpDocumentation Online Editor.
 *
 *  @author Yannick Torres <yannick@php.net>
 *  @license LGPL
 */

set_time_limit(0);

require_once dirname(__FILE__) . '/conf.inc.php';
require_once dirname(__FILE__) . '/LockFile.php';
require_once dirname(__FILE__) . '/ToolsError.php';
require_once dirname(__FILE__) . '/ToolsCheckDoc.php';

class phpDoc
{

    public $availableLanguage;

    /**
     * Hold the user's Cvs login.
     */
    public $cvsLogin;

    /**
     * Hold the user's Cvs lang.
     */
    public $cvsLang;

    /**
     * Hold the user's configuration.
     */
    public $userConf;

    /**
     * Hold the DB connection.
     */
    public $db;

    /**
     * Hold the user's Cvs password.
     */
    protected $cvsPasswd;


    function __construct() {

        // Connection MySqli
        try {
            $this->db = new mysqli(DOC_EDITOR_SQL_HOST, DOC_EDITOR_SQL_USER, DOC_EDITOR_SQL_PASS, DOC_EDITOR_SQL_BASE);
            if (mysqli_connect_errno()) {
                throw new Exception('connect databases faild!');
            }
        }
        catch (Exception $e) {
            echo $e->getMessage();
            exit;
        }

        $this->availableLanguage = array('ar','pt_BR','bg','zh','hk','tw','cs','da','nl','fi','fr','de','el','he','hu','it','ja','kr','no','fa','pl','pt','ro','ru', 'se','sk','sl','es','sv','tr');

    }

    /**
     * Checkout the phpdoc-all repository.
     * This method must be call ONLY by the /firstRun.php script.
     */

    function checkoutRepository() {

        $lock = new LockFile('lock_checkout_repository');

        if ($lock->lock()) {
            // We exec the checkout
            $cmd = "cd " . DOC_EDITOR_DATA_PATH ."; cvs -d :pserver:cvsread:phpfi@cvs.php.net:/repository login; cvs -d :pserver:cvsread:phpfi@cvs.php.net:/repository checkout phpdoc-all;";
            exec($cmd);
        }

        $lock->release();
    }


    /**
     * Apply the Revcheck tools recursively on all lang
     * @param $dir The directory from which we start.
     * @param $idDir Directory id from the database.
     * @return Nothing.
     */
    function revDoRevCheck( $dir = '', $idDir ) {
        if ($dh = opendir(DOC_EDITOR_CVS_PATH . 'en/' . $dir)) {

            $entriesDir = array();
            $entriesFiles = array();

            while (($file = readdir($dh)) !== false) {
                if (
                (!is_dir(DOC_EDITOR_CVS_PATH . 'en' . $dir.'/' .$file) && !in_array(substr($file, -3), array('xml','ent')) && substr($file, -13) != 'PHPEditBackup' )
                || strpos($file, 'entities.') === 0
                || $dir == '/chmonly' || $dir == '/internals' || $dir == '/internals2'
                || $file == 'contributors.ent' || $file == 'contributors.xml'
                || ($dir == '/appendices' && ($file == 'reserved.constants.xml' || $file == 'extensions.xml'))
                || $file == 'README'
                || $file == 'DO_NOT_TRANSLATE'
                || $file == 'rsusi.txt'
                || $file == 'missing-ids.xml'
                || $file == 'license.xml'
                || $file == 'versions.xml'
                ) {
                    continue;
                }

                if ($file != '.' && $file != '..' && $file != 'CVS' && $dir != '/functions') {

                    if (is_dir(DOC_EDITOR_CVS_PATH . 'en' . $dir.'/' .$file)) {
                        $entriesDir[] = $file;
                    } elseif (is_file(DOC_EDITOR_CVS_PATH . 'en' . $dir.'/' .$file)) {
                        $entriesFiles[] = $file;
                    }
                }
            }

            // Files first
            if (sizeof($entriesFiles) > 0 ) {

                foreach($entriesFiles as $file) {

                    $path = DOC_EDITOR_CVS_PATH . 'en' . $dir . '/' . $file;

                    $en_size = intval(filesize($path) / 1024);
                    $en_date = filemtime($path);

                    $infoEN      = $this->getInfoFromFile($path);
                    $en_revision = ($infoEN['rev'] == 'NULL') ? 'NULL' : "'".$infoEN['rev']."'";
                    $xmlid       = ($infoEN['xmlid'] == 'NULL') ? 'NULL' : "'".$infoEN['xmlid']."'";

                    $tmp = explode('/', $dir);

                    $ToolsCheckDoc = new ToolsCheckDoc($this->db);
                    $ToolsCheckDocResult = $ToolsCheckDoc->checkDoc($infoEN['content'], $dir);

                    // Sql insert.
                    $s = sprintf('INSERT INTO `files` (`lang`, `xmlid`, `dir`, `path`, `name`, `revision`, `size`, `mdate`, `maintainer`, `status`, `check_oldstyle`,  `check_undoc`, `check_roleerror`, `check_badorder`, `check_noseealso`, `check_noreturnvalues`, `check_noparameters`, `check_noexamples`, `check_noerrors`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                    "'en'",
                    $xmlid,
                    "'$idDir'",
                    "'$dir/'",
                    "'$file'",
                    $en_revision,
                    $en_size,
                    $en_date,
                    "NULL",
                    "NULL",
                    $ToolsCheckDocResult['check_oldstyle'],
                    $ToolsCheckDocResult['check_undoc'],
                    $ToolsCheckDocResult['check_roleerror'],
                    $ToolsCheckDocResult['check_badorder'],
                    $ToolsCheckDocResult['check_noseealso'],
                    $ToolsCheckDocResult['check_noreturnvalues'],
                    $ToolsCheckDocResult['check_noparameters'],
                    $ToolsCheckDocResult['check_noexamples'],
                    $ToolsCheckDocResult['check_noerrors']
                    );

                    $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

                    reset($this->availableLanguage);

                    // Do for all language
                    while (list(, $lang) = each($this->availableLanguage)) {

                        $path = DOC_EDITOR_CVS_PATH . $lang . $dir . '/' . $file;

                        if (is_file($path)) {

                            // Initial revcheck method
                            $size = intval(filesize($path) / 1024);
                            $date = filemtime($path);

                            $size_diff = $en_size - $size;
                            $date_diff = (intval((time() - $en_date) / 86400)) - (intval((time() - $date) / 86400));

                            $infoLANG   = $this->getInfoFromFile($path);
                            $revision   = ($infoLANG['en-rev'] == 'NULL') ? 'NULL' : "'".$infoLANG['en-rev']."'";
                            $maintainer = ($infoLANG['maintainer'] == 'NULL') ? 'NULL' : "'".$infoLANG['maintainer']."'";
                            $status     = ($infoLANG['status'] == 'NULL') ? 'NULL' : "'".$infoLANG['status']."'";
                            $xmlid      = ($infoLANG['xmlid'] == 'NULL') ? 'NULL' : "'".$infoLANG['xmlid']."'";
                            $reviewed   = ($infoLANG['reviewed'] == 'NULL') ? 'NULL' : "'".$infoLANG['reviewed']."'";

                            $s = sprintf('INSERT INTO `files` (`lang`, `xmlid`, `dir`, `path`, `name`, `revision`, `en_revision`, `reviewed`, `size`, `size_diff`, `mdate`, `mdate_diff`, `maintainer`, `status`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                            "'$lang'",
                            $xmlid,
                            "'$idDir'",
                            "'$dir/'",
                            "'$file'",
                            $revision,
                            $en_revision,
                            $reviewed,
                            $size,
                            $size_diff,
                            $date,
                            $date_diff,
                            $maintainer,
                            $status
                            );
                            $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

                            // Check for error in this file ONLY if this file is uptodate
                            if ($revision == $en_revision ) {

                                $error = new ToolsError($this->db);
                                $error->setParams($infoEN['content'], $infoLANG['content'], $lang, $dir.'/', $file, $maintainer);
                                $error->run();
                                $error->saveError();

                            }

                        } else {

                            $s = sprintf('INSERT INTO `files` (`lang`, `dir`, `path`, `name`, `revision`, `size`, `mdate`, `maintainer`, `status`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)',
                            "'$lang'",
                            "'$idDir'",
                            "'$dir/'",
                            "'$file'",
                            "NULL",
                            "NULL",
                            "NULL",
                            "NULL",
                            "NULL"
                            );
                            $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

                        }

                    }



                }
            }

            // Directories..
            if (sizeof($entriesDir) > 0) {

                reset($entriesDir);

                foreach ($entriesDir as $Edir) {

                    $path = DOC_EDITOR_CVS_PATH . 'en/' . $dir . '/' . $Edir;

                    $s = sprintf('INSERT INTO `dirs` (`parentDir`, `name`) VALUES (%s, "%s")', $idDir, $Edir);

                    $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

                    $last_id = $this->db->insert_id;
                    $this->revDoRevCheck($dir . '/' . $Edir, $last_id);

                }
            }
        }
        closedir($dh);
    }

    /**
     * Part of the revcheck tools. Start the Revcheck tools.
     * @return Nothing.
     */
    function revStart() {

        $s = sprintf('INSERT INTO `dirs` (`parentDir`, `name`) VALUES (%s, "/")', 0);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        $firstDir = $this->db->insert_id;
        $this->revDoRevCheck('', $firstDir);

    }

    /**
     * Log into this application.
     * @param $cvsLogin  The login use to identify this user into PHP CVS server.
     * @param $cvsPasswd The password, in plain text, to identify this user into PHP CVS server.
     * @param $lang      The language we want to access.
     * @return An associated array.
     */
    function login($cvsLogin, $cvsPasswd, $lang='en') {

        $return = array(); // Value return

        // Is this user already exist on this server ? database check
        $s = sprintf('SELECT * FROM `users` WHERE `cvs_login`="%s" AND `cvs_passwd`="%s"', $cvsLogin, $cvsPasswd);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        $n = $r->num_rows;

        if ($n == 0 ) {

            // No match
            $this->cvsLogin  = $cvsLogin;
            $this->cvsPasswd = $cvsPasswd;
            $this->cvsLang   = $lang;

            $s = sprintf('SELECT * FROM `users` WHERE `cvs_login`="%s"', $cvsLogin);
            $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

            $n = $r->num_rows;
            if ($n == 0 ) {

                //User unknow from this server for now.
                // Is a valid cvs user ?
                $r = $this->checkCvsAuth();

                if ($r === TRUE ) {

                    // We register this new valid user
                    $userID = $this->registerUser();

                    //Store in session
                    $_SESSION['userID']    = $userID;
                    $_SESSION['cvsLogin']  = $this->cvsLogin;
                    $_SESSION['cvsPasswd'] = $this->cvsPasswd;
                    $_SESSION['lang']      = $this->cvsLang;
                    $_SESSION['userConf']  = array(

                    "conf_needupdate_diff"        => 'using-exec',
                    "conf_needupdate_scrollbars"  => 'true',
                    "conf_needupdate_displaylog"  => 'false',

                    "conf_error_skipnbliteraltag" => 'true',
                    "conf_error_scrollbars"       => 'true',
                    "conf_error_displaylog"       => 'false',

                    "conf_reviewed_scrollbars"    => 'true',
                    "conf_reviewed_displaylog"    => 'false',

                    "conf_allfiles_displaylog"    => 'false',

                    "conf_patch_scrollbars"       => 'true',
                    "conf_patch_displaylog"       => 'false',

                    "conf_theme"                  => 'themes/empty.css'
                    );

                    $return['state'] = true;

                } elseif ($r == 'Bad password') {
                    $return['state'] = false;
                    $return['msg']   = 'Bad cvs password';
                } else {
                    $return['state'] = false;
                    $return['msg']   = 'unknow from cvs';
                }

            } else {
                //User exist, but a bad password is enter
                $return['state'] = false;
                $return['msg']   = 'Bad db password';
            }

            return $return;

        } else {

            $a = $r->fetch_object();
            // user know on this server
            $this->cvsLogin  = $cvsLogin;
            $this->cvsPasswd = $cvsPasswd;
            $this->cvsLang   = $lang;
            $this->userConf  = array(

            "conf_needupdate_diff"        => $a->conf_needupdate_diff,
            "conf_needupdate_scrollbars"  => $a->conf_needupdate_scrollbars,
            "conf_needupdate_displaylog"  => $a->conf_needupdate_displaylog,

            "conf_error_skipnbliteraltag" => $a->conf_error_skipnbliteraltag,
            "conf_error_scrollbars"       => $a->conf_error_scrollbars,
            "conf_error_displaylog"       => $a->conf_error_displaylog,

            "conf_reviewed_scrollbars"    => $a->conf_reviewed_scrollbars,
            "conf_reviewed_displaylog"    => $a->conf_reviewed_displaylog,

            "conf_allfiles_displaylog"    => $a->conf_allfiles_displaylog,

            "conf_patch_scrollbars"       => $a->conf_patch_scrollbars,
            "conf_patch_displaylog"       => $a->conf_patch_displaylog,

            "conf_theme"                  => $a->conf_theme
            );

            // Store in session
            $_SESSION['userID'] = $a->userID;
            $_SESSION['cvsLogin'] = $this->cvsLogin;
            $_SESSION['cvsPasswd'] = $this->cvsPasswd;
            $_SESSION['lang'] = $this->cvsLang;
            $_SESSION['userConf'] = $this->userConf;

            $return['state'] = true;
            $return['msg']   = 'Welcome !';

            return $return;
        }

    }

    /**
     * Update the date/time about the lastConnexion for this user, in DB
     * @return Nothing.
     */
    function updateLastConnect() {

        $s = sprintf('UPDATE `users` SET `last_connect`=now() WHERE `userID`="%s"', $this->userID);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

    }

    /**
     * Check if there is an authentificated session or not
     * @return TRUE if there is an authentificated session, FALSE otherwise.
     */
    function isLogged() {

        if (isset($_SESSION['userID'])) {
            $this->userID     = $_SESSION['userID'];
            $this->cvsLogin   = $_SESSION['cvsLogin'];
            $this->cvsPasswd  = $_SESSION['cvsPasswd'];
            $this->cvsLang    = $_SESSION['lang'];

            $this->userConf   = ( isset($_SESSION['userConf']) ) ? $_SESSION['userConf'] : array(

            "conf_needupdate_diff"        => 'using-exec',
            "conf_needupdate_scrollbars"  => 'true',
            "conf_needupdate_displaylog"  => 'false',

            "conf_error_skipnbliteraltag" => 'true',
            "conf_error_scrollbars"       => 'true',
            "conf_error_displaylog"       => 'false',

            "conf_reviewed_scrollbars"    => 'true',
            "conf_reviewed_displaylog"    => 'false',

            "conf_allfiles_displaylog"    => 'false',

            "conf_patch_scrollbars"       => 'true',
            "conf_patch_displaylog"       => 'false',

            "conf_theme"                  => 'themes/empty.css'
            );

            $this->updateLastConnect();

            return true;
        } else {
            return false;
        }

    }

    /**
     * Register a new valid user on the application.
     * 
     * @todo The CVS password is stored in plain text into the database for later use. We need to find something better
     * @return int The database insert id
     */
    function registerUser()
    {
        $s = sprintf('INSERT INTO `users` (`cvs_login`, `cvs_passwd`) VALUES ("%s", "%s")', $this->cvsLogin, $this->cvsPasswd);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        return $this->db->insert_id;
    }

    /**
     * Update the repository to sync our local copy. Simply exec an "cvs -f -q update -d -P" command.
     * As this exec command take some time, we start by creating a lock file, then run the command, then delete this lock file.
     * As it, we can test if this command has finish, or not.
     */
    function updateRepository() {
        // We place a lock file to test if update is finish
        $lock = new LockFile('lock_update_repository');
        if ($lock->lock()) {
            // We exec the update
            $cmd = "cd ".DOC_EDITOR_CVS_PATH."; cvs -f -q update -d -P . ;";
            exec($cmd);
        }

        // We remove the lock file
        $lock->release();
    }

    /**
     * CleanUp the dataBase before an Update.
     * 
     * @see updateRepository
     */
    function cleanUp()
    {
        // We cleanUp the database before update Cvs and apply again all tools
        foreach (array('dirs', 'files', 'translators', 'errorfiles') as $table) {
            $this->db->query(sprintf('TRUNCATE TABLE %s', $table)) or die('Error: '.$this->db->error.'|'.$s);
        }
    }

    /**
     * Use to encode Cvs Password when we try to identify the user into PHP CVS Server.
     * @param $pass The password to encode.
     * @param $letter The password to encode.
     * @return string The encoded password
     */
    function encodeCvsPass($pass, $letter) {
        static $code = array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13,
        14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
        31, 114, 120, 53, 79, 96, 109, 72, 108, 70, 64, 76, 67, 116, 74, 68,
        87, 111, 52, 75, 119, 49, 34, 82, 81, 95, 65, 112, 86, 118, 110, 122,
        105, 41, 57, 83, 43, 46, 102, 40, 89, 38, 103, 45, 50, 42, 123, 91,
        35, 125, 55, 54, 66, 124, 126, 59, 47, 92, 71, 115, 78, 88, 107, 106,
        56, 36, 121, 117, 104, 101, 100, 69, 73, 99, 63, 94, 93, 39, 37, 61,
        48, 58, 113, 32, 90, 44, 98, 60, 51, 33, 97, 62, 77, 84, 80, 85, 223,
        225, 216, 187, 166, 229, 189, 222, 188, 141, 249, 148, 200, 184, 136,
        248, 190, 199, 170, 181, 204, 138, 232, 218, 183, 255, 234, 220, 247,
        213, 203, 226, 193, 174, 172, 228, 252, 217, 201, 131, 230, 197, 211,
        145, 238, 161, 179, 160, 212, 207, 221, 254, 173, 202, 146, 224, 151,
        140, 196, 205, 130, 135, 133, 143, 246, 192, 159, 244, 239, 185, 168,
        215, 144, 139, 165, 180, 157, 147, 186, 214, 176, 227, 231, 219, 169,
        175, 156, 206, 198, 129, 164, 150, 210, 154, 177, 134, 127, 182, 128,
        158, 208, 162, 132, 167, 209, 149, 241, 153, 251, 237, 236, 171, 195,
        243, 233, 253, 240, 194, 250, 191, 155, 142, 137, 245, 235, 163, 242,
        178, 152);

        for ($i = 0; $i < strlen($pass); $i++) {
            $letter .= chr($code[ord($pass[$i])]);
        }
        return $letter;
    }

    /**
     * Test the CVS credentials against the server
     *
     * @return TRUE if the loggin success, error message otherwise.
     */
    function checkCvsAuth() {

        $fp = fsockopen(DOC_EDITOR_CVS_SERVER_HOST, DOC_EDITOR_CVS_SERVER_PORT);
        fwrite($fp, "BEGIN AUTH REQUEST\n");
        fwrite($fp, DOC_EDITOR_CVS_SERVER_PATH . "\n");
        fwrite($fp, $this->cvsLogin."\n");

        fwrite($fp, $this->encodeCvsPass($this->cvsPasswd,"A")."\n");
        fwrite($fp, "END AUTH REQUEST\n");

        $r = trim(fread($fp, 1024));

        if ($r != 'I LOVE YOU')  {
            if ($r == 'I HATE YOU') {
                return 'Bad password';
            } else {
                return $r;
            }
        } else {
            return true;
        }

    }

    /**
     * Get Cvs log of a specified file.
     * @param $Path The path of the file.
     * @param $File The name of the file.
     * @return An array containing all Cvs log informations.
     */
    function cvsGetLog($Path, $File) {

        $cmd = 'cd '.DOC_EDITOR_CVS_PATH.$Path.'; cvs log '.$File;

        $output = array();
        exec($cmd, $output);

        $output = implode("\n", $output);

        $output = str_replace("=============================================================================", "", $output);

        $part = explode("----------------------------", $output);

        for ($i=1; $i < count($part); $i++ ) {

            $final[$i-1]['id'] = $i;

            $final[$i-1]['raw'] = $part[$i];

            // Get revision
            $out = array();
            preg_match('/revision (.*?)\n/e', $part[$i], $out);
            $final[$i-1]['revision'] = $out[1];

            // Get date
            $out = array();
            preg_match('/date: (.*?);/e', $part[$i], $out);
            $final[$i-1]['date'] = $out[1];

            // Get user
            $out = array();
            preg_match('/author: (.*?);/e', $part[$i], $out);
            $final[$i-1]['author'] = $out[1];

            //Get content
            $content = explode("\n", $part[$i]);

            if (substr($content[3], 0, 9) == 'branches:' ) { $j=4; }
            else { $j=3; }

            $final[$i-1]['content'] = '';

            for ($h=$j; $h < count($content); $h++) {
                $final[$i-1]['content'] .= $content[$h]."\n";
            }
            $final[$i-1]['content'] = str_replace("\n", '<br/>', trim($final[$i-1]['content']));
        }

        return $final;
    }

    /* Methods for the revcheck */

    /**
     * Parse a string to find all attributs.
     * @param $tags_attrs The string to parse.
     * @return An associated array who key are the name of the attribut, and value, the value of the attribut.
     */
    function revParseAttrString($tags_attrs) {
        $tag_attrs_processed = array();

        // Go through the tag attributes
        foreach ($tags_attrs as $attrib_list) {

            // Get attr name and values
            $attribs = array();
            preg_match_all('!(.+)=\\s*(["\'])\\s*(.+)\\2!U', $attrib_list, $attribs);

            // Assign all attributes to one associative array
            $attrib_array = array();
            foreach ($attribs[1] as $num => $attrname) {
                $attrib_array[trim($attrname)] = trim($attribs[3][$num]);
            }
            // Collect in order of tags received
            $tag_attrs_processed[] = $attrib_array;
        }
        // Retrun with collected attributes
        return $tag_attrs_processed;
    }

    function revCheckOldFiles($dir = '') {

        if ($dh = opendir(DOC_EDITOR_CVS_PATH . $this->cvsLang . $dir)) {

            $entriesDir = array();
            $entriesFiles = array();

            while (($file = readdir($dh)) !== false) {
                if (
                (!is_dir(DOC_EDITOR_CVS_PATH . $this->cvsLang . $dir.'/' .$file) && !in_array(substr($file, -3), array('xml','ent')) && substr($file, -13) != 'PHPEditBackup' )
                || strpos($file, 'entities.') === 0
                || $dir == '/chmonly' || $dir == '/internals' || $dir == '/internals2'
                || $file == 'contributors.ent' || $file == 'contributors.xml'
                || ($dir == '/appendices' && ($file == 'reserved.constants.xml' || $file == 'extensions.xml'))
                || $file == 'README'
                || $file == 'DO_NOT_TRANSLATE'
                || $file == 'rsusi.txt'
                || $file == 'missing-ids.xml'
                ) {
                    continue;
                }

                if ($file != '.' && $file != '..' && $file != 'CVS' && $dir != '/functions') {

                    if (is_dir(DOC_EDITOR_CVS_PATH . $this->cvsLang . $dir.'/' .$file)) {
                        $entriesDir[] = $file;
                    } elseif (is_file(DOC_EDITOR_CVS_PATH . $this->cvsLang . $dir.'/' .$file)) {
                        $entriesFiles[] = $file;
                    }
                }
            }

            // Files first
            if (!empty($entriesFiles)) {
                foreach($entriesFiles as $file) {
                    $path_en = DOC_EDITOR_CVS_PATH . 'en/' . $dir . '/' . $file;
                    $path = DOC_EDITOR_CVS_PATH . $this->cvsLang . $dir . '/' . $file;

                    if (!@is_file($path_en)) {
                        $size = intval(filesize($path) / 1024);

                        $s = sprintf('INSERT INTO `old_files` (`lang`, `dir`, `file`, `size`, `userID`) VALUES ("%s", "%s", "%s", "%s", %s)', $lang, $dir, $file, $size, $this->userID);

                        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
                    }
                }
            }

            // Directories..
            if (!empty($entriesDir)) {
                foreach ($entriesDir as $Edir) {
                    $this->revCheckOldFiles($dir . '/' . $Edir);
                }
            }
        }
        closedir($dh);
    }

    /**
     * Part of the revcheck tools. Parse the translation's file witch hold all informations about all translators and put it into database.
     * @return Nothing.
     */
    function revParseTranslation() {

        reset($this->availableLanguage);
        while (list(, $lang) = each($this->availableLanguage)) {

            // Path to find translation.xml file, set default values,
            // in case we can't find the translation file
            $translation_xml = DOC_EDITOR_CVS_PATH . $lang . "/translation.xml";

            if (file_exists($translation_xml)) {
                // Else go on, and load in the file, replacing all
                // space type chars with one space
                $txml = preg_replace('/\\s+/', ' ', join('', file($translation_xml)));

            }

            if (isset($txml)) {
                // Find all persons matching the pattern
                $matches = array();
                if (preg_match_all('!<person (.+)/\\s?>!U', $txml, $matches)) {
                    $default = array('cvs' => 'n/a', 'nick' => 'n/a', 'editor' => 'n/a', 'email' => 'n/a', 'name' => 'n/a');
                    $persons = $this->revParseAttrString($matches[1]);

                    $charset = $this->getFileEncoding($txml, 'content');

                    foreach ($persons as $person) {

                        if ($charset == 'utf-8' ) {
                            $name = utf8_decode($person['name']);
                        } else {
                            $name = $person['name'];
                        }

                        $person = array_merge($default, $person);

                        $s = sprintf('INSERT INTO `translators` (`lang`, `nick`, `name`, `mail`, `cvs`, `editor`) VALUES ("%s", "%s", "%s", "%s", "%s", "%s")',
                        $lang,
                        $this->db->real_escape_string($person['nick']),
                        $this->db->real_escape_string($name),
                        $this->db->real_escape_string($person['email']),
                        $this->db->real_escape_string($person['cvs']),
                        $this->db->real_escape_string($person['editor'])
                        );

                        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
                    }
                }
            }
        }
    }

    /**
     * Test if the file is a modified file.
     *
     * @param $lang The lang of the tested file.
     * @param $path The path of the tested file.
     * @param $name The name of the tested file.
     *
     * @return Boolean TRUE if the file is a modified file, FALSE otherwise.
     */
    function isModifiedFile($lang, $path, $name) {

        $s = sprintf('SELECT `id`, `lang`, `path`, `name` FROM `pendingCommit` WHERE 
        `lang`="%s" AND `path`="%s" AND `name`="%s"', $lang, $path, $name);

        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        return ( $r->num_rows == 0 ) ? FALSE : TRUE;

    }

    /**
     * Get all modified files.
     * @return An associated array containing all informations about modified files.
     */
    function getModifiedFiles() {

        // Get Modified Files
        $s = sprintf('SELECT `id`, `lang`, `path`, `name`, CONCAT("1.", `revision`) AS `revision`,
        CONCAT("1.", `en_revision`) AS `en_revision`, `maintainer`, `reviewed` FROM `pendingCommit` WHERE 
        `lang`="%s" OR `lang`="en"', $this->cvsLang);

        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        $node = array();

        while ($a = $r->fetch_assoc()) {
            $node[$a['lang'].$a['path'].$a['name']] = $a;
        }

        return $node;

    }

    /**
     * Get all files witch need to be updated.
     * @return An associated array containing all informations about files witch need to be updated.
     */
    function getFilesNeedUpdate() {

        // Get Files Need Commit
        $ModifiedFiles = $this->getModifiedFiles();

        $s = sprintf('SELECT * FROM `files` WHERE `lang` = "%s" AND `revision` != `en_revision`', $this->cvsLang);
        $r = $this->db->query($s);

        $nb = $r->num_rows;

        $node = array();

        while ($a = $r->fetch_object()) {

            if (isset($ModifiedFiles[$this->cvsLang.$a->path.$a->name]) || isset($ModifiedFiles['en'.$a->path.$a->name])) {

                if (isset($ModifiedFiles['en'.$a->path.$a->name])) {
                    $new_en_revision = $ModifiedFiles['en'.$a->path.$a->name]['revision'];
                    $new_revision    = '1.'.$a->revision;
                    $new_maintainer  = $a->maintainer;
                }

                if (isset($ModifiedFiles[$this->cvsLang.$a->path.$a->name])) {
                    $new_en_revision = '1.'.$a->en_revision;
                    $new_revision    = $ModifiedFiles[$this->cvsLang.$a->path.$a->name]['en_revision'];
                    $new_maintainer  = $ModifiedFiles[$this->cvsLang.$a->path.$a->name]['maintainer'];
                }

                $node[] = array(
                "id"          => $a->id,
                "path"        => $a->path,
                "name"        => $a->name,
                "revision"    => $new_revision,
                "en_revision" => $new_en_revision,
                "maintainer"  => $new_maintainer,
                "needcommit"  => true,
                "isCritical"  => false
                );
            } else {
                $node[] = array(
                "id"          => $a->id,
                "path"        => $a->path,
                "name"        => $a->name,
                "revision"    => '1.'.$a->revision,
                "en_revision" => '1.'.$a->en_revision,
                "maintainer"  => $a->maintainer,
                "needcommit"  => false,
                "isCritical"  => ( ($a->en_revision - $a->revision >= 10) || $a->size_diff >= 3 || $a->mdate_diff <= -30 ) ? true : false
                );
            }
        }
        return array('nb'=>$nb, 'node'=>$node);
    }

    /**
     * Get all files witch need to be reviewed.
     * @return An associated array containing all informations about files witch need to be reviewed.
     */
    function getFilesNeedReviewed() {

        // Get Files Need Commit
        $ModifiedFiles = $this->getModifiedFiles();

        $s = sprintf('SELECT * FROM `files` WHERE `lang` = "%s" AND `revision` = `en_revision` AND reviewed != \'yes\' LIMIT 100', $this->cvsLang);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $nb = $r->num_rows;

        $node = array();

        while ($a = $r->fetch_object()) {

            $temp = array(
            "id"         => $a->id,
            "path"       => $a->path,
            "name"       => $a->name,
            );

            if (isset($ModifiedFiles[$this->cvsLang.$a->path.$a->name]) || isset($ModifiedFiles['en'.$a->path.$a->name])) {

                if (isset($ModifiedFiles['en'.$a->path.$a->name])) {
                    $new_reviewed    = $a->reviewed;
                    $new_maintainer  = $a->maintainer;
                }

                if (isset($ModifiedFiles[$this->cvsLang.$a->path.$a->name])) {
                    $new_reviewed    = $ModifiedFiles[$this->cvsLang.$a->path.$a->name]['reviewed'];
                    $new_maintainer  = $ModifiedFiles[$this->cvsLang.$a->path.$a->name]['maintainer'];
                }

                $temp['reviewed']   = $new_reviewed;
                $temp['maintainer'] = $new_maintainer;
                $temp['needcommit'] = true;
            } else {
                $temp['reviewed']   = $a->reviewed;
                $temp['maintainer'] = $a->maintainer;
                $temp['needcommit'] = false;
            }
            $node[] = $temp;
        }
        return array('nb'=>$nb, 'node'=>$node);
    }

    /**
     * Get all pending patch.
     * @return An associated array containing all informations about pending patch.
     */
    function getFilesPendingPatch() {

        $s = sprintf('SELECT `id`, CONCAT(`lang`, `path`) AS path, `name`, `posted_by` AS \'by\', `uniqID`, `date` FROM `pendingPatch` WHERE `lang`="%s" OR `lang`=\'en\'', $this->cvsLang);

        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $nb = $r->num_rows;

        $node = array();

        while ($row = $r->fetch_assoc()) {
            $node[] = $row;
        }

        return array('nb' => $nb, 'node' => $node);
    }

    /**
     * Get all files pending for commit.
     * @return An associated array containing all informations about files pending for commit.
     */
    function getFilesPendingCommit() {

        $s = sprintf('SELECT * FROM `pendingCommit` WHERE `lang`="%s" OR `lang`=\'en\'', $this->cvsLang);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $nb = $r->num_rows;

        $node = array();

        while ($a = $r->fetch_object()) {

            $node[] = array(
            "id"          => $a->id,
            "path"        => $a->lang.$a->path,
            "name"        => $a->name,
            "by"          => $a->modified_by,
            "date"        => $a->date
            );

        }

        return array('nb'=>$nb, 'node'=>$node);
    }

    /**
     * Get all translators informations.
     * @return An associated array containing all informations about translators.
     */
    function get_translators()
    {
        $sql = sprintf('SELECT `id`, `nick`, `name`, `mail`, `cvs` FROM `translators` WHERE `lang`="%s"', $this->cvsLang);
        $persons = array();
        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);

        while ($r = $result->fetch_array()) {
            $persons[$r['nick']] = array('id'=>$r['id'], 'name' => utf8_encode($r['name']), 'mail' => $r['mail'], 'cvs' => $r['cvs']);
        }

        return $persons;
    }

    /**
     * Get all statistiques about translators.
     * @return An indexed array containing all statistiques about translators (nb uptodate files, nb old files, etc...)
     */
    function getTranslatorsInfo() {

        $translators = $this->get_translators();
        $uptodate    = $this->translator_get_uptodate();
        $old         = $this->translator_get_old();
        $critical    = $this->translator_get_critical();

        $i=0; $persons=array();
        foreach($translators as $nick => $data) {
            $persons[$i]              = $data;
            $persons[$i]['nick']      = $nick;
            $persons[$i]['uptodate']  = isset($uptodate[$nick]) ? $uptodate[$nick] : '0';
            $persons[$i]['old']       = isset($old[$nick]) ? $old[$nick] : '0';
            $persons[$i]['critical']  = isset($critical[$nick]) ? $critical[$nick] : '0';
            $persons[$i]['sum']       = $persons[$i]['uptodate'] + $persons[$i]['old'] + $persons[$i]['critical'];
            $i++;
        }
        return $persons;
    }

    /**
     * Get summary of all statistiques.
     * @return An indexed array containing all statistiques for the summary
     */
    function getSummaryInfo() {

        $nbFiles     = $this->getNbFiles();

        $uptodate    = $this->getNbFilesTranslated();
        $old         = $this->getStatsOld();
        $critical    = $this->getStatsCritical();

        $missFiles = $this->getStatsNoTrans();

        $withoutRevTag = $this->getStatsNoTag();

        $nbFiles[1] = $uptodate[1]+$old[1]+$critical[1]+$withoutRevTag[1]+$missFiles[1];

        $summary = array();

        $summary[0]['id']            = 1;
        $summary[0]['libel']         = 'Up to date files';
        $summary[0]['nbFiles']       = $uptodate[0];
        $summary[0]['percentFiles']  = round(($uptodate[0]*100)/$nbFiles[0], 2);
        $summary[0]['sizeFiles']     = ($uptodate[1] == '' ) ? 0 : $uptodate[1];
        $summary[0]['percentSize']   = round(($uptodate[1]*100)/$nbFiles[1], 2);

        $summary[1]['id']            = 2;
        $summary[1]['libel']         = 'Old files';
        $summary[1]['nbFiles']       = $old[0];
        $summary[1]['percentFiles']  = round(($old[0]*100)/$nbFiles[0], 2);
        $summary[1]['sizeFiles']     = ($old[1] == '' ) ? 0 : $old[1];
        $summary[1]['percentSize']   = round(($old[1]*100)/$nbFiles[1], 2);

        $summary[2]['id']            = 3;
        $summary[2]['libel']         = 'Critical files';
        $summary[2]['nbFiles']       = $critical[0];
        $summary[2]['percentFiles']  = round(($critical[0]*100)/$nbFiles[0], 2);
        $summary[2]['sizeFiles']     = ($critical[1] == '' ) ? 0 : $critical[1];
        $summary[2]['percentSize']   = round(($critical[1]*100)/$nbFiles[1], 2);


        $summary[3]['id']            = 4;
        $summary[3]['libel']         = 'Files without revision tag';
        $summary[3]['nbFiles']       = $withoutRevTag[0];
        $summary[3]['percentFiles']  = round(($withoutRevTag[0]*100)/$nbFiles[0], 2);
        $summary[3]['sizeFiles']     = ($withoutRevTag[1] == '' ) ? 0 : $withoutRevTag[1];
        $summary[3]['percentSize']   = round(($withoutRevTag[1]*100)/$nbFiles[1], 2);

        $summary[4]['id']            = 5;
        $summary[4]['libel']         = 'Files available for translation';
        $summary[4]['nbFiles']       = $missFiles[0];
        $summary[4]['percentFiles']  = round(($missFiles[0]*100)/$nbFiles[0], 2);
        $summary[4]['sizeFiles']     = ($missFiles[1] == '' ) ? 0 : $missFiles[1];
        $summary[4]['percentSize']   = round(($missFiles[1]*100)/$nbFiles[1], 2);

        $summary[5]['id']            = 6;
        $summary[5]['libel']         = 'Total';
        $summary[5]['nbFiles']       = $nbFiles[0];
        $summary[5]['percentFiles']  = '100%';
        $summary[5]['sizeFiles']     = $nbFiles[1];
        $summary[5]['percentSize']   = '100%';


        return $summary;
    }

    /**
     * Get number of uptodate files per translators.
     * @return An associated array (key=>translator's nick, value=>nb files).
     */
    function translator_get_uptodate()
    {
        $sql = sprintf('SELECT
                COUNT(`name`) AS total,
                `maintainer`
            FROM
                `files`
            WHERE
                `lang`="%s"
            AND 
                `revision` = `en_revision`
            GROUP BY
                `maintainer`
            ORDER BY
                `maintainer`', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $tmp = array();
        while ($r = $result->fetch_array()) {
            $tmp[$r['maintainer']] = $r['total'];
        }
        return $tmp;
    }

    /**
     * Get number of old files per translators.
     * @return An associated array (key=>translator's nick, value=>nb files).
     */
    function translator_get_old()
    {
        $sql = sprintf('SELECT
                COUNT(`name`) AS total,
                `maintainer`
            FROM
                `files`
            WHERE
                `lang`="%s"
            AND
                `en_revision` != `revision`
            AND
                `en_revision` - `revision` < 10
            AND
                `size_diff` < 3 
            AND 
                `mdate_diff` > -30
            AND
                `size` is not NULL
            GROUP BY
                `maintainer`
            ORDER BY
                `maintainer`', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $tmp = array();
        while ($r = $result->fetch_array()) {
            $tmp[$r['maintainer']] = $r['total'];
        }
        return $tmp;
    }

    /**
     * Get number of critical files per translators.
     * @return An associated array (key=>translator's nick, value=>nb files).
     */
    function translator_get_critical()
    {
        $sql = sprintf('SELECT
                COUNT(`name`) AS total,
                `maintainer`
            FROM
                `files`
            WHERE
                `lang`="%s"
            AND
                ( `en_revision` - `revision` >= 10  OR
                ( `en_revision` != `revision`  AND
                    ( `size_diff` >= 3 OR `mdate_diff` <= -30 )
                ))
            AND
                `size` is not NULL
            GROUP BY
                `maintainer`
            ORDER BY
                `maintainer`', $this->cvsLang);
        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $tmp = array();
        while ($r = $result->fetch_array()) {
            $tmp[$r['maintainer']] = $r['total'];
        }
        return $tmp;
    }

    /**
     * Get number of files.
     * @return An indexed array.
     */
    function getNbFiles() {
        $sql = sprintf('SELECT
                    COUNT(*) AS total,
                    SUM(`size`) AS total_size
                FROM
                    `files`
                WHERE
                    `lang` = "%s"
            ', $this->cvsLang);

        $res = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $r = $res->fetch_array();
        $result = array($r['total'], $r['total_size']);
        return $result;
    }

    /**
     * Get number of translated files.
     * @return Number of translated files.
     */
    function getNbFilesTranslated() {

        $sql = 'SELECT
                COUNT(name) AS total,
                SUM(size)   AS total_size
            FROM
                files
            WHERE
                lang="' . $this->cvsLang . '"
            AND
                revision = en_revision
           ';

        $res = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $r = $res->fetch_array();
        $result = array($r['total'], $r['total_size']);
        return $result;
    }

    /**
     * Get statistic about critical files witch need to be updated.
     * @return An associated array (total=>nb files, total_size=>size of this files).
     */
    function getStatsCritical() {

        $s = sprintf('SELECT
                COUNT(`name`) AS total,
                SUM(`size`) AS total_size
            FROM
                `files`
            WHERE
                `lang`="%s"
            AND
                ( `en_revision` - `revision` >= 10  OR
                ( `en_revision` != `revision`  AND
                    ( `size_diff` >= 3 OR `mdate_diff` <= -30 )
                ))
            AND
                `size` is not NULL
           ', $this->cvsLang);

        $result = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        $r = $result->fetch_array();
        $result = array($r['total'], $r['total_size']);
        return $result;
    }

    /**
     * Get statistic about old files witch need to be deleted from LANG tree.
     * @return An associated array (total=>nb files, total_size=>size of this files).
     */
    function getStatsOld()
    {
        $sql = sprintf('SELECT
                COUNT(`name`) AS total,
                SUM(`size`)   AS total_size
            FROM
                `files`
            WHERE
                `lang`="%s"
            AND
                `en_revision` != `revision`
            AND
                `en_revision` - `revision` < 10
            AND
                `size_diff` < 3 
            AND 
                `mdate_diff` > -30
            AND
                `size` is not NULL
           ', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);

        $r = $result->fetch_array();
        $result = array($r['total'], $r['total_size']);
        return $result;
    }

    /**
     * Get statistic about files witch need to be translated.
     * @return An associated array (total=>nb files, size=>size of this files).
     */
    function getStatsNoTrans()
    {
        $sql = sprintf('SELECT
                COUNT(a.name) as total, 
                sum(b.size) as size 
            FROM
                `files` a
            LEFT JOIN
                `files` b
            ON 
                a.dir = b.dir 
            AND
                a.name = b.name
            WHERE
                a.lang="%s" 
            AND
                b.lang="en"
            AND
                a.revision is NULL
            AND
                a.size is NULL', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        if ($result->num_rows) {
            $r = $result->fetch_array();
            return array($r['total'], $r['size']);
        } else {
            return array(0,0);
        }
    }

    /**
     * Get statistic about missed files witch need to be added to LANG tree.
     * @return An array of missed files (size=>size of the file, file=>name of the file).
     */
    function getMissFiles()
    {
        $sql = sprintf('SELECT
                b.size as size, 
                a.name as file 
            FROM
                `files` a
            LEFT JOIN
                `files` b 
            ON 
                a.dir = b.dir 
            AND
                a.name = b.name 
            WHERE 
                a.lang="%s" 
            AND
                b.lang="en" 
            AND
                a.revision is NULL 
            AND
                a.size is NULL', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);
        $num = $result->num_rows;
        if ($num == 0) {
            // only 'null' will produce a 0 with sizeof()
            return null;
        } else {
            $tmp = array();
            while ($r = $result->fetch_array()) {
                $tmp[] = array('size' => $r['size'], 'file' => $r['file']);
            }
            return $tmp;
        }
    }

    /**
     * Get statistic about files witch haven't revcheck's tags.
     * @return An associated array (total=>nb files, size=>size of this files).
     */
    function getStatsNoTag()
    {
        $sql = sprintf('SELECT
                COUNT(a.name) as total,
                sum(b.size) as size
            FROM
                `files` a
            LEFT JOIN
                `files` b 
            ON 
                a.dir = b.dir 
            AND
                a.name = b.name
            WHERE
                a.lang="%s" 
            AND
                b.lang="en"
            AND
                a.revision is NULL
            AND
                a.size is not NULL', $this->cvsLang);

        $result = $this->db->query($sql) or die('Error: '.$this->db->error.'|'.$s);

        $r = $result->fetch_array();
        $result = array($r['total'], $r['size']);
        return $result;
    }

    /**
     * Get encoding of a file, regarding his XML's header.
     * @param $file The file to get encoding from.
     * @param $mode The mode. Must be 'file' if $file is a path to the file, or 'content' if $file is the content of the file.
     * @return The charset as a string.
     */
    function getFileEncoding($file, $mode) {

        if ($mode == 'file' ) {
            $txml = file_get_contents($file);
        } else {
            $txml = $file;
        }

        $txml = preg_replace('/\\s+/', ' ', $txml);

        $match = array();
        preg_match('!<\?xml(.+)\?>!U', $txml, $match);
        $xmlinfo = $this->revParseAttrString($match);

        $charset = (isset($xmlinfo[1]['encoding'])) ? strtolower($xmlinfo[1]['encoding']) : 'iso-8859-1';

        return $charset;
    }

    /**
     * Get the content of a file.
     * @param $FilePath The path for the file we want to retreive.
     * @param $lang The lang of the file we want to retreive. Either 'en' or current LANG.
     * @return An associated array (content=> content of the file, charset=>the charset of the file).
     */
    function getFileContent($FilePath, $FileName) {

        // Security
        $FilePath = str_replace('..', '', $FilePath);
        $FilePath = str_replace('//', '/', $FilePath);

        $file = $FilePath.$FileName;

        // Is this file modified ?
        $ModifiedFiles = $this->getModifiedFiles();

        if (isset($ModifiedFiles[$file])) { $extension = '.new'; }
        else { $extension = ''; }

        $file = DOC_EDITOR_CVS_PATH.$file.$extension;

        $charset = $this->getFileEncoding($file, 'file');

        $return['charset'] = $charset;
        $return['content'] = file_get_contents($file);

        return $return;
    }

    /**
     * Save a file after modification.
     * @param $FilePath The path for the file we want to save.
     * @param $content The new content.
     * @param $lang The lang of the file we want to save. Either 'en' or current LANG.
     * @param $type Can be 'file' or 'patch'.
     * @param $uniqID If type=patch, this is an uniqID to identify this patch.
     * @return The path to the new file ($FilePath with .new extension) successfully created.
     */
    function saveFile($FilePath, $content, $lang, $type, $uniqID='') {

        if ($type == 'file' ) { $ext = '.new'; }
        else { $ext = '.'.$uniqID.'.patch'; }

        // Security
        $FilePath = str_replace('..', '', $FilePath);

        // Open in w+ mode
        $h = fopen(DOC_EDITOR_CVS_PATH.$lang.$FilePath.$ext, 'w+');
        fwrite($h, $content);
        fclose($h);

        return DOC_EDITOR_CVS_PATH.$lang.$FilePath.$ext;
    }

    /**
     * Register a file as need to be commited, into the database.
     * @param $lang        The path for the file witch need to be commited.
     * @param $FilePath    The path for the file witch need to be commited.
     * @param $FileName    The name of the file witch need to be commited.
     * @param $revision    The revision of this file.
     * @param $en_revision The EN revision of this file.
     * @param $reviewed    The stats of the reviewed tag.
     * @param $maintainer  The maintainer.
     * @return Nothing.
     */
    function registerAsPendingCommit($lang, $FilePath, $FileName, $revision, $en_revision, $reviewed, $maintainer) {

        $s = sprintf('SELECT id FROM `pendingCommit` WHERE `lang`="%s" AND `path`="%s" AND `name`="%s"', $lang, $FilePath, $FileName);
        $r = $this->db->query($s);

        $nb = $r->num_rows;

        // We insert or update the pendingCommit table
        if ($nb == 0 ) {

            $s = sprintf('INSERT into `pendingCommit` (`lang`, `path`, `name`, `revision`, `en_revision`, `reviewed`, `maintainer`, `modified_by`, `date`) VALUES ("%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", now())', $lang, $FilePath, $FileName, $revision, $en_revision, $reviewed, $maintainer, $this->cvsLogin);
            $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
            $fileID = $this->db->insert_id;
        } else {
            $a = $r->fetch_object();

            $s = sprintf('UPDATE `pendingCommit` SET `revision`="%s", `en_revision`="%s", `reviewed`="%s", `maintainer`="%s" WHERE id="%s"', $revision, $en_revision, $reviewed, $maintainer, $a->id);
            $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
            $fileID = $a->id;
        }

    }

    /**
     * Register a new patch, into the database.
     * @param $lang     The lang.
     * @param $FilePath The path for the file.
     * @param $FileName The name of the file.
     * @return Nothing.
     */
    function registerAsPendingPatch($lang, $FilePath, $FileName, $emailAlert) {

        $uniqID = md5(uniqid(rand(), true));

        $s = sprintf('INSERT into `pendingPatch` (`lang`, `path`, `name`, `posted_by`, `date`, `email`, `uniqID`) VALUES ("%s", "%s", "%s", "%s", now(), "%s", "%s")', $lang, $FilePath, $FileName, $this->cvsLogin, $emailAlert, $uniqID);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        return $uniqID;

    }

    /**
     * Get the information from the content of a file.
     * @param $content The content of the file.
     * @return The revision as a 2 digits number, or 0 if revision wasn't found.
     */
    function getInfoFromContent($content) {

        $info = array('rev'=>0, 'en-rev'=>0, 'maintainer'=>'NULL', 'reviewed'=>'NULL', 'status'=>'NULL', 'xmlid'=>'NULL', 'content'=>$content);

        // Cvs tag
        $match = array();
        preg_match('/<!-- .Revision: \d+\.(\d+) . -->/', $content, $match);
        if (!empty($match)) {
            $info['rev'] = $match[1];
        }

        //Rev tag
        $match = array();
        preg_match('/<!--\s*EN-Revision:\s*\d+\.(\d+)\s*Maintainer:\s*(\\S*)\s*Status:\s*(.+)\s*-->/U', $content, $match);
        if (!empty($match)) {
            $info['en-rev'] = $match[1];
            $info['maintainer'] = $match[2];
            $info['status'] = $match[3];
        }

        // Reviewed tag
        $match = array();
        if (preg_match('/<!--\s*Reviewed:\s*(.*?)*-->/Ui', $content, $match)) {
            $info['reviewed'] = trim($match[1]);
        }

        // All xmlid
        $match = array();
        if (preg_match_all('/xml:id="(.*?)"/', $content, $match)) {
            $info['xmlid'] = implode('|',$match[1]);
        }

        return $info;
    }

    function getInfoFromFile($file) {
        $content = file_get_contents($file);
        return $this->getInfoFromContent($content);
    }

    /**
     * Get the diff of a file with his modified version.
     * @param $path The path to the file.
     * @param $file The name of the file.
     * @return The diff a the file with his modified version, as HTML, reday to be display.
     */
    function getDiffFromFiles($path, $file, $type='', $uniqID='') {
        include "./class.fileDiff.php";

        $charset = $this->getFileEncoding(DOC_EDITOR_CVS_PATH.$path.'/'.$file, 'file');

        $FilePath1 = DOC_EDITOR_CVS_PATH.$path.'/'.$file;
        $FilePath2 = ( $type == '' ) ? DOC_EDITOR_CVS_PATH.$path.'/'.$file.'.new' : DOC_EDITOR_CVS_PATH.$path.'/'.$file.'.'.$uniqID.'.patch';

        $diff = new diff;
        $info['content'] = $diff->inline($FilePath1, $FilePath2, 2, $charset);
        $info['charset'] = $charset;
        return $info;

    }

    /**
     * Get a raw diff between a file and a modified file.
     * @param $path The path to the file.
     * @param $file The name of the file.
     * @return The diff of the file with his modified version.
     */
    function getRawDiff($path, $file) {

        $cmd = 'cd '.DOC_EDITOR_CVS_PATH.$path.'; diff -uN '.$file.' '.$file.'.new';

        $output = array();
        exec($cmd, $output);
        return implode("\r\n",$output);

    }

    /** NEW
     * Get the diff of a file with his modified version.
     * @param $path The path to the file.
     * @param $file The name of the file.
     * @param $rev1 Frist revison.
     * @param $rev2 Second revision.
     * @return The diff a the file with his modified version, as HTML, reday to be display.
     */
    function getDiffFromExec($path, $file, $rev1, $rev2) {

        $cmd = 'cd '.DOC_EDITOR_CVS_PATH.$path.'; cvs diff -kk -u -r '.$rev2.' -r '.$rev1.' '.$file;

        $output = array();
        exec($cmd, $output);

        $output = htmlentities(join("\n", $output));

        $match = array();

        preg_match_all('/@@(.*?)@@(.[^@@]*)/s', $output, $match);

        $diff = array();

        for ($i = 0; $i < count($match[1]); $i++ ) {

            $diff[$i]['line'] = $match[1][$i];
            $diff[$i]['content'] =  $match[2][$i];

        }

        $return = '<table class="code">';

        for ($i = 0; $i < count($diff); $i++ ) {

            // Line
            $return .= '
           <tr>
            <td class="line">'.$diff[$i]['line'].'</td>
           </tr>
          ';

            // Content
            $tmp = explode("\n", trim($diff[$i]['content']));

            for ($j=0; $j < count($tmp); $j++ ) {
                $tmp[$j] = str_replace(" ", "&nbsp;", $tmp[$j]);

                switch (substr($tmp[$j], 0, 1)) {
                    case '+':
                        $class = 'ins';
                        break;
                    case '-':
                        $class = 'del';
                        break;

                    default:
                        $class = '';
                        break;
                }

                $return .= '
             <tr>
              <td class="'.$class.'">'.$tmp[$j].'</td>
             </tr>
            ';
            } // Fin for J

            // Separator
            $return .= '
           <tr>
            <td class="truncated">&nbsp;</td>
           </tr>
          ';

        } // Fin for I


        $return .= '<table>';

        return $return;

    }

    /**
     * Get all commit message.
        *
     * Each time we commit, we store in DB the commit message to be use later. This method get all this message from DB.
     * @return An indexed array of commit message.
     */
    function getCommitLogMessage()
    {
        $result = array();

        $s = sprintf('SELECT `id`, `text` FROM `commitMessage` WHERE userID="%s"', $this->userID);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        while ($a = $r->fetch_assoc()) {
            $result[] = $a;
        }

        return $result;
    }

    /**
     * Save Output message into a log file.
     * @param $file The name of the file.
     * @param $output The output message.
     * @return Nothing.
     */
    function saveOutputLogFile($file, $output)
    {
        $fp = fopen(DOC_EDITOR_CVS_PATH . '../.' . $file, 'w');
        fwrite($fp, implode("<br>",$output));
        fclose($fp);
    }

    /**
     * Get the content of a log file.
     * @param $file The name of the file.
     * @return $content The content.
     */
    function getOutputLogFile($file) {
        return file_get_contents(DOC_EDITOR_CVS_PATH . '../.' . $file);
    }

    /**
     * Check the build of your file (using configure.php script).
     * PHP binary should be in /usr/bin
     * @return The output log.
     */
    function checkBuild($enable_xml_details='false') {
        $cmd = 'cd '.DOC_EDITOR_CVS_PATH.';/usr/bin/php configure.php --with-lang='.$this->cvsLang.' --disable-segfault-error';

        if ($enable_xml_details == 'true' ) {
            $cmd .= ' --enable-xml-details';
        }

        $cmd .= ';';

        $output = array();
        exec($cmd, $output);

        //Format the outPut
        $output = str_replace("Warning", '<span style="color: #FF0000; font-weight: bold;">Warning</span>', $output);

        return $output;
    }

    /**
     * Delete local change of a file.
     * @param $path The path of the file.
     * @param $file The name of the file.
     * @return An array witch contain informations about this file.
     */
    function clearLocalChange($path, $file) {

        // Extract the lang
        $t = explode('/',$path);
        $lang = $t[0];
        array_shift($t);

        // Add first /
        $path = '/'.implode('/', $t);

        // We need delete row from pendingCommit table
        $s = sprintf('SELECT `id` FROM `pendingCommit` WHERE `lang`="%s" AND `path`="%s" AND name="%s"', $lang, $path, $file);
        $r = $this->db->query($s);
        $a = $r->fetch_object();

        // We need delete row from pendingCommit table
        $s = sprintf('DELETE FROM `pendingCommit` WHERE `id`="%s"', $a->id);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        // We need delete file on filesystem
        $doc = DOC_EDITOR_CVS_PATH.$lang.$path.$file.".new";

        @unlink($doc);

        // We need check for error in this file

        $en_content     = file_get_contents(DOC_EDITOR_CVS_PATH.'en'.$path.$file);
        $lang_content   = file_get_contents(DOC_EDITOR_CVS_PATH.$lang.$path.$file);

        $info = $this->getInfoFromContent($lang_content);

        $anode[0] = array( 0 => $lang.$path, 1 => $file, 2 => $en_content, 3 => $lang_content, 4 => $info['maintainer']);

        $errorTools = new ToolsError($this->db);
        $error = $errorTools->updateFilesError($anode, 'nocommit');

        // We need reload original lang_revision
        $s = sprintf('SELECT `revision`, `maintainer`, `reviewed` FROM `files` WHERE `lang`="%s" AND `path`="%s" AND `name`="%s"', $lang, $path, $file);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $a = $r->fetch_object();

        $info = array();
        $info['rev']        = $a->revision;
        $info['maintainer'] = $a->maintainer;
        $info['reviewed']   = $a->reviewed;

        if (isset($error['first'])) {
            $info['errorState'] = true;
            $info['errorFirst'] = $error['first'];
        } else {
            $info['errorState'] = false;
            $info['errorFirst'] = '-No error-';
        }

        // We return original lang_revision & maintainer
        return $info;

    }

    /**
     * Commit some files to Cvs server.
     * @param $anode An array of files to be commited.
     * @param $log The message log to use with this commit.
     * @return The message from Cvs server after this commit.
     */
    function cvsCommit($anode, $log) {

        $files = '';

        for ($i = 0; $i < count($anode); $i++ ) {

            // We need move .new file into the real file
            @unlink( DOC_EDITOR_CVS_PATH.$anode[$i][0].$anode[$i][1]);
            @copy(   DOC_EDITOR_CVS_PATH.$anode[$i][0].$anode[$i][1].'.new', DOC_EDITOR_CVS_PATH.$anode[$i][0].$anode[$i][1]);
            @unlink( DOC_EDITOR_CVS_PATH.$anode[$i][0].$anode[$i][1].'.new');

            $files .= $anode[$i][0].$anode[$i][1].' ';

        }
        // Escape single quote
        $log = str_replace("'", "\\'", $log);

        // First, login into Cvs
        $cmd = 'export CVS_PASSFILE='.realpath(DOC_EDITOR_DATA_PATH).'/.cvspass && cd '.DOC_EDITOR_CVS_PATH.' && cvs -d :pserver:'.$this->cvsLogin.':'.$this->cvsPasswd.'@cvs.php.net:/repository login && cvs -d :pserver:'.$this->cvsLogin.':'.$this->cvsPasswd.'@cvs.php.net:/repository -f commit -l -m \''.$log.'\' '.$files;
        $output  = array();
        exec($cmd, $output);

        //$this->debug('commit cmd : '.$cmd);

        return $this->highlightCommitLog($output);
    }

    /**
     * Highlights the given commit log
        *
     * @param $message The commit log
     * @return The output message, more beautiful than before!
     */
    function highlightCommitLog($message) {

        $reg = array("/(COMMITINFO)/", "/(LOGINFO)/", "/(Checking in)/", "/(done)/", "/(bailing)/", "/(Mailing the commit email to)/", "/(Logging in to)/","(new revision)");
        return preg_replace($reg, "<span style=\"color: #15428B; font-weight: bold;\">\\1</span>", $message);

    }

    /**
     * Update information about a file after his commit (update informations added with revcheck tools).
     * @param $anode An array of files.
     * @return Nothing.
     */
    function updateRev($anode) {

        for ($i = 0; $i < count($anode); $i++ ) {

            $t = explode("/", $anode[$i][0]);

            $FileLang = $t[0];
            array_shift($t);

            $FilePath = implode("/", $t);
            $FileName = $anode[$i][1];

            //En file ?
            if ($FileLang == 'en' ) {

                $path    = DOC_EDITOR_CVS_PATH.'en/'.$FilePath.$FileName;
                $content = file_get_contents($path);
                $info    = $this->getInfoFromContent($content);
                $size    = intval(filesize($path) / 1024);
                $date    = filemtime($path);

                // For the EN file
                $s = sprintf('UPDATE `files`
                 SET
                   `revision` = "%s",
                   `size`     = "%s",
                   `mdate`    = "%s"

                 WHERE
                   `lang` = "%s" AND
                   `path` = \'/%s\' AND
                   `name` = "%s"
               ',$info['rev'], $size, $date, $FileLang, $FilePath, $FileName);
                $this->db->query($s) or die($this->db->error.'|'.$s);

                // For all LANG file
                $s = sprintf('UPDATE `files`
                 SET
                   `en_revision` = "%s"

                 WHERE
                   `lang` != "%s" AND
                   `path`  = \'/%s\' AND
                   `name`  = "%s"
               ', $info['rev'], $FileLang, $FilePath, $FileName);
                $this->db->query($s) or die($this->db->error.'|'.$s);


            } else {

                $path    = DOC_EDITOR_CVS_PATH.$FileLang.'/'.$FilePath.$FileName;
                $content = file_get_contents($path);
                $info    = $this->getInfoFromContent($content);
                $size    = intval(filesize($path) / 1024);
                $date    = filemtime($path);


                $pathEN    = DOC_EDITOR_CVS_PATH.'en/'.$FilePath.$FileName;
                $sizeEN    = intval(filesize($pathEN) / 1024);
                $dateEN    = filemtime($pathEN);

                $size_diff = $sizeEN - $size;
                $date_diff = (intval((time() - $dateEN) / 86400)) - (intval((time() - $date) / 86400));

                $s = sprintf('UPDATE `files`
                 SET
                   `revision`   = "%s",
                   `reviewed`   = "%s",
                   `size`       = "%s",
                   `mdate`      = "%s",
                   `maintainer` = "%s",
                   `status`     = "%s",
                   `size_diff`  = "%s",
                   `mdate_diff` = "%s"

                 WHERE
                   `lang`="%s" AND
                   `path`=\'/%s\' AND
                   `name`="%s"
               ',$info['en-rev'], $info['reviewed'], $size, $date, $info['maintainer'], $info['status'], $size_diff, $date_diff, $FileLang, $FilePath, $FileName);
                $this->db->query($s) or die($this->db->error.'|'.$s);
            }

            //$this->debug('in updateRev() ; DB query : '.$s);

        }
    }

    /**
     * Remove the mark "needCommit" into DB for a set of files.
     * @param $anode An array of files.
     * @return Nothing.
     */
    function removeNeedCommit($anode) {

        for ($i = 0; $i < count($anode); $i++ ) {

            $t = explode("/", $anode[$i][0]);

            $FileLang = $t[0];
            array_shift($t);

            $FilePath = implode("/", $t);
            $FileName = $anode[$i][1];

            $s = sprintf('DELETE FROM `pendingCommit`
              WHERE
                 `lang` = "%s" AND
                 `path` = \'/%s\' AND
                 `name` = "%s"
            ', $FileLang, $FilePath, $FileName);

            $this->db->query($s) or die($this->db->error.'|'.$s);

            //$this->debug('in removeNeedCommit() ; DB query : '.$s);

        }

    }

    function debug($mess) {

        $mess = '['.date("d/m:Y H:i:s").'] by '.$this->cvsLogin.' : '.$mess."\n";

        $fp = fopen(DOC_EDITOR_CVS_PATH.'../.debug', 'a+');
        fwrite($fp, $mess);
        fclose($fp);

    }

    /**
     * Add (or not) a log message to the DB.
     * @param $logMessage The log message to be added if it don't exist yet.
     * @return Nothing.
     */
    function manageLogMessage($logMessage) {

        $s = sprintf('SELECT id FROM `commitMessage` WHERE `text`="%s" AND `userID`="%s"', $this->db->real_escape_string($logMessage), $this->userID);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $nb = $r->num_rows;

        if ($nb == 0 ) {
            $s = sprintf('INSERT INTO `commitMessage` (`text`,`userID`) VALUES ("%s", "%s")', $this->db->real_escape_string($logMessage), $this->userID);
            $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        }

    }

    /**
     * Send an email.
     * @param $to The Receiver.
     * @param $subject The subject of the email.
     * @param $msg The content of the email. Don't use HTML here ; only plain text.
     * @return Nothing.
     */
    function sendEmail($to, $subject, $msg) {

        $headers = 'From: '.$this->cvsLogin.'@php.net' . "\r\n" .
        'X-Mailer: PhpDocumentation Online Editor' . "\r\n" .
        'Content-Type: text/plain; charset="utf-8"'."\n";
        mail($to, stripslashes($subject), stripslashes(trim($msg)), $headers);
    }

    /**
      * Update an option in user configuration database
      * @param $item The name of the option.
      * @param $value The value of the option.
      * @return Nothing
      */
    function updateConf($item, $value)
    {

        $s = sprintf('UPDATE `users` SET `%s`="%s" WHERE `cvs_login`="%s"', $item, $value, $this->cvsLogin);

        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        // In session
        $this->userConf[$item] = $value;
        $_SESSION['userConf'][$item] = $value;

        return '';
    }

    /**
      * Erase personal data. Delete all reference into the DB for this user.
      * @return Nothing
      */
    function erasePersonalData()
    {

        $s = sprintf('DELETE FROM `commitMessage` WHERE `userID`="%s"', $this->userID);
        $this->db->query($s);

        $s = sprintf('DELETE FROM `users` WHERE `userID`="%s"', $this->userID);
        $this->db->query($s);

        return;

    }

    function getAllFiles($node, $search='') {

        // Get Files Need Commit
        $ModifiedFiles = $this->getModifiedFiles();

        if( $search == '' ) {

            // Security
            $node = str_replace('..', '', $node);

            $d = dir(DOC_EDITOR_CVS_PATH.$node);

            $nodes = array();
            while($f = $d->read()){

                // We display only 'en' and 'LANG' tree
                if ($node == '/' && $f != 'en' && $f != $this->cvsLang ) {
                    continue;
                }


                if ($f == '.'  ||
                $f == '..' ||
                substr($f, 0, 1)  == '.' || // skip hidden files
                substr($f, -4)    == '.new' || // skip pendingCommit files
                substr($f, -6)    == '.patch' || // skip pendingPatch files
                $f == 'CVS'

                ) continue;

                if (is_dir(DOC_EDITOR_CVS_PATH . $node . '/' . $f)) {
                    $nodes[] = array('text' => $f, 'id' => $node . '/' . $f, 'cls' => 'folder', 'type' => 'folder');
                } else {

                    if (isset($ModifiedFiles[substr($node, 2, (strlen($node)-1)).'/'.$f])) {
                        $cls = 'file modified';
                    } else {
                        $cls = 'file';
                    }

                    // Get extension
                    $t       = explode('.',$f);
                    $ext     = $t[count($t)-1];
                    $nodes[] = array('text'=>$f, 'id'=>$node.'/'.$f, 'leaf'=>true, 'cls'=>$cls, 'extension'=>$ext, 'type'=>'file');

                }
            }
            $d->close();

        } else {

            $s = sprintf('SELECT `lang`, `path`, `name` FROM `files` WHERE (`lang`="%s" OR `lang`=\'en\') AND `name` LIKE \'%%%s%%\' ORDER BY `lang`, `path`, `name`', $this->cvsLang, $search);
            $r = $this->db->query($s) or die($this->db->error.'|'.$s);
            while ($a = $r->fetch_object()) {

                $t       = explode('.',$a->name);
                $ext     = $t[count($t)-1];
                $nodes[] = array('text'=>$a->lang.$a->path.$a->name, 'id'=>'//'.$a->lang.$a->path.$a->name, 'leaf'=>true, 'cls'=>'file', 'extension'=>$ext, 'type'=>'file', 'from'=>'search');

            }

        }
        return $nodes;
    }

    function saveLogMessage($messID, $mess)
    {
        $s = sprintf('UPDATE `commitMessage` SET `text`="%s" WHERE `id`="%s"', $this->db->real_escape_string($mess), $messID);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
    }

    function deleteLogMessage($messID)
    {
        $s = sprintf('DELETE FROM `commitMessage` WHERE `id`="%s"', $messID);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
    }

    function getAllFilesAboutExtension($ExtName) {

        $s = sprintf('SELECT `path`, `name` FROM `files` WHERE `path` LIKE \'/reference/%s/%%\' AND `lang`="%s" ORDER BY `path`, `name`',$ExtName, $this->cvsLang);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $node = array();

        $i=0;
        while ($a = $r->fetch_object()) {

            $node[$i]['path'] = $a->path;
            $node[$i]['name'] = $a->name;

            $i++;
        }

        return $node;

    }

    function afterPatchAccept($PatchUniqID) {

        $s = sprintf('SELECT * FROM `pendingPatch` WHERE `uniqID` = "%s"', $PatchUniqID);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $a = $r->fetch_object();

        // We need to send an email ?
        if (trim($a->email) != '' ) {

            $to = trim($a->email);
            $subject = '[PHP-DOC] - Patch accepted for '.$a->lang.$a->path.$a->name;
            $msg = <<<EOD
Your patch ($PatchUniqID) was accepted and applied to the PHP Manual.

Since the online and downloadable versions of the documentation need some
time to get updated, we would like to ask you to be a bit patient.
 	
Thank you for your submission, and for helping us make our documentation better.

-- 
{$this->cvsLogin}@php.net
EOD;
            $this->sendEmail($to, $subject, $msg);

        }
        
        // We need to delete this patch from filesystem...
        @unlink(DOC_EDITOR_CVS_PATH.$a->lang.$a->path.$a->name.'.'.$a->uniqID.'.patch');
        
        // ... and from DB
        $s = sprintf('DELETE FROM `pendingPatch` WHERE `id` = "%s"', $a->id);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

    }

    function afterPatchReject($PatchUniqID) {

        $s = sprintf('SELECT * FROM `pendingPatch` WHERE `uniqID` = "%s"', $PatchUniqID);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $a = $r->fetch_object();

        // We need to send an email ?
        if (trim($a->email) != '' ) {

            $to = trim($a->email);
            $subject = '[PHP-DOC] - Patch Rejected for '.$a->lang.$a->path.$a->name;
            $msg = <<<EOD
Your patch ($PatchUniqID) was rejected from the PHP Manual.
 	
Thank you for your submission.

-- 
{$this->cvsLogin}@php.net
EOD;
            $this->sendEmail($to, $subject, $msg);
        }
        
        // We need to delete this patch from filesystem...
        @unlink(DOC_EDITOR_CVS_PATH.$a->lang.$a->path.$a->name.'.'.$a->uniqID.'.patch');
        
        // ... and from DB
        $s = sprintf('DELETE FROM `pendingPatch` WHERE `id` = "%s"', $a->id);
        $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

    }

    function searchXmlID($lang, $fileID)
    {
        $s = sprintf('SELECT `lang`, `path`, `name` FROM `files` WHERE `lang` = "%s" AND `xmlid` LIKE "%' . $fileID . '%"', $lang);
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        return $r->fetch_object();
    }

    /**
      * Get the last update datetime
      * @return The last update datetime or "in_progress" if the update is in progress
      */
    function getLastUpdate()
    {
        // Test is there is an update in progress
        $lock_update = new LockFile('lock_update_repository');
        $lock_apply = new LockFile('lock_apply_tools');

        if( $lock_update->isLocked() || $lock_apply->isLocked() ) { return array('lastupdate'=>'in_progress', 'by'=>'-'); }
        else {

            $s = 'SELECT `lastupdate`, `by` FROM `project` WHERE `name`="php"';
            $r = $this->db->query($s);
            $a = $r->fetch_assoc();

            return $a;

        }


    } // get_last_update

    /**
      * Set the last update datetime into DB
      * @return Nothing
      */
    function setLastUpdate()
    {

        $s = 'SELECT `lastupdate`, `by` FROM `project` WHERE `name`="php"';
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);
        $nb = $r->num_rows;

        if( $nb == 0 ) {
            $s = sprintf('INSERT INTO `project` (`name`, `lastupdate`, `by`) VALUES (\'php\', now(), "%s")', ( ( isset($this->cvsLogin ) ) ? $this->cvsLogin : '-' ));
        } else {
            $s = sprintf('UPDATE `project` SET `lastupdate`=now(), `by`="%s" WHERE `name`=\'php\'', ( ( isset($this->cvsLogin ) ) ? $this->cvsLogin : '-' ));
        }
        $r = $this->db->query($s) or die('Error: '.$this->db->error.'|'.$s);

        return;

    } // 

} // End of class
