<?php
require_once 'crest.php';
require_once 'settings.php';

// Функция для отправки HTTP-запроса к FastAPI
function send_to_python($endpoint, $data) {
    $ch = curl_init('http://127.0.0.1:8000' . $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Обработка запросов
$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];

if ($request_method == 'POST' && $request_uri == '/bitrix/send-message') {
    $input = json_decode(file_get_contents('php://input'), true);
    $chat_id = $input['chat_id'];
    $message = $input['message'];

    // Отправка сообщения в Bitrix24 через API
    $result = CRest::call('im.message.add', [
        'CHAT_ID' => $chat_id,
        'MESSAGE' => $message
    ]);

    // Отправка данных в Python FastAPI
    $python_response = send_to_python('/bitrix/message', [
        'chat_number' => $chat_id,
        'message' => $message
    ]);

    echo json_encode([
        'status' => $result['result'] ? 'ok' : 'error',
        'bitrix_response' => $result,
        'python_response' => $python_response
    ]);
} elseif ($request_method == 'GET' && $request_uri == '/bitrix/chats') {
    // Получение списка чатов из Bitrix24
    $result = CRest::call('im.chat.get', []);

    // Отправка данных в Python FastAPI
    $python_response = send_to_python('/bitrix/chats', [
        'chats' => $result['result']
    ]);

    echo json_encode([
        'status' => $result['result'] ? 'ok' : 'error',
        'chats' => $result['result'],
        'python_response' => $python_response
    ]);
} else {
    echo json_encode(['error' => 'Invalid request']);
}