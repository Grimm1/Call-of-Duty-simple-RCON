<?php

require_once __DIR__ . '/resolveHostname.php';

function queryCodServer($serverId) {
    include __DIR__ . '/../config/database.php';

    // Fetch server details based on the server ID
    $stmt = $conn->prepare("SELECT ip_or_hostname, port FROM game_servers WHERE id = ?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $server = $result->fetch_assoc();
        $ip = resolveHostname($server['ip_or_hostname']);
        if ($ip === false) {
            $error = "Error resolving hostname for server ID $serverId";
            error_log($error);
            return json_encode(['error' => "Error: Unable to resolve hostname."]);
        }
        $port = $server['port'];

        $query = "\xFF\xFF\xFF\xFFgetstatus";
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 2, 'usec' => 0));
    
        if (!$socket) {
            throw new Exception("Could not create socket");
        }

        if (!socket_sendto($socket, $query, strlen($query), 0, $ip, $port)) {
            socket_close($socket);
            throw new Exception("Could not send data to server");
        }

        $buffer = '';
        $remote_addr = '';
        $remote_port = 0;
        if (socket_recvfrom($socket, $buffer, 4096, 0, $remote_addr, $remote_port) === false) {
            socket_close($socket);
            throw new Exception("No response from server");
        }
        socket_close($socket);

        // Process the response
        $buffer = substr($buffer, 4); // Remove the initial 4 bytes
        $buffer = str_replace('statusResponse', '', $buffer);

        $lines = explode("\n", $buffer);
        $serverInfo = [];
        $players = [];

        $playerIdCounter = 0; // Counter for player IDs

        foreach ($lines as $line) {
            if (strpos($line, "\\") !== false) {
                // Server info
                $infoParts = explode("\\", $line);
                for ($i = 1; $i < count($infoParts); $i += 2) {
                    $key = $infoParts[$i];
                    $value = $infoParts[$i + 1];
                    if (in_array($key, ['g_gametype', 'gamename', 'mapname', 'sv_hostname', 'sv_maxclients'])) {
                        $serverInfo[$key] = $value;
                    }
                }
            } else {
                // Player info - assuming format is Score, Ping, Name
                $playerParts = preg_split('/\s+/', trim($line), 3);
                if (count($playerParts) >= 3) {
                    $players[$playerIdCounter] = [
                        'score' => $playerParts[0],
                        'ping' => $playerParts[1],
                        'name' => $playerParts[2]
                    ];
                    $playerIdCounter++; // Increment player ID
                }
            }
        }

        // Construct JSON response
        $response = [
            'serverInfo' => $serverInfo,
            'players' => $players
        ];

        return json_encode($response);
    } else {
        throw new Exception("Server not found with ID: $serverId");
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['serverId'])) {
    $serverId = intval($_POST['serverId']);
    try {
        header('Content-Type: application/json');
        echo queryCodServer($serverId);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/* 
returns this format
Server Information:
g_gametype: dm
gamename: Call of Duty
mapname: mp_dawnville
sv_hostname: ^1Grimms ^7Deathmatch
sv_maxclients: 16

Players:
[0] => Score: 0, Ping: 0, Name: "Unknown Soldier" 

then converts to json*/

?>

