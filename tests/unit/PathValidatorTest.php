<?php
use PHPUnit\Framework\TestCase;

class PathValidatorTest extends TestCase
{
    public function testAllowsPathWithinBase(): void
    {
        $this->assertTrue(
            PathValidator::isWithinAllowedBase('C:\\xampp\\htdocs\\hr2000\\uploads', 'C:\\xampp\\htdocs\\hr2000')
        );
    }

    public function testBlocksTraversalAttack(): void
    {
        $this->assertFalse(
            PathValidator::isWithinAllowedBase('C:\\xampp\\htdocs\\hr2000\\..\\brs\\config', 'C:\\xampp\\htdocs\\hr2000')
        );
    }

    public function testBlocksCompletelyDifferentPath(): void
    {
        $this->assertFalse(
            PathValidator::isWithinAllowedBase('C:\\Windows\\System32', 'C:\\xampp\\htdocs')
        );
    }
}
