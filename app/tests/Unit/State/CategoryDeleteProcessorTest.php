<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Category;
use App\State\CategoryDeleteProcessor;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CategoryDeleteProcessorTest extends TestCase
{
    public function testProductLinkedAfterTheCheckGivesConflict(): void
    {
        $query = $this->createStub(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(0);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);

        $removeProcessor = $this->createStub(ProcessorInterface::class);
        $removeProcessor->method('process')->willThrowException(
            new ForeignKeyConstraintViolationException($this->createStub(DriverException::class), null),
        );

        $this->expectException(ConflictHttpException::class);

        (new CategoryDeleteProcessor($removeProcessor, $entityManager))
            ->process((new Category())->setCode('MUGS'), new Delete());
    }
}
