<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Metadata\Patch;
use App\Entity\Category;
use App\Entity\Product;
use App\State\ProductStateProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two PATCH requests that read the same product can only be interleaved below the
 * HTTP layer, so the processor gets a product loaded before another request committed.
 */
final class ProductConcurrencyTest extends KernelTestCase
{
    public function testStaleUpdateCannotLeaveProductWithoutCategories(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);

        $mugs = (new Category())->setCode('MUGS');
        $cups = (new Category())->setCode('CUPS');
        $product = (new Product())->setName('Kubek')->setPrice('19.99')->addCategory($mugs)->addCategory($cups);
        $em->persist($mugs);
        $em->persist($cups);
        $em->persist($product);
        $em->flush();

        // Another request replaces [MUGS, CUPS] with [MUGS] and commits first.
        $db->executeStatement('DELETE FROM product_category WHERE product_id = ? AND category_id = ?', [$product->getId(), $cups->getId()]);
        $db->executeStatement('UPDATE product SET version = version + 1 WHERE id = ?', [$product->getId()]);

        // This request still sees [MUGS, CUPS] and replaces it with [CUPS].
        $product->removeCategory($mugs);

        try {
            static::getContainer()->get(ProductStateProcessor::class)->process($product, new Patch());
            self::fail('A stale update must be rejected.');
        } catch (OptimisticLockException) {
        }

        self::assertEquals(
            [$mugs->getId()],
            $db->fetchFirstColumn('SELECT category_id FROM product_category WHERE product_id = ?', [$product->getId()]),
        );
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM operation_log'));
    }
}
