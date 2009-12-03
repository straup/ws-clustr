<?php

	###########################################################

	define('BIN_CLUSTR', '/usr/bin/clustr');
	define('BIN_TAR', '/bin/tar');
	define('PATH_TMP', sys_get_temp_dir());

	###########################################################

	#
	# Some days I hate the Internet...
	#

	$headers = getallheaders();

	foreach ($headers as $key => $val){
		if (preg_match("/^x-clustr/i", $key)){
			$headers[strtolower($key)] = $val;
			unset($headers[$key]);
		}
	}

	#
	# Do I have points?
	#

       	if ($cache = $headers['x-clustr-cache']){

               	$cache = PATH_TMP . '/' . $cache;

                if (! file_exists($cache)){
                       	clustr_not_found();
                }     
                        
		$path_points = $cache;
	}

        else {

		$data = file_get_contents("php://input");

                if (! $data){
                	usage();
                        exit;
		}

                # 

		$path_points = write_points($data);          
	}

	#
	# Any process specific details?
	#

	$alpha = 0.01;
	$clustr_name = "clustr-" . getmypid();

	if (isset($headers['x-clustr-alpha'])){
		$alpha = floatval($headers['x-clustr-alpha']);
	}

	if (isset($headers['x-clustr-name'])){

		$clustr_name = $headers['x-clustr-name'];

		if (! preg_match("/^[a-z0-9-]+$/i", $clustr_name)){
			clustr_error("Not a valid clustr name");
		}
	}

	#
	# Generate the shapefile
	#

	$shproot = clustrize($path_points, $alpha, $clustr_name);

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

	$fname = basename($gz);

	$enc_fname = htmlspecialchars($fname);
	$enc_alpha = htmlspecialchars($alpha);
	
        header("Content-Type: application/gzip");
        header("Content-Disposition: attachment; filename={$enc_fname};" );
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . filesize($gz));
	header("X-clustr-filename: {$enc_fname}");
	header("X-clustr-alpha: {$enc_alpha}");

        readfile($gz);

	unlink($gz);
	exit;

	###########################################################

	function write_points (&$data){

		$tmp = tempnam(PATH_TMP, "clustr-" . getmypid());

		$fh = fopen($tmp, "w");
		fwrite($fh, $data);
		fclose($fh);

                $fname = PATH_TMP . '/clustr-' . md5_file($tmp);

                rename($tmp, $fname);
		return $fname;
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
                echo "<li><strong>x-clustr-cache</strong>. Use this header to ask ws-clustr to look for, and use, a previously cached version of the points file you want to clustr (rather than sending the whole thing to the server again and again). The value should be: <q>clustr-</q> + the value of <em>md5sum(/path/to/points.txt)</em>. If the cache file is not found on the server ws-clustr will return an HTTP 404 error. It is left to client applications to decide what to do in those circumstances. (It is also left to people running a ws-clustr to periodically clean out their system's tmp directory where the cache files are stored.)</li>";
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

	function clustr_not_found ($msg=''){
		header("HTTP/1.1 404 File Not Found");

		if ($msg){
			echo $msg;
		}

		exit;
	}

	###########################################################
?>
