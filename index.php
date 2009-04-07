<?php

	###########################################################

	define('BIN_CLUSTR', '/usr/bin/clustr');
	define('BIN_TAR', '/bin/tar');
	define('PATH_TMP', '/tmp');

	###########################################################

	#
	# Do I have points?
	#

	$data = file_get_contents("php://input"); 

	if (! $data){
		usage();
		exit;
	}

	#
	# Any process specific details?
	#

	$alpha = 0.01;
	$cname = "clustr-" . getmypid();

	$headers = getallheaders();

	if (isset($headers['x-clustr-alpha'])){
		$alpha = floatval($headers['x-clustr-alpha']);
	}

	if (isset($headers['x-clustr-name'])){

		$cname = $headers['x-clustr-name'];

		if (! preg_match("/^[a-z0-9-]+$/i", $cname)){
			clustr_error("Not a valid clustr name");
		}
	}

	# 
	# Write the points to a tmp file
	#

	$tmp = write_points($data);

	if (! $tmp){
		clustr_error("Failed to write tmp file for points");
	}

	#
	# Generate the shapefile
	#

	$shproot = clustrize($tmp, $alpha, $cname);

	if (! $shproot){
		clustr_error("Failed to generate shapefile data");
	}

	#
	# Compress
	#

	$gz = compress($shproot);
	
	if (! $gz){
		clustr_error("Failed to compress shapefile data");
	}

	#
	# Happy happy
	#

        header("Content-Type: application/gzip");
        header("Content-Disposition: attachment; filename=" . basename($gz).";" );
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . filesize($gz));
        readfile($gz);

	unlink($gz);
	exit;

	###########################################################

	function write_points (&$data){
		$tmp = tempnam(PATH_TMP, "clustr");
		$fh = fopen($tmp, "w");
		fwrite($fh, $data);
		fclose($fh);
		return $tmp;
	}

	###########################################################

	function clustrize ($points, $alpha, $name){

		$root = PATH_TMP . "/{$name}";
		$shp = "{$root}/{$name}.shp";

		if (! mkdir($root)){
			return false;
		}

		$cmd = BIN_CLUSTR . " -v -a {$alpha} {$points} {$shp}";
		$out = shell_exec(escapeshellcmd($cmd));

		unlink($points);

		if (! file_exists($shp)){
			return false;
		}

		return $root;
	}

	###########################################################
	
	function compress ($root){

		$parent = dirname($root);
		$name = basename($root);

		$gz = "{$root}.tar.gz";

		$cmd = BIN_TAR . " -C {$parent} -cvzf {$gz} {$name}";
		$out = shell_exec(escapeshellcmd($cmd));

		foreach (array('shp', 'dbf', 'shx') as $ext){
			$shpfile = "{$root}/{$name}.{$ext}";
			unlink($shpfile);
		}

		rmdir($root);
			
		return $gz;
	}

	###########################################################

	function usage (){

		echo "<h2>ws-clustr</h2>";

		echo "<p>ws-clustr is a bare-bones web interface, written in PHP, to the command-line <a href=\"http://code.flickr.com/blog/2008/10/30/the-shape-of-alpha/\">Clustr</a> application.</p>";
		
		echo "<h3>How does it work?</h3>";
		echo "<p>To generate a shapefile using ws-clustr simply send a binary (HTTP) POST containing the points you want to clustrize. ws-clustr will send back a compressed shapefile! For example:</p>";
		echo "<pre>$> curl --data-binary '@/path/to/points.txt' http://example.com/ws-clustr/ > ~/path/to/clustr.tar.gz</pre>";

		echo "<h3>Details</h3>";
		
		echo "<p>The file you pass should be formatted as <code>&lt;tag&gt; &lt;lon&gt; &lt;lat&gt;\n</code>, where tag is any unique string.</p>";
		echo "<p>You may also pass the following HTTP headers with your request:</p>";

		echo "<ul>";
		echo "<li><strong>x-clustr-alpha</strong>.Specify the size of the alpha number to run Clustr with.  The default value is <code>0.01</code></li>";
		echo "<li><strong>x-clustr-name</strong>. Specify the name of the output file to create. Valid names may only contain the characters a-z (case-insensitive), 0-9 and dashes. The default value is <code>clustr-<em>the current process ID</em></code></li>";		
		echo "</ul>";

		echo "<h3>That's it.</h3>";
		echo "<p>There are no ponies. No.</p>";
	}

	###########################################################

	function clustr_error ($msg){

		header("HTTP/1.1 500 Server Error");
		echo $msg;
		exit;
	}

	###########################################################
?>
