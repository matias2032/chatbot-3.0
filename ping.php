<?php
// ============================================================
//  ping.php — Endpoint para cronjob / keep-alive
// ============================================================

http_response_code(200);
header('Content-Type: application/json');

echo json_encode(['status' => 'ok', 'ts' => time()]);
