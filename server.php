<?php
// servidor.php
error_reporting(E_ALL); //configura el nivel de error que el script reportará (TODOS)
set_time_limit(0); //establece el tiempo máximo de ejecución de un script en segundos.
ob_implicit_flush(); //volcado implícito de la salida, envía datos del script mientras se ejecuta, no al terminar

//Apertura el servicio seguro
function ws_handshake($client, $headers)
{
  if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $headers, $matches)) {
    $key = base64_encode(sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $headers = "HTTP/1.1 101 Switching Protocols\r\n";
    $headers .= "Upgrade: websocket\r\n";
    $headers .= "Connection: Upgrade\r\n";
    $headers .= "Sec-WebSocket-Accept: $key\r\n\r\n";
    socket_write($client, $headers);
    return true;
  }
  return false;
}

//Decodificar datos del WS
function ws_decode($data)
{
  if (empty($data) || strlen($data) < 2) {
    return '';  // Retornar una cadena vacía si $data no tiene suficiente longitud
  }

  $length = ord($data[1]) & 127;
  if ($length == 126) {
    $masks = substr($data, 4, 4);
    $data = substr($data, 8);
  } elseif ($length == 127) {
    $masks = substr($data, 10, 4);
    $data = substr($data, 14);
  } else {
    $masks = substr($data, 2, 4);
    $data = substr($data, 6);
  }
  $text = '';
  for ($i = 0; $i < strlen($data); ++$i) {
    $text .= $data[$i] ^ $masks[$i % 4];
  }
  return $text;
}

//Codificar WS
function ws_encode($text)
{
  $b1 = 0x80 | (0x1 & 0x0f);
  $length = strlen($text);

  if ($length <= 125)
    $header = pack('CC', $b1, $length);
  elseif ($length > 125 && $length < 65536)
    $header = pack('CCn', $b1, 126, $length);
  else
    $header = pack('CCNN', $b1, 127, $length);

  return $header . $text;
}

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, '0.0.0.0', 8080);
socket_listen($socket);

$clients = array();
$client_ids = array();
$handshakes = array();
$nicknames = array();

echo "Servidor WebSocket iniciado en el puerto 8080...\n";

while (true) {
  $read = array_merge(array($socket), $clients);
  $write = $except = null;

  if (socket_select($read, $write, $except, null) < 1) {
    continue;
  }

  if (in_array($socket, $read)) {
    $client = socket_accept($socket);
    $clients[] = $client;

    $socket_id = spl_object_hash($client);
    $client_ids[$socket_id] = uniqid('client_');
    $handshakes[$client_ids[$socket_id]] = false;

    echo "Nuevo cliente conectado: " . $client_ids[$socket_id] . "\n";
    $key = array_search($socket, $read);
    unset($read[$key]);
  }

  foreach ($read as $read_socket) {
    $data = @socket_read($read_socket, 2048);
    $socket_id = spl_object_hash($read_socket);

    if ($data === false || $data === '') {
      $client_id = $client_ids[$socket_id];
      if (isset($nicknames[$client_id])) {
        $nickname = $nicknames[$client_id];
        $response = json_encode([
          'type' => 'system',
          'message' => "$nickname se ha desconectado"
        ]);

        foreach ($clients as $client) {
          $current_socket_id = spl_object_hash($client);
          if (
            $client != $read_socket &&
            isset($client_ids[$current_socket_id]) &&
            isset($handshakes[$client_ids[$current_socket_id]]) &&
            $handshakes[$client_ids[$current_socket_id]]
          ) {
            socket_write($client, ws_encode($response));
          }
        }

        echo "$nickname se ha desconectado\n";
        unset($nicknames[$client_id]);
      }

      $key = array_search($read_socket, $clients);
      unset($clients[$key]);
      unset($handshakes[$client_ids[$socket_id]]);
      unset($client_ids[$socket_id]);
      continue;
    }

    $client_id = $client_ids[$socket_id];

    if (!$handshakes[$client_id]) {
      if (ws_handshake($read_socket, $data)) {
        $handshakes[$client_id] = true;
        echo "Handshake completado para cliente: $client_id\n";
      }
      continue;
    }

    $message = ws_decode($data);
    if (!empty($message)) {
      $decoded = json_decode($message, true);

      if ($decoded != null || $decoded != "") {
        if ($decoded['type'] === 'login') {
          $nicknames[$client_id] = $decoded['nickname'];
          $response = json_encode([
            'type' => 'system',
            'message' => "{$decoded['nickname']} se ha unido al chat"
          ]);
          echo "{$decoded['nickname']} se ha unido al chat\n";
        } else {
          $response = json_encode([
            'type' => 'message',
            'nickname' => $nicknames[$client_id],
            'message' => $decoded['message']
          ]);
        }

        foreach ($clients as $client) {
          $current_socket_id = spl_object_hash($client);
          if (
            $client != $read_socket &&
            isset($client_ids[$current_socket_id]) &&
            isset($handshakes[$client_ids[$current_socket_id]]) &&
            $handshakes[$client_ids[$current_socket_id]]
          ) {
            socket_write($client, ws_encode($response));
          }
        }
      }
    }
  }

  // Pequeña pausa para no saturar el CPU
  usleep(100000); //100 ms
}

socket_close($socket);
