<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Category;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * UniqueEntity cannot see a code saved by a concurrent request after validation.
 * The UNIQUE index stops the duplicate; this turns its error into the same
 * violation instead of a response carrying the SQL error.
 *
 * @implements ProcessorInterface<Category, Category>
 */
final class CategoryPersistProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Category, Category> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Category
    {
        try {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        } catch (UniqueConstraintViolationException) {
            $message = (new UniqueEntity(fields: ['code']))->message;
            $violation = new ConstraintViolation($message, $message, [], $data, 'code', $data->getCode(), code: UniqueEntity::NOT_UNIQUE_ERROR);

            throw new ValidationException(new ConstraintViolationList([$violation]));
        }
    }
}
