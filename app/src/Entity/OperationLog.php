<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProductOperation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only record of a product change. product_id has no foreign key
 * so the entry outlives the deleted product.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['operation'])]
#[ORM\Index(columns: ['product_id'])]
#[ORM\Index(columns: ['created_at'])]
class OperationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ProductOperation::class)]
    private ProductOperation $operation;

    #[ORM\Column]
    private int $productId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(ProductOperation $operation, int $productId, array $payload)
    {
        $this->operation = $operation;
        $this->productId = $productId;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): ProductOperation
    {
        return $this->operation;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
