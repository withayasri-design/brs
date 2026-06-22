<?php
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testGetsPdoInstance(): void
    {
        $pdo = Database::pdo();
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testPdoIsSingleton(): void
    {
        $a = Database::pdo();
        $b = Database::pdo();
        $this->assertSame($a, $b);
    }

    public function testQueryExecutes(): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users');
        $stmt->execute();
        $this->assertIsInt((int) $stmt->fetchColumn());
    }
}
