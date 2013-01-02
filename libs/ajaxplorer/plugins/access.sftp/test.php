<?php


include('phpseclib/SFTP.php');

$sftp = new Net_SFTP('192.168.1.100');
if (!$sftp->login('warhawk', 'bhui'))
{
	exit('Login Failed');
}

echo $sftp->pwd() . "\r\n";
$sftp->put('filename.ext', 'hello, world!');
print_r($sftp->nlist());

		/*
		$sftp = new Net_SFTP('192.168.1.100:22');
		if (!$sftp->login('warhawk', 'bhui'))
		{
			throw new Exception("Login Failed!");
		}
		*/

?>