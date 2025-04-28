<html>

<head>
    <title>Index of /logfiles/pelletronic/</title>
    <style>
        th {
            text-align: left;
        }
    </style>
</head>

<body>
    <h1>Index of /logfiles/pelletronic/</h1>
    <pre>
        <table cellpadding="0">
            <tbody>
                <tr><th><a href="?nd">Name</a>
                </th><th><a href="?dd">Modified</a></th><th><a href="?sd">Size</a></th></tr>
                <tr><td colspan="3"><hr></td></tr>
                <tr><td><a href="/logfiles/pelletronic/..">Parent directory</a></td><td>&nbsp;-</td><td>&nbsp;&nbsp;-</td></tr>
<?php 
ini_set('display_errors', 1);
error_reporting(E_ALL);


$origFile = file_get_contents('http://192.168.1.41/logfiles/pelletronic/');

$lines = explode("\n", $origFile);

foreach ($lines as $line) {
    if (preg_match('/<a href="([^"]+\.csv)">([^<]+)<\/a>/', $line, $matches)) {
        $filename = $matches[1];
        $localfilename = basename($filename);
        
        copy ('http://192.168.1.41' . $filename, $localfilename);
        
        if (preg_match('/[0-9]{2}-[a-zA-Z]+-[0-9]{4} [0-9]{2}:[0-9]{2}/', $line, $matches)) 
            $filedate = $matches[0];

        $filesize = filesize($localfilename);

        // <tr><td><a href="/logfiles/pelletronic/touch_20250428.csv">touch_20250428.csv</a></td><td>&nbsp;28-Apr-2025 08:04</td><td>&nbsp;&nbsp;66.4k</td></tr>
        echo "<tr><td><a href=\"/$filename\">$localfilename</a></td><td>&nbsp;$filedate</td><td>&nbsp;&nbsp;" . number_format($filesize / 1024, 1) . "k</td></tr>";
    }
}

// Now remove files older than 7 days
$files = glob('touch_*.csv');
$now = time();
$fourteenDaysAgo = $now - (14 * 24 * 60 * 60);
foreach ($files as $file) {
    if (filemtime($file) < $fourteenDaysAgo) {
        unlink($file);
    }
}

?>
            </tbody>
        </table>
    </pre>
</body>

</html>

