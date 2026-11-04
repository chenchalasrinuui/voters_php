<?php

function requireSuperAdminSession(PDO $pdo)
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{48})$/i', $authorization, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'A valid super-admin login session is required.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $session = $pdo->prepare("
            SELECT account_id
            FROM auth_sessions
            WHERE token_hash = ? AND account_type = 'admin' AND expires_at > NOW()
            LIMIT 1
        ");
        $session->execute([hash('sha256', $matches[1])]);
        $accountId = (int)$session->fetchColumn();
        if ($accountId <= 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Only the super-admin account that created this user can perform this action.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $admin = $pdo->prepare("SELECT id FROM admin WHERE id = ? LIMIT 1");
        $admin->execute([$accountId]);
        if (!$admin->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'The super-admin session is no longer valid.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        return $accountId;
    } catch (Throwable $e) {
        error_log("Super-admin session verification failed: " . $e->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Unable to verify the super-admin session.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
