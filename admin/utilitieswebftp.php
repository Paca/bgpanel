<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * LICENSE:
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @categories	Games/Entertainment, Systems Administration
 * @package		Bright Game Panel
 * @author		Super_G2 <super_g2@optimal-gamerz.net> @NOSPAM
 * @copyleft	2012
 * @license		GNU General Public License version 3.0 (GPLv3)
 * @version		(Release 0) DEVELOPER BETA 4
 * @link		http://www.bgpanel.net/
 */



$title = 'File Manager Tool';
$page = 'utilitieswebftp';
$tab = 4;
$return = 'utilitieswebftp.php';

//require("../libs/ajaxplorer/???")
require("../configuration.php");
require("./include.php");


$servers = mysql_query( "SELECT `serverid`, `name` FROM `".DBPREFIX."server` WHERE `status` = 'Active' && `panelstatus` = 'Started' ORDER BY `name`" );


//---------------------------------------------------------+

if (isset($_GET['serverid']) && is_numeric($_GET['serverid']))
{
	if (query_numrows( "SELECT `name` FROM `".DBPREFIX."server` WHERE `serverid` = '".$_GET['serverid']."'" ) == 0)
	{
		exit('Error: Server is invalid.');
	}
	else
	{
		$serverid = $_GET['serverid'];
		$step = 'webftp';
	}
}
else
{
	$step = 'selectserver';
}

//---------------------------------------------------------+


