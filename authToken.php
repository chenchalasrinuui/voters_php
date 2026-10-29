

<?php
  class AuthToken{
      public function validate(){
        // Get JWT Token from Request Headers
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

        // Validate Token
        if (!$token || !JWTHandler::validateToken($token)) {
            echo json_encode(["error" => "Unauthorized"]);
            http_response_code(401);
            exit;
        }
      }
  }
?>
