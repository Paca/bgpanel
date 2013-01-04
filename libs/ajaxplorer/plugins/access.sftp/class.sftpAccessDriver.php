<?php
/*
 * Copyright 2007-2011 Charles du Jeu <contact (at) cdujeu.me>
 * This file is part of AjaXplorer.
 *
 * AjaXplorer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AjaXplorer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with AjaXplorer.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://www.ajaxplorer.info/>.
 *
 */
defined('AJXP_EXEC') or die( 'Access not allowed' );


require_once(AJXP_INSTALL_PATH."/plugins/access.sftp/phpseclib/SFTP.php");

/**
 * @package info.ajaxplorer.plugins
 * AJXP_Plugin to access a remote server using SSH File Transfer Protocol (SFTP) with phpseclib ( http://phpseclib.sourceforge.net/ )
 * @author: warhawk3407 <warhawk3407@gmail.com>, sUpEr g2
 */
class sftpAccessDriver extends AbstractAccessDriver
{
	/**
	* @var Repository
	*/

	/** SFTP Base Path **/
	var $basePath = '';
	/** SFTP Link **/
	var $sftpLink = FALSE;



	/**
	 * initRepository
	 */
	public function initRepository()
	{
		$this->basePath = $this->repository->getOption("PATH");
		$sftpLink = $this->connect();
		$recycle = $this->repository->getOption("RECYCLE_BIN");
		if($recycle != ""){
			RecycleBinManager::init($this->urlBase, "/".$recycle);
		}
		$this->close($sftpLink);
	}



	/**
	 * SFTP/SSH2 connection
	 */
	private function connect(){
		// Repository Information
		$host = $this->repository->getOption("SFTP_HOST");
		$port = $this->repository->getOption("SFTP_PORT");
		// Credentials
		$user = $this->repository->getOption("SFTP_USER");
		$password = $this->repository->getOption("SFTP_PASSWD");

		// Connection
		$this->sftpLink = new Net_SFTP($host.':'.$port);
		if (!$this->sftpLink->login($user, $password))
		{
			throw new Exception("Login Failed!");
		}

		return TRUE;
	}

	private function close(){
		// Close Connection
		$this->sftpLink->disconnect();

		return TRUE;
	}



	// DEBUG: throw new AJXP_Exception("DEBUG:".print_r($test));



	function switchAction($action, $httpVars, $fileVars)
	{
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);

		$selection = new UserSelection();
		$selection->initFromHttpVars($httpVars);

		$dir = $httpVars["dir"] OR "";
		$dir = AJXP_Utils::securePath($dir);

		if(isSet($dir) && $action != "upload"){
			$safeDir = $dir;
			$dir = SystemTextEncoding::fromUTF8($dir);
		}
		// FILTER DIR PAGINATION ANCHOR
		if(isSet($dir) && strstr($dir, "%23")!==false){
			$parts = explode("%23", $dir);
			$dir = $parts[0];
			$page = $parts[1];
		}
		if(isSet($dest)) {
			$dest = SystemTextEncoding::fromUTF8($dest);
		}

		$mess = ConfService::getMessages();


		switch($action)
		{
			//------------------------------------
			//	XML LISTING
			//------------------------------------
			case "ls":
				if(!isSet($dir) || $dir == "/") $dir = "";

				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}

				// SFTP Open directory
				$cd = $this->sftpLink->exec('cd '.$this->basePath.$dir);
				if( !empty($cd) ){
					throw new AJXP_Exception("Cannot open dir: ".$dir);
				}
				unset($cd);

				/**
				 * AJXP_XML Header
				 *
				 * AJXP_XMLWriter::header();
				 */
				header('Content-Type: text/xml; charset=UTF-8');
				header('Cache-Control: no-cache');
				print('<?xml version="1.0" encoding="UTF-8"?>');
				print("<tree repo_has_recycle=\"false\" is_file=\"false\" filename=\"".$dir."\">");

				// List files and directories
				$contents = $this->sftpLink->rawlist($this->basePath.$dir);

