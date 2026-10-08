<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Doctrine\Common\State\RemoveProcessor;
use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\OperationLog;
use App\Entity\Product;
use App\Enum\ProductOperation;
use App\Message\ProductNotification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves the product change and its operation log in one transaction, then
 * dispatches the notification once the transaction has been committed.
 *
 * @implements ProcessorInterface<Product, Product|null>
 */
final class ProductStateProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: RemoveProcessor::class)]
        private readonly ProcessorInterface $removeProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Product
    {
        if ($operation instanceof DeleteOperationInterface) {
            // After removal the product has no id and no categories left.
            $notification = ProductNotification::fromProduct($data, ProductOperation::Deleted);

            $this->entityManager->wrapInTransaction(function () use ($data, $operation, $uriVariables, $context, $notification): void {
                $this->removeProcessor->process($data, $operation, $uriVariables, $context);
                $this->entityManager->persist(OperationLog::fromNotification($notification));
            });
            $this->messageBus->dispatch($notification);

            return null;
        }

        $productOperation = $operation instanceof Post ? ProductOperation::Created : ProductOperation::Updated;

        [$product, $notification] = $this->entityManager->wrapInTransaction(
            function () use ($data, $operation, $uriVariables, $context, $productOperation): array {
                if (ProductOperation::Updated === $productOperation) {
                    // Every successful PATCH counts as an update, even one that changes no value.
                    $data->touch();
                }

                $product = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
                $notification = ProductNotification::fromProduct($product, $productOperation);
                $this->entityManager->persist(OperationLog::fromNotification($notification));

                return [$product, $notification];
            },
        );
        $this->messageBus->dispatch($notification);

        return $product;
    }
}
