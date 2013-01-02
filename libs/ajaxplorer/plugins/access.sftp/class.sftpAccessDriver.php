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
class sftpAccessDriver extends fsAccessDriver
{
	/**
	* @var Repository
	*/
	public $repository;
	public $driverConf;
	protected $wrapperClassName;
	protected $urlBase;

	// SFTP Link
	var $sftpLink = false;

	/**
	 * Parse
	 * @param DOMNode $contribNode
	 */
	protected function parseSpecificContributions(&$contribNode)
	{
		parent::parseSpecificContributions($contribNode);
		if($contribNode->nodeName != "actions") return ;
		$this->disableArchiveBrowsingContributions($contribNode);
	}

	/**
	 * initRepository
	 */
	function initRepository()
	{
		if(is_array($this->pluginConf)){
			$this->driverConf = $this->pluginConf;
		}else{
			$this->driverConf = array();
		}

		$path = $this->repository->getOption("PATH");
		$recycle = $this->repository->getOption("RECYCLE_BIN");
        ConfService::setConf("PROBE_REAL_SIZE", false);

		$wrapperData = $this->detectStreamWrapper(true);
		$this->wrapperClassName = $wrapperData["classname"];
		$this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();

		if($recycle != ""){
			RecycleBinManager::init($this->urlBase, "/".$recycle);
		}
	}

	/**
	 * SFTP/SSH2 connection
	 */
	function connect(){
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

		return true;
	}

	/**
	 * switchAction function
	 * @param $action 
	 * @param $httpVars
	 * @param $fileVars
	 */
	function switchAction($action, $httpVars, $fileVars)
	{
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);

		$selection = new UserSelection();
		$dir = $httpVars["dir"] OR "";
        if($this->wrapperClassName == "fsAccessWrapper"){
            $dir = fsAccessWrapper::patchPathForBaseDir($dir);
        }
		$dir = AJXP_Utils::securePath($dir);
		if($action != "upload"){
			$dir = AJXP_Utils::decodeSecureMagic($dir);
		}
		$selection->initFromHttpVars($httpVars);
		if(!$selection->isEmpty()){
			$this->filterUserSelectionToHidden($selection->getFiles());
		}
		$mess = ConfService::getMessages();

		$newArgs = RecycleBinManager::filterActions($action, $selection, $dir, $httpVars);
		if(isSet($newArgs["action"])) $action = $newArgs["action"];
		if(isSet($newArgs["dest"])) $httpVars["dest"] = SystemTextEncoding::toUTF8($newArgs["dest"]);//Re-encode!
 		// FILTER DIR PAGINATION ANCHOR
		$page = null;
		if(isSet($dir) && strstr($dir, "%23")!==false){
			$parts = explode("%23", $dir);
			$dir = $parts[0];
			$page = $parts[1];
		}

		$pendingSelection = "";
		$logMessage = null;
		$reloadContextNode = false;

		/*
		$repo = ConfService::getRepository();
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);
		$xmlBuffer = "";
		foreach($httpVars as $getName=>$getValue){
			$$getName = AJXP_Utils::securePath($getValue);
		}
		$selection = new UserSelection();
		$selection->initFromHttpVars($httpVars);
		if(isSet($dir) && $action != "upload") {
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
		*/

