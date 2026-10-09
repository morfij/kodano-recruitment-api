<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Represents a product's categories as category IDs in both directions. API Platform
 * links resources by IRI and does not allow a relation to be a plain number, so the
 * product is (de)normalized as usual and only its categories are swapped.
 */
final class ProductCategoriesNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'product_categories_normalizer';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Product && 'json' === $format && !isset($context[self::ALREADY_CALLED]);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        \assert($data instanceof Product);
        $normalized = $this->normalizer->normalize($data, $format, [self::ALREADY_CALLED => true] + $context);

        if (\is_array($normalized) && \array_key_exists('categories', $normalized)) {
            $normalized['categories'] = array_map(static fn (Category $category): ?int => $category->getId(), $data->getCategories()->getValues());
        }

        return $normalized;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Product::class === $type && 'json' === $format && \is_array($data) && !isset($context[self::ALREADY_CALLED]);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Product
    {
        \assert(\is_array($data));
        $categories = null;
        if (\array_key_exists('categories', $data)) {
            $categories = $this->findCategories($data['categories']);
            unset($data['categories']);
        }

        $product = $this->denormalizer->denormalize($data, $type, $format, [self::ALREADY_CALLED => true] + $context);
        \assert($product instanceof Product);

        if (null !== $categories) {
            foreach ($product->getCategories()->getValues() as $category) {
                if (!\in_array($category, $categories, true)) {
                    $product->removeCategory($category);
                }
            }
            foreach ($categories as $category) {
                $product->addCategory($category);
            }
        }

        return $product;
    }

    public function getSupportedTypes(?string $format): array
    {
        return 'json' === $format ? [Product::class => false] : [];
    }

    /**
     * An empty list or null passes here and is rejected by the Count constraint on Product.
     *
     * @return list<Category>
     */
    private function findCategories(mixed $ids): array
    {
        $ids ??= [];
        if (!\is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn (mixed $id): bool => !\is_int($id))) {
            throw $this->violation('This value should be a list of category IDs.', $ids, Type::INVALID_TYPE_ERROR);
        }

        $ids = array_values(array_unique($ids));
        $found = [];
        foreach ($this->entityManager->getRepository(Category::class)->findBy(['id' => $ids]) as $category) {
            $found[$category->getId()] = $category;
        }

        $missing = array_diff($ids, array_keys($found));
        if ([] !== $missing) {
            throw $this->violation(\sprintf('Category %s does not exist.', implode(', ', $missing)), $ids, Choice::NO_SUCH_CHOICE_ERROR);
        }

        return array_map(static fn (int $id): Category => $found[$id], $ids);
    }

    private function violation(string $message, mixed $value, string $code): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], null, 'categories', $value, code: $code),
        ]));
    }
}
