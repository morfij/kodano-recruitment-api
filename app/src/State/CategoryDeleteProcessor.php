<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\RemoveProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Deletes a category only when no product uses it, so a product never ends up
 * without a category. The RESTRICT foreign key guards the same rule in the database.
 *
 * @implements ProcessorInterface<Category, null>
 */
final class CategoryDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Category, null> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: RemoveProcessor::class)]
        private readonly ProcessorInterface $removeProcessor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $products = (int) $this->entityManager
            ->createQuery(sprintf('SELECT COUNT(p.id) FROM %s p WHERE :category MEMBER OF p.categories', Product::class))
            ->setParameter('category', $data)
            ->getSingleScalarResult();

        if ($products > 0) {
            throw new ConflictHttpException(sprintf('Category %s is used by %d product(s) and cannot be deleted.', $data->getCode(), $products));
        }

        try {
            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        } catch (ForeignKeyConstraintViolationException) {
            // A product was linked after the check above.
            throw new ConflictHttpException(sprintf('Category %s is used by a product and cannot be deleted.', $data->getCode()));
        }
    }
}
