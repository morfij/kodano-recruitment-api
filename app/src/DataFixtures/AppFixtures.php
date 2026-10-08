<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo data for local use. Saved through Doctrine directly, so no operation log
 * entries or e-mails are produced.
 */
final class AppFixtures extends Fixture
{
    private const CATEGORIES = ['MUGS', 'CUPS', 'PLATES', 'GLASSES'];

    /** name, price, category codes */
    private const PRODUCTS = [
        ['Kubek ceramiczny', '19.99', ['MUGS']],
        ['Kubek termiczny', '49.90', ['MUGS']],
        ['Filiżanka espresso', '12.50', ['CUPS']],
        ['Filiżanka ze spodkiem', '24.00', ['CUPS', 'PLATES']],
        ['Talerz płaski', '15.00', ['PLATES']],
        ['Talerz głęboki', '16.50', ['PLATES']],
        ['Szklanka do wody', '8.99', ['GLASSES']],
        ['Kufel do piwa', '22.00', ['GLASSES', 'MUGS']],
    ];

    public function load(ObjectManager $manager): void
    {
        $categories = [];
        foreach (self::CATEGORIES as $code) {
            $categories[$code] = (new Category())->setCode($code);
            $manager->persist($categories[$code]);
        }

        foreach (self::PRODUCTS as [$name, $price, $codes]) {
            $product = (new Product())->setName($name)->setPrice($price);
            foreach ($codes as $code) {
                $product->addCategory($categories[$code]);
            }
            $manager->persist($product);
        }

        $manager->flush();
    }
}
