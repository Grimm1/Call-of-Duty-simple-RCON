<?php

function readCustomMaps() {
    $filePath = __DIR__ . '/../gamevars/custom_maps.php';
    $customMaps = [];

    if (file_exists($filePath)) {
        include $filePath;
    }

    return $customMaps;
}

function writeCustomMaps($customMaps) {
    $filePath = __DIR__ . '/../gamevars/custom_maps.php';
    $content = "<?php\n\$customMaps = " . var_export($customMaps, true) . ";\n?>";

    $file = fopen($filePath, 'w');
    if ($file) {
        if (fwrite($file, $content) !== false) {
            fclose($file);
            return true;
        } else {
            fclose($file);
            return false;
        }
    } else {
        return false;
    }
}

function addMap($serverName, $mapName, $mapAlias) {
    if (!empty($serverName) && !empty($mapName) && !empty($mapAlias)) {
        $customMaps = readCustomMaps();
        if (!isset($customMaps[$serverName])) {
            $customMaps[$serverName] = [];
        }
        $customMaps[$serverName][$mapName] = $mapAlias;
        return writeCustomMaps($customMaps);
    }
    return false;
}
?>
