<?php
class AuthToken {
    public function validate() {
        // Get JWT Token from Request Headers
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $token = $authHeader ? str_replace('Bearer ', '', $authHeader) : null;

        // Validate Token
        if (!$token || !JWTHandler::validateToken($token)) {
            require_once __DIR__ . '/cors.php';
            http_response_code(401);
            echo json_encode(["error" => "Unauthorized"]);
            exit;
        }
    }
}
