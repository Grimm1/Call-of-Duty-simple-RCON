<?php
function resolveHostname(string $hostname): string|false {
    $debugFile = __DIR__ . '/debug_resolveHostname.log';
    $debugLog = function($message) use ($debugFile) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($debugFile, "$timestamp - $message\n", FILE_APPEND);
    };

    //$debugLog("Attempting to resolve hostname: $hostname");

    // Check if the provided hostname is already an IP address
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        //$debugLog("Hostname is already an IP address: $hostname");
        return $hostname;
    }

    $ip = gethostbyname($hostname);
    //$debugLog("Resolved IP for $hostname: $ip");

    if ($ip === '127.0.0.1') {
        //$debugLog("IP resolved to localhost, attempting external IP fetch");
        try {
            $externalIp = file_get_contents('http://ipecho.net/plain');
            if ($externalIp !== false) {
                //$debugLog("External IP successfully fetched: $externalIp");
                return $externalIp;
            }
            //$debugLog("Failed to fetch external IP for hostname: $hostname");
        } catch (\Exception $e) {
            //$debugLog("Exception when fetching external IP: " . $e->getMessage());
        }
        // Return localhost if external fetch fails
        return $ip;
    }
    
    // Return the resolved IP if it's not the same as the hostname (successful DNS lookup)
    $returnValue = ($ip !== $hostname) ? $ip : false;
    //$debugLog("Function returning: " . ($returnValue === false ? 'false' : $returnValue));
    return $returnValue;
}
?>