switch ($step)
{

//------------------------------------------------------------------------------------------------------------+



	case 'selectserver':


		include("./bootstrap/header.php");


		/**
		 * Notifications
		 */
		if (isset($_SESSION['msg1']) && isset($_SESSION['msg2']) && isset($_SESSION['msg-type']))
		{
?>
			<div class="alert alert-<?php
			switch ($_SESSION['msg-type'])
			{
				case 'block':
					echo 'block';
					break;

				case 'error':
					echo 'error';
					break;

				case 'success':
					echo 'success';
					break;

				case 'info':
					echo 'info';
					break;
			}
?>">
				<a class="close" data-dismiss="alert">&times;</a>
				<h4 class="alert-heading"><?php echo $_SESSION['msg1']; ?></h4>
				<?php echo $_SESSION['msg2']; ?>
			</div>
<?php
			unset($_SESSION['msg1']);
			unset($_SESSION['msg2']);
			unset($_SESSION['msg-type']);
		}
		/**
		 *
		 */


?>
			<div class="well">
<?php

		if (query_numrows( "SELECT `serverid` FROM `".DBPREFIX."server` WHERE `status` = 'Active' && `panelstatus` = 'Started'" ) != 0)
		{
?>
				<form method="get" action="utilitiesrcontool.php">
					<label>Available servers for File Manager Access :</label>
						<select name="serverid">
<?php

			//---------------------------------------------------------+
			while ($rowsServers = mysql_fetch_assoc($servers))
			{
?>
							<option value="<?php echo $rowsServers['serverid']; ?>">#<?php echo $rowsServers['serverid']; ?> - <?php echo htmlspecialchars($rowsServers['name'], ENT_QUOTES); ?></option>
<?php
			}
			//---------------------------------------------------------+

?>
						</select>
						<div style="text-align: center; margin-top: 19px;">
							<button type="submit" class="btn btn-primary btn-large">Go to File Manager</button>
						</div>
				</form>
<?php
		}
		else
		{
?>
				<div class="alert alert-block">
					<h4 class="alert-heading">No server available for File Managing!</h4>
					All servers are offline.
				</div>
<?php
		}
?>
				<div style="text-align: center; margin-top: 19px;">
					<ul class="pager">
						<li>
							<a href="index.php">Back to Home</a>
						</li>
					</ul>
				</div>
			</div>
<?php

		break;



//------------------------------------------------------------------------------------------------------------+



	case 'webftp':
		require_once("../libs/phpseclib/SSH2.php");
		require_once("../libs/phpseclib/Crypt/AES.php");
		###
		if (empty($serverid))
		{
			$error .= 'No ServerID specified for server validation !';
		}
		else
		{
			if (!is_numeric($serverid))
			{
				$error .= 'Invalid ServerID. ';
			}
			else if (query_numrows( "SELECT `name` FROM `".DBPREFIX."server` WHERE `serverid` = '".$serverid."'" ) == 0)
			{
				$error .= 'Invalid ServerID. ';
			}
		}
		###
		if (isset($error))
		{
			$_SESSION['msg1'] = 'Validation Error!';
			$_SESSION['msg2'] = $error;
			$_SESSION['msg-type'] = 'error';
			unset($error);
			header( 'Location: index.php' );
			die();
		}
		###
		$panelstatus = query_fetch_assoc( "SELECT `panelstatus` FROM `".DBPREFIX."server` WHERE `serverid` = '".$serverid."' LIMIT 1" );
		if ($panelstatus['panelstatus'] != 'Started')
		{
			$_SESSION['msg1'] = 'Validation Error!';
			$_SESSION['msg2'] = 'The server is not running!';
			$_SESSION['msg-type'] = 'error';
			header( 'Location: index.php' );
			die();
		}
		###
		$status = query_fetch_assoc( "SELECT `status` FROM `".DBPREFIX."server` WHERE `serverid` = '".$serverid."' LIMIT 1" );
		if ($status['status'] != 'Active')
		{
			$_SESSION['msg1'] = 'Validation Error!';
			$_SESSION['msg2'] = 'The server is disabled or pending!';
			$_SESSION['msg-type'] = 'error';
			header( 'Location: index.php' );
			die();
		}
		else
		{
			$server = query_fetch_assoc( "SELECT * FROM `".DBPREFIX."server` WHERE `serverid` = '".$serverid."' LIMIT 1" );
			$box = query_fetch_assoc( "SELECT `ip`, `login`, `password`, `sshport` FROM `".DBPREFIX."box` WHERE `boxid` = '".$server['boxid']."' LIMIT 1" );
			###
			
			// AJOUT A FAIRE ICI
			
/*
			$ssh = new Net_SSH2($box['ip'].':'.$box['sshport']);
			$aes = new Crypt_AES();
			$aes->setKeyLength(256);
			$aes->setKey(CRYPT_KEY);
			if (!$ssh->login($box['login'], $aes->decrypt($box['password'])))
			{
				$_SESSION['msg1'] = 'Connection Error!';
				$_SESSION['msg2'] = 'Unable to connect to box with SSH.';
				$_SESSION['msg-type'] = 'error';
				header( 'Location: index.php' );
				die();
			}

			if (!empty($_GET['cmd']))
			{
				$cmdRcon = $_GET['cmd'];

				//We retrieve the content of the screen
				$cmd = "cd ".$server['homedir']."; cat screenlog.0";
				$outputScreenContent = $ssh->exec($cmd."\n");
				unset($cmd);

				//We retrieve screen name ($session)
				$output = $ssh->exec("screen -ls | grep ".$server['screen']."\n");
				$output = trim($output);
				$session = explode("\t", $output);
				unset($output);

				//We prepare and we send the command into the screen
				$cmd = "screen -S ".$session[0]." -p 0 -X stuff \"".$cmdRcon."\"`echo -ne '\015'`";
				$ssh->exec($cmd."\n");
				unset($cmd);

				//Adding event to the database
				$message = 'RCON command ('.mysql_real_escape_string($cmdRcon).') sent to : '.$server['name'];
				query_basic( "INSERT INTO `".DBPREFIX."log` SET `serverid` = '".$serverid."', `message` = '".$message."', `name` = '".$_SESSION['adminfirstname']." ".$_SESSION['adminlastname']."', `ip` = '".$_SERVER['REMOTE_ADDR']."'" );
				unset($cmdRcon);

				// Check if the output has been updated

				$cmd = "cd ".$server['homedir']."; cat screenlog.0";
				$i = 0; //Security counter

				$updated = FALSE;

				while ($updated != TRUE)
				{
					$output = $ssh->exec($cmd."\n");
					###
					if ((md5($output) != md5($outputScreenContent)) || ($i == 20))
					{
						$outputScreenContent = $output;
						$updated = TRUE;
					}
					###
					sleep(1);
					$i++;
				}

				unset($output, $updated, $cmd);

				header( 'Location: utilitiesrcontool.php?serverid='.urlencode($serverid) );
				die();
			}

			//We retrieve the content of the screen
			$cmd = "cd ".$server['homedir']."; cat screenlog.0";
			$outputScreenContent = $ssh->exec($cmd."\n");
			unset($cmd);
		*/
		}



		include("./bootstrap/header.php");


		/**
		 * Notifications
		 */
		if (isset($_SESSION['msg1']) && isset($_SESSION['msg2']) && isset($_SESSION['msg-type']))
		{
?>
			<div class="alert alert-<?php
			switch ($_SESSION['msg-type'])
			{
				case 'block':
					echo 'block';
					break;

				case 'error':
					echo 'error';
					break;

				case 'success':
					echo 'success';
					break;

				case 'info':
					echo 'info';
					break;
			}
?>">
				<a class="close" data-dismiss="alert">&times;</a>
				<h4 class="alert-heading"><?php echo $_SESSION['msg1']; ?></h4>
				<?php echo $_SESSION['msg2']; ?>
			</div>
<?php
			unset($_SESSION['msg1']);
			unset($_SESSION['msg2']);
			unset($_SESSION['msg-type']);
		}
		/**
		 *
		 */


?>
			<script type="text/javascript">
			$(document).ready(function() {
				// Si besoin de js à inclure...
			});
			</script>
			<div class="page-header">
				<h1><small><?php echo $server['name']; ?></small></h1>
			</div>

				<div class="well">
					<p>WIP... </p>
				</div>
				
				<div style="text-align: center; margin-top: 19px;">
					<ul class="pager">
						<li>
							<a href="utilitieswebftp.php">Back to File Manager Tool Servers List</a>
							<a href="serversummary.php?id=<?php echo $serverid; ?>">Go to Server Summary</a>
						</li>
					</ul>
				</div>
<?php
		break;



//------------------------------------------------------------------------------------------------------------+

}


include("./bootstrap/footer.php");
?>