				/**
				 * AJXP_XML Nodes
				 *
				 * loadNodeInfo
				 */
				$EXTENSIONS = $this->extensions();
				foreach ($contents as $key => $value) // The key is the name of a file or a folder
				{
					if($key != '.' && $key != '..') // Remove '.' and '..' directories from listing
					{
						if($value['type'] == 1){
							// FILE MetaData
							$is_image = 0; // (Default: 0)
							$mimestring = "Text File"; // Type (Default: Text Type)
							$icon = 'txt2.png'; // Icon (Default: txt2.png - Text Type)
							$file_group = $value['gid'];
							$file_owner = $value['uid'];
							$perms = substr(decoct($value['permissions']), 2); // To get permissions formatted  as "0644"
							$modified = $value['mtime']; // Modified
							$size = $this->bytesToSize($value['size']); // Readable Size
							$bytesize = $value['size']; // Computer Size

							// MIME Process (String and Icon)
							$extension = pathinfo($key, PATHINFO_EXTENSION);
							foreach($EXTENSIONS as $extValue){
								if ($extValue[0] == $extension)	{
									$mimestring = $extValue[2];
									$icon = $extValue[1];
									break;
								}
							}
							reset($EXTENSIONS);
							unset($extension);

							// Files Nodes
							$files[$key] = "<tree ajxp_node=\"true\" text=\"$key\" is_file=\"true\" is_image=\"$is_image\" filename=\"$dir/$key\" mimestring=\"$mimestring\" icon=\"$icon\" file_group=\"$file_group\" file_owner=\"$file_owner\" file_perms=\"$perms\" ajxp_modiftime=\"$modified\" bytesize=\"$bytesize\" filesize=\"$size\"/>";
						}
						else{
							// FOLDER MetaData
							$file_group = $value['gid'];
							$file_owner = $value['uid'];
							$perms = substr(decoct($value['permissions']), 1); // Perms
							$modified = $value['mtime']; // Modified
							$bytesize = $value['size']; // Computer Size
							$size = $this->bytesToSize($value['size']); // Readable Size

							// Folders Nodes
							$folders[$key] = "<tree ajxp_node=\"true\" text=\"$key\" is_file=\"false\" is_image=\"0\" filename=\"$dir/$key\" mimestring=\"Directory\" icon=\"folder.png\" openicon=\"folder_open.png\" file_group=\"$file_group\" file_owner=\"$file_owner\" file_perms=\"$perms\" ajxp_modiftime=\"$modified\" bytesize=\"$bytesize\" filesize=\"$size\"/>";
						}
						
					}
				}
				unset($contents);

				// Sort nodes and build a single array
				if (isset($folders)){
					sort($folders);
					$nodes = $folders;
				}
				else{
					$nodes = array();
				}
				if (isset($files)){
					sort($files);
				}
				else{
					$files = array();
				}
				$nodes = array_merge($nodes, $files);
				unset($files, $folders);

				/**
				 * AJXP_XML
				 *
				 * Print <tree ... />
				 */
				foreach($nodes as $node){
					print($node);
				}

				/**
				 * AJXP_XML Footer
				 *
				 * AJXP_XMLWriter::close();
				 */
				print("</tree>");

				// SFTP Connection Closure
				$this->close();
			break;
									
