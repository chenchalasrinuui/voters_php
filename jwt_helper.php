<?php
require_once 'vendor/autoload.php'; // Include Firebase JWT
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHandler {
    private static $secret_key = "your_secret_key"; // Change this to a secure key

    public static function generateToken($userId) {
        $payload = [
            'iss' => "yourdomain.com",
            'iat' => time(),
            'exp' => time() + (24* 60 * 60), // Token expires in 1 hour
            'userId' => $userId
        ];
        return JWT::encode($payload, self::$secret_key, 'HS256');
    }

    public static function validateToken($token) {
        try {
            $decoded = JWT::decode($token, new Key(self::$secret_key, 'HS256'));
            return $decoded;
        } catch (Exception $e) {
            return false; // Invalid token
        }
    }
}
?>
