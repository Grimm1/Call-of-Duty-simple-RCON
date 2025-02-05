<?php
$rootDir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $rootDir . 'config/database.php';
include __DIR__ . '/resolveHostname.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $serverId = $_POST['server'];
    $command = $_POST['command'];
    echo sendRconCommand($serverId, $command);
}

function sendRconCommand($serverId, $command) {
    global $conn;

    // $debugLogFile = __DIR__ . '/rcon_function_debug.log';
    // $logMessage = "Attempting RCON command for server ID: $serverId with command: $command\n";
    // file_put_contents($debugLogFile, $logMessage, FILE_APPEND);

    $stmt = $conn->prepare("SELECT ip_or_hostname, port, rcon_password FROM game_servers WHERE id = ?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $server = $result->fetch_assoc();
        $ip = resolveHostname($server['ip_or_hostname']);
        if (!$ip) {
            $error = "Error resolving hostname for server ID $serverId";
            error_log($error);
            // file_put_contents($debugLogFile, "$error\n", FILE_APPEND);
            return "Error: Unable to resolve hostname.";
        }
        $port = $server['port'];
        $password = $server['rcon_password'];

        // $logMessage = "Server details - IP: $ip, Port: $port\n";
        // file_put_contents($debugLogFile, $logMessage, FILE_APPEND);

        $context = stream_context_create(array(
            'socket' => array(
                'timeout' => .5
            )
        ));

        $sock = @stream_socket_client("udp://$ip:$port", $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
        if (!$sock) {
            $error = "Failed to connect to server $ip:$port - Error $errno: $errstr";
            error_log($error);
            // file_put_contents($debugLogFile, "$error\n", FILE_APPEND);
            return "Error: Server is offline or not responding.";
        }

        $command = "\xff\xff\xff\xffrcon " . $password . " " . $command;
        fwrite($sock, $command);

        stream_set_timeout($sock, 1);
        $response = '';
        $readStart = microtime(true);
        while ((microtime(true) - $readStart) < 2) {
            $response .= fread($sock, 2048);
            if (feof($sock)) break; 
        }
        fclose($sock);

        $response = str_replace("\xff\xff\xff\xffprint\n", '', $response);
        // $logMessage = "RCON response: $response\n";
        // file_put_contents($debugLogFile, $logMessage, FILE_APPEND);

        return $response;
    } else {
        $error = "Server with ID $serverId not found in the database";
        error_log($error);
        // file_put_contents($debugLogFile, "$error\n", FILE_APPEND);
        return "Error: Server not found.";
    }
}
?>

