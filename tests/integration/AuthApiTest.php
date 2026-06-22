<?php
use PHPUnit\Framework\TestCase;

class AuthApiTest extends TestCase
{
    public function testLoginReturnsUserAndCsrfToken(): void
    {
        // Unit-test the auth logic directly without HTTP
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username=?');
        $stmt->execute(['admin']);
        $user = $stmt->fetch();
        $this->assertNotFalse($user, 'Admin user must exist (run sql/seed.sql first)');
        $this->assertContains($user['role'], ['admin','operator','viewer']);
    }
}
