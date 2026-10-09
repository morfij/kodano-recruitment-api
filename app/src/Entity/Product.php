<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\ProductStateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(openapi: new OpenApiOperation(responses: [
            '409' => new OpenApiResponse('The product was changed by another request in the meantime'),
        ])),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['product:read']],
    denormalizationContext: ['groups' => ['product:write']],
    order: ['id' => 'ASC'],
    processor: ProductStateProcessor::class,
)]
class Product
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    #[Groups(['product:read', 'product:write'])]
    private ?string $name = null;

    /**
     * Decimal kept as a string to avoid float rounding. The pattern also bounds the
     * range to 0..99999999.99, matching DECIMAL(10,2).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^\d{1,8}(\.\d{1,2})?$/D',
        message: 'Price must be a decimal string with up to 8 digits before the point and up to 2 after it, e.g. "19.99".',
    )]
    #[Groups(['product:read', 'product:write'])]
    private ?string $price = null;

    /**
     * Deleting a product drops its links; a category stays undeletable while linked.
     *
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'product_category')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'RESTRICT')]
    #[Assert\Count(min: 1)]
    #[Groups(['product:read', 'product:write'])]
    #[ApiProperty(
        readableLink: false,
        writableLink: false,
        schema: ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [1, 2]],
    )]
    private Collection $categories;

    /**
     * Two PATCH requests that read the same categories could otherwise each remove a
     * different one and leave none. The version is bumped on every update, because
     * the processor touches updatedAt, so a stale write fails with 409.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }
}