		switch($action)
		{

			/*
			//------------------------------------
			//	DOWNLOAD
			//------------------------------------
			case "download":
				AJXP_Logger::logAction("Download", array("files"=>$selection));
				@set_error_handler(array("HTMLWriter", "javascriptErrorHandler"), E_ALL & ~ E_NOTICE);
				@register_shutdown_function("restore_error_handler");
				$zip = false;
				if($selection->isUnique()){
					if(is_dir($this->urlBase.$selection->getUniqueFile())) {
						$zip = true;
						$base = basename($selection->getUniqueFile());
						$dir .= "/".dirname($selection->getUniqueFile());
					}else{
						if(!file_exists($this->urlBase.$selection->getUniqueFile())){
							throw new Exception("Cannot find file!");
						}
					}
				}else{
					$zip = true;
				}
				if($zip){
					// Make a temp zip and send it as download
					$loggedUser = AuthService::getLoggedUser();
					$file = AJXP_Utils::getAjxpTmpDir()."/".($loggedUser?$loggedUser->getId():"shared")."_".time()."tmpDownload.zip";
					$zipFile = $this->makeZip($selection->getFiles(), $file, $dir);
					if(!$zipFile) throw new AJXP_Exception("Error while compressing");
					register_shutdown_function("unlink", $file);
					$localName = ($base==""?"Files":$base).".zip";
					$this->readFile($file, "force-download", $localName, false, false, true);
				}else{
					$localName = "";
					AJXP_Controller::applyHook("dl.localname", array($this->urlBase.$selection->getUniqueFile(), &$localName, $this->wrapperClassName));
					$this->readFile($this->urlBase.$selection->getUniqueFile(), "force-download", $localName);
				}

			break;
			*/
			
			/*
			//------------------------------------
			//	DELETE
			//------------------------------------
			case "delete";

				if($selection->isEmpty())
				{
					throw new AJXP_Exception("", 113);
				}
				$logMessages = array();
				$errorMessage = $this->delete($selection->getFiles(), $logMessages);
				if(count($logMessages))
				{
					$logMessage = join("\n", $logMessages);
				}
				if($errorMessage) throw new AJXP_Exception(SystemTextEncoding::toUTF8($errorMessage));
				AJXP_Logger::logAction("Delete", array("files"=>$selection));
				$reloadContextNode = true;

			break;

			//------------------------------------
			//	RENAME
			//------------------------------------
			case "rename";

				$file = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
				$filename_new = AJXP_Utils::decodeSecureMagic($httpVars["filename_new"]);
                $dest = null;
                if(isSet($httpVars["dest"])){
                    $dest = AJXP_Utils::decodeSecureMagic($httpVars["dest"]);
                    $filename_new = "";
                }
				$this->filterUserSelectionToHidden(array($filename_new));
				$this->rename($file, $filename_new, $dest);
				$logMessage= SystemTextEncoding::toUTF8($file)." $mess[41] ".SystemTextEncoding::toUTF8($filename_new);
				$reloadContextNode = true;
				$pendingSelection = $filename_new;
				AJXP_Logger::logAction("Rename", array("original"=>$file, "new"=>$filename_new));

			break;

			//------------------------------------
			//	CREATE DIR
			//------------------------------------
			case "mkdir";

				$messtmp="";
				$dirname=AJXP_Utils::decodeSecureMagic($httpVars["dirname"], AJXP_SANITIZE_HTML_STRICT);
				$dirname = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($dirname));
                AJXP_Controller::applyHook("node.before_create", array(new AJXP_Node($dir."/".$dirname), -2));
				$error = $this->mkDir($dir, $dirname);
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
				$messtmp.="$mess[38] ".SystemTextEncoding::toUTF8($dirname)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$pendingSelection = $dirname;
				$reloadContextNode = true;
                AJXP_Logger::logAction("Create Dir", array("dir"=>$dir."/".$dirname));

			break;

			//------------------------------------
			//	CREER UN FICHIER / CREATE FILE
			//------------------------------------
			case "mkfile";

				$messtmp="";
				$filename=AJXP_Utils::decodeSecureMagic($httpVars["filename"], AJXP_SANITIZE_HTML_STRICT);
				$filename = substr($filename, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
				$this->filterUserSelectionToHidden(array($filename));
				$content = "";
				if(isSet($httpVars["content"])){
					$content = $httpVars["content"];
				}
				$error = $this->createEmptyFile($dir, $filename, $content);
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
				$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
				if($dir=="") {$messtmp.="/";} else {$messtmp.=SystemTextEncoding::toUTF8($dir);}
				$logMessage = $messtmp;
				$reloadContextNode = true;
				$pendingSelection = $dir."/".$filename;
				AJXP_Logger::logAction("Create File", array("file"=>$dir."/".$filename));
				//$newNode = new AJXP_Node($this->urlBase.$dir."/".$filename);
				//AJXP_Controller::applyHook("node.change", array(null, $newNode, false));

			break;

			//------------------------------------
			//	CHANGE FILE PERMISSION
			//------------------------------------
			case "chmod";

				$messtmp="";
				$files = $selection->getFiles();
				$changedFiles = array();
				$chmod_value = $httpVars["chmod_value"];
				$recursive = $httpVars["recursive"];
				$recur_apply_to = $httpVars["recur_apply_to"];
				foreach ($files as $fileName){
					$error = $this->chmod($fileName, $chmod_value, ($recursive=="on"), ($recursive=="on"?$recur_apply_to:"both"), $changedFiles);
				}
				if(isSet($error)){
					throw new AJXP_Exception($error);
				}
				//$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
				$logMessage="Successfully changed permission to ".$chmod_value." for ".count($changedFiles)." files or folders";
				$reloadContextNode = true;
				AJXP_Logger::logAction("Chmod", array("dir"=>$dir, "filesCount"=>count($changedFiles)));

			break;

			//------------------------------------
			//	UPLOAD
			//------------------------------------
			case "upload":

				AJXP_Logger::debug("Upload Files Data", $fileVars);
				$destination=$this->urlBase.AJXP_Utils::decodeSecureMagic($dir);
				AJXP_Logger::debug("Upload inside", array("destination"=>$destination));
				if(!$this->isWriteable($destination))
				{
					$errorCode = 412;
					$errorMessage = "$mess[38] ".SystemTextEncoding::toUTF8($dir)." $mess[99].";
					AJXP_Logger::debug("Upload error 412", array("destination"=>$destination));
					return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
				}
				foreach ($fileVars as $boxName => $boxData)
				{
					if(substr($boxName, 0, 9) != "userfile_") continue;
					$err = AJXP_Utils::parseFileDataErrors($boxData);
					if($err != null)
					{
						$errorCode = $err[0];
						$errorMessage = $err[1];
						break;
					}
					$userfile_name = $boxData["name"];
					try{
						$this->filterUserSelectionToHidden(array($userfile_name));
					}catch (Exception $e){
						return array("ERROR" => array("CODE" => 411, "MESSAGE" => "Forbidden"));
					}
					$userfile_name=AJXP_Utils::sanitize(SystemTextEncoding::fromPostedFileName($userfile_name), AJXP_SANITIZE_HTML_STRICT);
                    if(isSet($httpVars["urlencoded_filename"])){
                        $userfile_name = AJXP_Utils::sanitize(SystemTextEncoding::fromUTF8(urldecode($httpVars["urlencoded_filename"])), AJXP_SANITIZE_HTML_STRICT);
                    }
                    AJXP_Logger::debug("User filename ".$userfile_name);
                    $userfile_name = substr($userfile_name, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
					if(isSet($httpVars["auto_rename"])){
						$userfile_name = self::autoRenameForDest($destination, $userfile_name);
					}
                    AJXP_Controller::applyHook("node.before_create", array(new AJXP_Node($this->urlBase.$dir."/".$userfile_name), $boxData["size"]));
					if(isSet($boxData["input_upload"])){
						try{
							AJXP_Logger::debug("Begining reading INPUT stream");
                            if(file_exists($destination."/".$userfile_name)){
                                AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($destination."/".$userfile_name), $boxData["size"]));
                            }
                            AJXP_Controller::applyHook("node.before_change", array(new AJXP_Node($destination)));
							$input = fopen("php://input", "r");
							$output = fopen("$destination/".$userfile_name, "w");
							$sizeRead = 0;
							while($sizeRead < intval($boxData["size"])){
								$chunk = fread($input, 4096);
								$sizeRead += strlen($chunk);
								fwrite($output, $chunk, strlen($chunk));
							}
							fclose($input);
							fclose($output);
							AJXP_Logger::debug("End reading INPUT stream");
						}catch (Exception $e){
							$errorCode=411;
							$errorMessage = $e->getMessage();
							break;
						}
					}else{
                        try {
                            AJXP_Controller::applyHook("before_create", array(new AJXP_Node($destination."/".$userfile_name), $boxData["size"]));
                        }catch (Exception $e){
                            $errorCode=411;
                            $errorMessage = $e->getMessage();
             				break;
                        }

                        $result = @move_uploaded_file($boxData["tmp_name"], "$destination/".$userfile_name);
                        if(!$result){
                            $realPath = call_user_func(array($this->wrapperClassName, "getRealFSReference"),"$destination/".$userfile_name);
                            $result = move_uploaded_file($boxData["tmp_name"], $realPath);
                        }
						if (!$result)
						{
							$errorCode=411;
							$errorMessage="$mess[33] ".$userfile_name;
							break;
						}
					}
                    if(isSet($httpVars["appendto_urlencoded_part"])){
                        $appendTo = AJXP_Utils::sanitize(SystemTextEncoding::fromUTF8(urldecode($httpVars["appendto_urlencoded_part"])), AJXP_SANITIZE_HTML_STRICT);
                        if(file_exists($destination ."/" . $appendTo)){
                            AJXP_Logger::debug("Should copy stream from $userfile_name to $appendTo");
                            $partO = fopen($destination."/".$userfile_name, "r");
                            $appendF = fopen($destination ."/". $appendTo, "a+");
                            while(!feof($partO)){
                                $buf = fread($partO, 1024);
                                fwrite($appendF, $buf, strlen($buf));
                            }
                            fclose($partO);
                            fclose($appendF);
                            AJXP_Logger::debug("Done, closing streams!");
                        }
                        @unlink($destination."/".$userfile_name);
                        $userfile_name = $appendTo;
                    }

					$this->changeMode($destination."/".$userfile_name);
                    AJXP_Controller::applyHook("node.change", array(null, new AJXP_Node($destination."/".$userfile_name), false));
					$logMessage.="$mess[34] ".SystemTextEncoding::toUTF8($userfile_name)." $mess[35] $dir";
					AJXP_Logger::logAction("Upload File", array("file"=>SystemTextEncoding::fromUTF8($dir)."/".$userfile_name));
				}

				if(isSet($errorMessage)){
					AJXP_Logger::debug("Return error $errorCode $errorMessage");
					return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
				}else{
					AJXP_Logger::debug("Return success");
					return array("SUCCESS" => true);
				}
				return ;

			break;

			*/

			//------------------------------------
			//	XML LISTING
			//------------------------------------
			case "ls":

				if(!isSet($dir) || $dir == "/") $dir = "";

				$lsOptions = $this->parseLsOptions((isSet($httpVars["options"])?$httpVars["options"]:"a"));

				$startTime = microtime();

				$dir = AJXP_Utils::securePath(SystemTextEncoding::magicDequote($dir));

				$path = $this->urlBase.($dir!= ""?($dir[0]=="/"?"":"/").$dir:"");
                $nonPatchedPath = $path;
                if($this->wrapperClassName == "fsAccessWrapper") {
                    $nonPatchedPath = fsAccessWrapper::unPatchPathForBaseDir($path);
                }

				$threshold = $this->repository->getOption("PAGINATION_THRESHOLD");
				if(!isSet($threshold) || intval($threshold) == 0) $threshold = 500;

				$limitPerPage = $this->repository->getOption("PAGINATION_NUMBER");
				if(!isset($limitPerPage) || intval($limitPerPage) == 0) $limitPerPage = 200;

				$countFiles = $this->countFiles($path, !$lsOptions["f"]);
				if($countFiles > $threshold){
					$offset = 0;
					$crtPage = 1;
					if(isSet($page)){
						$offset = (intval($page)-1)*$limitPerPage;
						$crtPage = $page;
					}
					$totalPages = floor($countFiles / $limitPerPage) + 1;
				}else{
					$offset = $limitPerPage = 0;
				}

				$metaData = array();
				if(RecycleBinManager::recycleEnabled() && $dir == ""){
                    $metaData["repo_has_recycle"] = "true";
				}

				$parentAjxpNode = new AJXP_Node($nonPatchedPath, $metaData);
                $parentAjxpNode->loadNodeInfo(false, true, ($lsOptions["l"]?"all":"minimal"));
                if(AJXP_XMLWriter::$headerSent == "tree"){
                    AJXP_XMLWriter::renderAjxpNode($parentAjxpNode, false);
                }else{
                    AJXP_XMLWriter::renderAjxpHeaderNode($parentAjxpNode);
                }
				if(isSet($totalPages) && isSet($crtPage)){
					AJXP_XMLWriter::renderPaginationData(
						$countFiles,
						$crtPage,
						$totalPages,
						$this->countFiles($path, TRUE)
					);
					if(!$lsOptions["f"]){
						AJXP_XMLWriter::close();
						exit(1);
					}
				}

				$cursor = 0;
				$fullList = array("d" => array(), "z" => array(), "f" => array());



				// SFTP Connection
				$this->connect();
				if ( ! $this->sftpLink ){
					throw new AJXP_Exception("DEBUG: No sftpLink.");
				}

				/*
				// SFTP Open directory
				$cd = $this->sftpLink->exec('cd '.$dir);
				if(!empty($cd)) {
					throw new AJXP_Exception("Cannot open dir ".$dir.' -- cd:'.$cd);
					//throw new AJXP_Exception("Cannot open dir ".$nonPatchedPath);
				}
				unset($cd);
				*/

				// List files and directories inside the specified path
				$nodes = $this->sftpLink->nlist();
				// Sort
				if(!empty($this->driverConf["SCANDIR_RESULT_SORTFONC"])){
					usort($nodes, $this->driverConf["SCANDIR_RESULT_SORTFONC"]);
				}



				foreach ($nodes as $nodeName)
				{
					if($nodeName == "." || $nodeName == "..") continue;

					$isLeaf = "";
					if(!$this->filterNodeName($path, $nodeName, $isLeaf, $lsOptions)){
						continue;
					}
					if(RecycleBinManager::recycleEnabled() && $dir == "" && "/".$nodeName == RecycleBinManager::getRecyclePath()){
						continue;
					}

					if($offset > 0 && $cursor < $offset){
						$cursor ++;
						continue;
					}
					if($limitPerPage > 0 && ($cursor - $offset) >= $limitPerPage) {
						break;
					}

					$currentFile = $nonPatchedPath."/".$nodeName;
                    $meta = array();
                    if($isLeaf != "") $meta = array("is_file" => ($isLeaf?"1":"0"));
                    $node = new AJXP_Node($currentFile, $meta);
                    $node->setLabel($nodeName);
                    $node->loadNodeInfo(false, false, ($lsOptions["l"]?"all":"minimal"));
					if(!empty($node->metaData["nodeName"]) && $node->metaData["nodeName"] != $nodeName){
                        $node->setUrl($nonPatchedPath."/".$node->metaData["nodeName"]);
					}
                    if(!empty($node->metaData["hidden"]) && $node->metaData["hidden"] === true){
               			continue;
               		}
                    if(!empty($node->metaData["mimestring_id"]) && array_key_exists($node->metaData["mimestring_id"], $mess)){
                        $node->mergeMetadata(array("mimestring" =>  $mess[$node->metaData["mimestring_id"]]));
                    }

                    $nodeType = "d";
                    if($node->isLeaf()){
                        if(AJXP_Utils::isBrowsableArchive($nodeName)) {
                            if($lsOptions["f"] && $lsOptions["z"]){
                                $nodeType = "f";
                            }else{
                                $nodeType = "z";
                            }
                        }
                        else $nodeType = "f";
                    }

					$fullList[$nodeType][$nodeName] = $node;
					$cursor ++;
				}
                if(isSet($httpVars["recursive"]) && $httpVars["recursive"] == "true"){
                    foreach($fullList["d"] as $nodeDir){
                        $this->switchAction("ls", array(
                            "dir" => SystemTextEncoding::toUTF8($nodeDir->getPath()),
                            "options"=> $httpVars["options"],
                            "recursive" => "true"
                        ), array());
                    }
                }else{
                    array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["d"]);
                }
				array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["z"]);
				array_map(array("AJXP_XMLWriter", "renderAjxpNode"), $fullList["f"]);

				// ADD RECYCLE BIN TO THE LIST
				if($dir == "" && RecycleBinManager::recycleEnabled() && $this->driverConf["HIDE_RECYCLE"] !== true)
				{
					$recycleBinOption = RecycleBinManager::getRelativeRecycle();
					if(file_exists($this->urlBase.$recycleBinOption)){
						$recycleIcon = ($this->countFiles($this->urlBase.$recycleBinOption, false, true)>0?"trashcan_full.png":"trashcan.png");
						$recycleNode = new AJXP_Node($this->urlBase.$recycleBinOption);
                        $recycleNode->loadNodeInfo();
                        AJXP_XMLWriter::renderAjxpNode($recycleNode);
					}
				}

				AJXP_Logger::debug("LS Time : ".intval((microtime()-$startTime)*1000)."ms");

				AJXP_XMLWriter::close();
				return ;

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

}

?>