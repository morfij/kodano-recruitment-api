<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Enum\ProductOperation;
use App\Message\ProductNotification;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Append-only record of a product change. product_id has no foreign key
 * so the entry outlives the deleted product. Exposed read-only; entries are
 * written by the product state processor only.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['operation'])]
#[ORM\Index(columns: ['product_id'])]
#[ORM\Index(columns: ['created_at'])]
#[ApiResource(
    operations: [
        new GetCollection(parameters: [
            'productId' => new QueryParameter(filter: new ExactFilter(), schema: ['type' => 'integer'], castToNativeType: true),
            'operation' => new QueryParameter(filter: new ExactFilter(), schema: ['type' => 'string', 'enum' => ['created', 'updated', 'deleted']]),
            'createdAt' => new QueryParameter(filter: new DateFilter()),
        ]),
        new Get(),
    ],
    description: 'Log of product changes (created, updated, deleted), newest first. Read-only.',
    normalizationContext: ['groups' => ['operation_log:read']],
    order: ['id' => 'DESC'],
)]
class OperationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['operation_log:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ProductOperation::class)]
    #[Groups(['operation_log:read'])]
    private ProductOperation $operation;

    #[ORM\Column]
    #[Groups(['operation_log:read'])]
    private int $productId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['operation_log:read'])]
    private array $payload;

    #[ORM\Column]
    #[Groups(['operation_log:read'])]
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

    public static function fromNotification(ProductNotification $notification): self
    {
        return new self($notification->operation, $notification->productId, [
            'name' => $notification->name,
            'price' => $notification->price,
            'categories' => $notification->categoryCodes,
        ]);
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
