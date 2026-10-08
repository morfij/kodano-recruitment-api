<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\ProductOperation;

/**
 * Snapshot of a product change. Holds plain values only, so it can be handled
 * after the transaction (or the product itself) is gone.
 */
final readonly class ProductNotification
{
    /**
     * @param list<string> $categoryCodes
     */
    public function __construct(
        public ProductOperation $operation,
        public int $productId,
        public string $name,
        public string $price,
        public array $categoryCodes,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function fromProduct(Product $product, ProductOperation $operation): self
    {
        return new self(
            $operation,
            (int) $product->getId(),
            (string) $product->getName(),
            (string) $product->getPrice(),
            array_values($product->getCategories()->map(static fn (Category $c): string => (string) $c->getCode())->toArray()),
            new \DateTimeImmutable(),
        );
    }
}