			//------------------------------------
			//	CREER UN REPERTOIRE / CREATE DIR
			//------------------------------------
			case "mkdir":
			
				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}
				// need to correct $dir var (remove first / in path) only for sftp
				$dir = preg_replace('/^\//', '', $dir);  
				
				// check form dirname
				$messtmp="";
				$dirname=AJXP_Utils::decodeSecureMagic($httpVars["dirname"], AJXP_SANITIZE_HTML_STRICT);
				$dirname = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($dirname));
                AJXP_Controller::applyHook("node.before_create", array(new AJXP_Node($dir."/".$dirname), -2));
                
                // SFTP create directory command
				$dir=="" ? $mkdir = $this->sftpLink->exec("mkdir ".$dirname) : $mkdir = $this->sftpLink->exec("mkdir ".$dir."/".$dirname);
				if( !empty($mkdir) ){
					throw new AJXP_Exception("Cannot create dir: ".$dir."/".$dirname);
				}
				$messtmp.="$mess[38] ".SystemTextEncoding::toUTF8($dirname)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$pendingSelection = $dirname;
				$reloadContextNode = true;
                AJXP_Logger::logAction("Create Dir", array("dir"=>$dir."/".$dirname));
                
				// SFTP Connection Closure
				$this->close();
			break;
						
			//------------------------------------
			//	CREER UN FICHIER / CREATE FILE
			//------------------------------------
			case "mkfile":
			
				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}
				// need to correct $dir var (remove first / in path) only for sftp
				$dir = preg_replace('/^\//', '', $dir);  
				
				// check form filename
				$messtmp="";
				$filename=AJXP_Utils::decodeSecureMagic($httpVars["filename"], AJXP_SANITIZE_HTML_STRICT);
				$filename = substr($filename, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($filename));
				$filename = preg_replace('/^\//', '', $filename);
				
                // SFTP create directory command
				$dir=="" ? $touch = $this->sftpLink->exec('touch '.$filename) : $touch = $this->sftpLink->exec('touch '.$dir.'/'.$filename) ;
				if( !empty($touch) ){
					throw new AJXP_Exception('Cannot touch file: '.$dir.'/'.$filename);
				}
				$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.=SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$pendingSelection = $dir."/".$filename;
				$reloadContextNode = true;
                AJXP_Logger::logAction("Create File", array("file"=>$dir."/".$filename));
                
				// SFTP Connection Closure
				$this->close();
			break;	
			
			//------------------------------------
			//	DELETE
			//------------------------------------
			case "delete";
			
				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}
				
				if($selection->isEmpty()){
					throw new AJXP_Exception("", 113);
				}
				
				$logMessage = array();
				foreach ($selection->getFiles() as $fileToDelete){
					// correction of initial "/"
					$fileToDelete = preg_replace('/^\//', '', $fileToDelete);
					if($fileToDelete == "" || $fileToDelete == DIRECTORY_SEPARATOR){
						return $mess[120];
					}
				   /** BUG de reconnaissance
					*
					* if(!file_exists($fileToDelete))
					* {
					* 	$logMessage[]=$mess[100]." ".SystemTextEncoding::toUTF8($fileToDelete);
					* 	continue;
					* }
					*/
					if($fileToDelete == $this->repository->getOption("RECYCLE_BIN")){
						// DELETING FROM RECYCLE
						RecycleBinManager::deleteFromRecycle($location);
					}
					
					$this->sftpLink->delete($fileToDelete,true) ==1 ? $errorMessage = '' : $errorMessage = 'Elément '.$fileToDelete.' impossible à supprimer';
					if(is_dir($fileToDelete) && $fileToDelete !=".." && $fileToDelete !="."){
						$logMessage[]="$mess[38] ".SystemTextEncoding::toUTF8($fileToDelete)." $mess[44].";
					} else {
						$logMessage[]="$mess[34] ".SystemTextEncoding::toUTF8($fileToDelete)." $mess[44].";
					}										
				}
				
				if(count($logMessage)) $logMessage = join("\n", $logMessage);				
				if($errorMessage!='') throw new AJXP_Exception(SystemTextEncoding::toUTF8($errorMessage));
				
				//AJXP_Controller::applyHook("node.change", array(new AJXP_Node($fileToDelete)));
				AJXP_Logger::logAction("Delete", array("files"=>$selection));
				$reloadContextNode = true;
				
				// SFTP Connection Closure
				$this->close();
			break;
		
			//------------------------------------
			//	RENAME
			//------------------------------------
			case "rename";
			
				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}
				
				// need to correct $file and $filename_new vars (remove first / in path) only for sftp
				$file = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
				$file = preg_replace('/^\//', '', $file);
				$filename_new = AJXP_Utils::decodeSecureMagic($httpVars["filename_new"]);
				$filename_new = preg_replace('/^\//', '', $filename_new);
				$this->filterUserSelectionToHidden(array($filename_new));
				$this->sftpLink->rename($file, $filename_new);
				$logMessage= SystemTextEncoding::toUTF8($file)." $mess[41] ".SystemTextEncoding::toUTF8($filename_new);
				$reloadContextNode = true;
				$pendingSelection = $filename_new;
				AJXP_Logger::logAction("Rename", array("original"=>$file, "new"=>$filename_new));

				// SFTP Connection Closure
				$this->close();
			break;
			
			//------------------------------------
			//	COPY / MOVE
			//------------------------------------
			case "copy";
			case "move";
			
				// SFTP Connection
				$this->connect();
				if ( !$this->sftpLink ){
					throw new AJXP_Exception("No sftpLink.");
				}
				
				if($selection->isEmpty()){
					throw new AJXP_Exception("", 113);
				}
				$successMessage = $errorMessage = array();
				
				// Destination folder init and check
				$dest = AJXP_Utils::decodeSecureMagic($httpVars["dest"]);
				$this->filterUserSelectionToHidden(array($httpVars["dest"]));
				$dest !='/' ? $dest = preg_replace('/^\//', '', $dest) : $dest ="~/.";
				/*
				* Check à faire sur caractère écrisible du dossier destination
				* if(!$this->isWriteable($dest))
				* {
				* 	$error[] = $mess[38]." ".$dest." ".$mess[99];
				* 	return ;
				* }
				*/
				
				// Copy or move + errorlogs
				/*
				* Check à faire sur contenu d'une archive type zip facilement lisible 
				* if($selection->inZip()){
				* 	// Set action to copy anycase (cannot move from the zip).
				* 	$action = "copy";
				* 	$this->extractArchive($dest, $selection, $error, $success);
				* } else {
				*/
				foreach ($selection->getFiles() as $fileToMove){
					// correction of initial "/" in filename
					$fileToMove = preg_replace('/^\//', '', $fileToMove);
					if ($action == "move"){
						$this->sftpLink->exec('mv '.$fileToMove.' '.$dest) == '' ? $successMessage[] = 'Elément '.$fileToMove.' déplacé avec succès vers '.$dest : $errorMessage[] = 'Elément '.$fileToMove.' impossible à déplacer vers '.$dest;
					} else {
						$this->sftpLink->exec('cp '.$fileToMove.' '.$dest) == '' ? $successMessage[] = 'Elément '.$fileToMove.' copié avec succès vers '.$dest : $errorMessage[] = 'Elément '.$fileToMove.' impossible à copier vers '.$dest;
					}
				}
				/*
				}
				*/
				if(count($errorMessage)){					
					throw new AJXP_Exception(SystemTextEncoding::toUTF8(join("\n", $errorMessage)));
				} else {
					$logMessage = join("\n", $successMessage);
					AJXP_Logger::logAction(($action=="move"?"Move":"Copy"), array("files"=>$selection, "destination"=>$dest));
				}
				// Don't know if it's necessary but correction back in time for $dest / 
				$dest !='~/.' ? $dest = '/'.$dest : $dest ='/';
				$reloadContextNode = true;
                if(!(RecycleBinManager::getRelativeRecycle() == $dest && $this->driverConf["HIDE_RECYCLE"] == true)){
                    $reloadDataNode = $dest;
                }

			break;
		
		}
		$xmlBuffer = "";
		if(isset($logMessage) || isset($errorMessage))
		{
			$xmlBuffer .= AJXP_XMLWriter::sendMessage((isSet($logMessage)?$logMessage:null), (isSet($errorMessage)?$errorMessage:null), false);
		}
		if($reloadContextNode){
			if(!isSet($pendingSelection)) $pendingSelection = "";
			$xmlBuffer .= AJXP_XMLWriter::reloadDataNode("", $pendingSelection, false);
		}
		if(isSet($reloadDataNode)){
			$xmlBuffer .= AJXP_XMLWriter::reloadDataNode($reloadDataNode, "", false);
		}
		return $xmlBuffer;
	}

	/**
	 * Convert bytes to human readable format
	 *
	 * http://codeaid.net/php/convert-size-in-bytes-to-a-human-readable-format-%28php%29
	 *
	 * @param integer bytes Size in bytes to convert
	 * @return string
	 */
	public function bytesToSize($bytes, $precision = 2){
		$kilobyte = 1024;
		$megabyte = $kilobyte * 1024;
		$gigabyte = $megabyte * 1024;
		$terabyte = $gigabyte * 1024;

		if (($bytes >= 0) && ($bytes < $kilobyte)) {
			return $bytes . ' B';

		} elseif (($bytes >= $kilobyte) && ($bytes < $megabyte)) {
			return round($bytes / $kilobyte, $precision) . ' KB';

		} elseif (($bytes >= $megabyte) && ($bytes < $gigabyte)) {
			return round($bytes / $megabyte, $precision) . ' MB';

		} elseif (($bytes >= $gigabyte) && ($bytes < $terabyte)) {
			return round($bytes / $gigabyte, $precision) . ' GB';

		} elseif ($bytes >= $terabyte) {
			return round($bytes / $terabyte, $precision) . ' TB';

		} else {
			return $bytes . ' B';
		}
	}

	// ALL FILES TYPES
	public function extensions(){
		return array(
			array("mid", "midi.png", "Midi File"),
			array("txt", "txt2.png", "Text file"),
			array("sql","txt2.png", "Text file"),
			array("js","javascript.png", "Javascript"),
			array("gif","image.png", "GIF picture"),
			array("jpg","image.png", "JPG picture"),
			array("html","html.png", "HTML page"),
			array("htm","html.png", "HTML page"),
			array("rar","archive.png", "RAR File"),
			array("gz","zip.png", "GZ File"),
			array("tgz","archive.png", "GZ File"),
			array("bz2","archive.png", "BZ2 File"),
			array("z","archive.png", "GZ File"),
			array("ra","video.png", "REAL file"),
			array("ram","video.png", "REAL file"),
			array("rm","video.png", "REAL file"),
			array("pl","source_pl.png", "PERL script"),
			array("zip","zip.png", "ZIP file"),
			array("wav","sound.png", "WAV file"),
			array("php","php.png", "PHP script"),
			array("php5","php.png", "PHP script"),
			array("php3","php.png", "PHP script"),
			array("phtml","php.png", "PHP script"),
			array("exe","exe.png", "Exe file"),
			array("sh","txt2.png", "Shell file"),
			array("cfg","txt2.png", "CFG file"),
			array("bmp","image.png", "BMP picture"),
			array("png","image.png", "PNG picture"),
			array("css","css.png", "CSS File"),
			array("mp3","sound.png", "MP3 File"),
			array("m4a","sound.png", "MP3 File"),
			array("aac","sound.png", "MP3 File"),
			array("xls","spreadsheet.png", "Spreadsheet"),
			array("xlsx","spreadsheet.png", "Spreadsheet"),
			array("ods","spreadsheet.png", "Spreadsheet"),
			array("sxc","spreadsheet.png", "Spreadsheet"),
			array("csv","spreadsheet.png", "Spreadsheet"),
			array("tsv","spreadsheet.png", "Spreadsheet"),
			array("doc","word.png", "Word Document"),
			array("docx","word.png", "Word Document"),
			array("odt","word.png", "Word Document"),
			array("swx","word.png", "Word Document"),
			array("rtf","word.png", "Word Document"),
			array("ppt","presentation.png", "Presentation"),
			array("pps","presentation.png", "Presentation"),
			array("odp","presentation.png", "Presentation"),
			array("sxi","presentation.png", "Presentation"),
			array("pdf","pdf.png", "PDF File"),
			array("mov","video.png", "MOV File"),
			array("avi","video.png", "AVI File"),
			array("mpg","video.png", "MPG File"),
			array("mpeg","video.png", "MPEG File"),
			array("mp4","video.png", "MPEG File"),
			array("m4v","video.png", "MPEG File"),
			array("ogv","video.png", "Video"),
			array("webm","video.png", "Video"),
			array("wmv","video.png", "AVI File"),
			array("swf","flash.png", "FLASH File"),
			array("flv","flash.png", "FLASH File"),
			array("tiff","image.png", "TIFF"),
			array("tif","image.png", "TIFF"),
			array("svg","image.png", "SVG"),
			array("psd","image.png", "Photoshop")
		);
	}
	
	/**
	 * Test if userSelection is containing a hidden file, which should not be the case!
	 * @param UserSelection $files
	 */
	public function filterUserSelectionToHidden($files){
		foreach ($files as $file){
			$file = basename($file);
			if(AJXP_Utils::isHidden($file) && !$this->driverConf["SHOW_HIDDEN_FILES"]){
				throw new Exception("Forbidden");
			}
			/*
				if($this->filterFile($file) || $this->filterFolder($file)){
				throw new Exception("Forbidden");
			}
			*/
		}
	}
	
	public function isWriteable($dir)
	{
		return is_writable($dir);
	}
	
	protected function rawListEntryToStat($entry, $filterStatPerms = false)
    {
        $info = array();    
		$vinfo = preg_split("/[\s]+/", $entry);
		AJXP_Logger::debug("RAW LIST", $entry);
		$statValue = array();
		if ($vinfo[0] !== "total"){
            $fileperms = $vinfo[0];
            $info['num']   = $vinfo[1];
            $info['owner'] = $vinfo[2];
            $info['groups'] = array();
            $i = 3;
            while(true){
                $info['groups'][] = $vinfo[$i];
                $i++;
                // Detect "Size" and "Month"
                if(is_numeric($vinfo[$i]) && !is_numeric($vinfo[$i+1])) break;
            }
            $info['group'] = implode(" ", $info["groups"]);
            $info['size']  = $vinfo[$i]; $i++;
            $info['month'] = $vinfo[$i]; $i++;
      		$info['day']   = $vinfo[$i]; $i++;
      		$info['timeOrYear']  = $vinfo[$i]; $i++;
		 }
         $resplit = preg_split("/[\s]+/", $entry, 8 + count($info["groups"]));
    	 $file = trim(array_pop($resplit));
		 $statValue[7] = $statValue["size"] = trim($info['size']);
		 if(strstr($info["timeOrYear"], ":")){
		 	$info["time"] = $info["timeOrYear"];
		 	$info["year"] = date("Y");
		 }else{
		 	$info["time"] = '09:00';
		 	$info["year"] = $info["timeOrYear"];
		 }
		 $statValue[4] = $statValue["uid"] = $info["owner"];
		 $statValue[5] = $statValue["gid"] = $info["group"];
    	 $filedate  = trim($info['day'])." ".trim($info['month'])." ".trim($info['year'])." ".trim($info['time']);
    	 $statValue[9] = $statValue["mtime"]  = strtotime($filedate);
    	 
		 $isDir = false;
		 if (strpos($fileperms,"d")!==FALSE || strpos($fileperms,"l")!==FALSE)
		 {
			 if(strpos($fileperms,"l")!==FALSE)
			 {
    			$test=explode(" ->", $file);
				$file=$test[0];
		 	 }
		 	 $isDir = true;
		}
		$boolIsDir = $isDir;
		$statValue[2] = $statValue["mode"] = $this->convertingChmod($fileperms);
		$statValue["ftp_perms"] = $fileperms;
		return array("name"=>$file, "stat"=>$statValue, "dir"=>$isDir);
	}
	
	protected function convertingChmod($permissions, $filterForStat = false)
	{
		$mode = 0;
		
		if ($permissions[1] == 'r') $mode += 0400;
		if ($permissions[2] == 'w') $mode += 0200;
		if ($permissions[3] == 'x') $mode += 0100;		
	 	else if ($permissions[3] == 's') $mode += 04100;
	 	else if ($permissions[3] == 'S') $mode += 04000;
	
	 	if ($permissions[4] == 'r') $mode += 040;
	 	if ($permissions[5] == 'w' || ($filterForStat && $permissions[2] == 'w')) $mode += 020;
	 	if ($permissions[6] == 'x' || ($filterForStat && $permissions[3] == 'x')) $mode += 010;
	 	else if ($permissions[6] == 's') $mode += 02010;
	 	else if ($permissions[6] == 'S') $mode += 02000;
	
	 	if ($permissions[7] == 'r') $mode += 04;
	 	if ($permissions[8] == 'w' || ($filterForStat && $permissions[2] == 'w')) $mode += 02;
	 	if ($permissions[9] == 'x' || ($filterForStat && $permissions[3] == 'x')) $mode += 01;
	 	else if ($permissions[9] == 't') $mode += 01001;
	 	else if ($permissions[9] == 'T') $mode += 01000;	
	 	
		if($permissions[0] != "d") {
			$mode += 0100000;
		}else{
			$mode += 0040000;
		}
	 	
		$mode = (string)("0".$mode);	
		return  $mode;
	}
	
}

?>