<?php session_start(); ?>

<!DOCTYPE HTML>
<head>
	<meta http-equiv="content-type" content="text/html" />
	<meta name="author" content="Thomas Malicet" />

	<title>FTPbucket</title>
	<style>
	    .log{
	        font: 10px verdana,sans-serif;
	        width: 100%;
	        border: solid 1px #000;
	        height: 500px;
	        overflow: scroll;
	    }
	    td {
	        padding: 5px;
	    }
	</style>
</head>

<body>

<?php 
    $config = include 'config.php';
    
    if (isset($_GET['pass']) && $_GET['pass'] == $config['admin_pass']){
        $_SESSION['logged'] = 'ok';
        @header('Location: index.php');
    }
    
    if(!isset($_SESSION['logged'])){
?>
        <form action="" method="get">
            <input type="password" name="pass" size="20" />
            <input type="submit" name="submit" value="Login" />
        </form>
<?php
    }else{
        if(isset($_GET['del'])){
            clear_file($_GET['del']);
            header('Location: index.php');
        }
        // Page
        $exp1 = '';
        if(file_exists('logfile.txt')){
            $log1 = file('logfile.txt');
            foreach($log1 as $ln){
                $exp1 .= $ln;
            }
        }
        
        $exp2 = '';
        if(file_exists('logpayload.txt')){
            $log2 = file('logpayload.txt');
            foreach($log2 as $ln){
                $exp2 .= $ln;
            }
        }
        ?>
        
        <table width='100%'>
            <tr><td width='50%'>
                Logs - <a href='?del=log1'>Clear This Logs</a><br />
                <pre class='log'><?php echo $exp1; ?></pre>
            </td><td width='50%'>
                Payload -   <a href='?del=log2'>Clear This Logs</a><br />
                <pre class='log'><?php echo $exp2; ?></pre>
            </td></tr>
        </table>
        
        <?php
    }
?>

</body>
</html>

<?php
function clear_file($f){
    
    if ($f == 'log1'){
        $f = 'logfile.txt';
    } else if ($f == 'log2'){
        $f = 'logpayload.txt';
    } else {
        die();
    }
    
    $f = @fopen($f, "r+");
    if ($f !== false) {
        ftruncate($f, 0);
        fclose($f);
    }
}






