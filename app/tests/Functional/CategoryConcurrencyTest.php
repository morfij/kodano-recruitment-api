<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Category;
use App\State\CategoryPersistProcessor;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A concurrent request saves the same code after this one passed UniqueEntity.
 */
final class CategoryConcurrencyTest extends KernelTestCase
{
    public function testDuplicateCodeFromConcurrentRequestIsAValidationError(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO category (code, created_at, updated_at) VALUES ('MUGS', NOW(), NOW())");

        try {
            static::getContainer()->get(CategoryPersistProcessor::class)->process((new Category())->setCode('MUGS'), new Post());
            self::fail('A duplicate code must be rejected.');
        } catch (ValidationException $e) {
            $violation = $e->getConstraintViolationList()->get(0);
            self::assertSame('code', $violation->getPropertyPath());
            self::assertSame(UniqueEntity::NOT_UNIQUE_ERROR, $violation->getCode());
            self::assertStringNotContainsString('SQLSTATE', (string) $e);
        }

        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM category WHERE code = 'MUGS'"));
    }
}
