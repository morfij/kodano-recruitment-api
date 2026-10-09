<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FailingNotificationChannel;
use App\Tests\Support\FailingOperationLogListener;
use Symfony\Component\Mime\RawMessage;

final class ProductNotificationTest extends ApiTestCase
{
    public function testCreateWritesLogAndSendsEmail(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');

        $response = $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => [$mugs, $cups],
        ]]);

        self::assertResponseStatusCodeSame(201);
        $id = $response->toArray()['id'];
        self::assertSame([['created', $id, ['name' => 'Kubek', 'price' => '19.99', 'categories' => ['MUGS', 'CUPS']]]], $this->logEntries());

        self::assertEmailCount(1);
        $email = $this->sentEmail();
        self::assertEmailHeaderSame($email, 'Subject', sprintf('Product created: Kubek (#%d)', $id));
        self::assertEmailAddressContains($email, 'From', 'noreply@example.com');
        self::assertEmailAddressContains($email, 'To', 'catalog@example.com');
        foreach (['Kubek', '19.99', 'MUGS, CUPS'] as $text) {
            self::assertEmailHtmlBodyContains($email, $text);
            self::assertEmailTextBodyContains($email, $text);
        }
    }

    public function testUpdateWritesLogAndSendsEmail(): void
    {
        [$uri, $id] = $this->createKubek();

        $this->patch($uri, ['name' => 'Kubek XL']);

        self::assertResponseIsSuccessful();
        self::assertSame(['updated', $id, ['name' => 'Kubek XL', 'price' => '19.99', 'categories' => ['MUGS']]], $this->logEntries()[1]);
        self::assertEmailCount(1);
        self::assertEmailHeaderSame($this->sentEmail(), 'Subject', sprintf('Product updated: Kubek XL (#%d)', $id));
    }

    public function testDeleteWritesLogAndSendsEmail(): void
    {
        [$uri, $id] = $this->createKubek();

        $this->client->request('DELETE', $uri);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->rowCount('product'));
        self::assertSame(['deleted', $id, ['name' => 'Kubek', 'price' => '19.99', 'categories' => ['MUGS']]], $this->logEntries()[1]);
        self::assertEmailCount(1);
        $email = $this->sentEmail();
        self::assertEmailHeaderSame($email, 'Subject', sprintf('Product deleted: Kubek (#%d)', $id));
        self::assertEmailTextBodyContains($email, 'MUGS');
    }

    public function testValidationErrorHasNoSideEffects(): void
    {
        [$uri] = $this->createKubek();

        $this->client->request('POST', '/api/products', ['json' => ['name' => 'Talerz', 'price' => '7.50']]);
        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);

        $this->patch($uri, ['price' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);

        self::assertCount(1, $this->logEntries());
    }

    public function testLogFailureRollsBackProductChange(): void
    {
        $this->client->disableReboot();
        [$uri, $id] = $this->createKubek();
        $failingLog = static::getContainer()->get(FailingOperationLogListener::class);

        $failingLog->failNextPersist();
        $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Talerz',
            'price' => '7.50',
            'categories' => [$this->categoryId('MUGS')],
        ]]);
        self::assertResponseStatusCodeSame(500);
        self::assertEmailCount(0);

        $failingLog->failNextPersist();
        $this->patch($uri, ['name' => 'Kubek XL']);
        self::assertResponseStatusCodeSame(500);
        self::assertEmailCount(0);

        $failingLog->failNextPersist();
        $this->client->request('DELETE', $uri);
        self::assertResponseStatusCodeSame(500);
        self::assertEmailCount(0);

        $db = $this->db();
        self::assertSame(['Kubek'], $db->fetchFirstColumn('SELECT name FROM product'));
        self::assertSame(1, $this->rowCount('product_category'));
        self::assertSame([['created', $id, ['name' => 'Kubek', 'price' => '19.99', 'categories' => ['MUGS']]]], $this->logEntries());
    }

    public function testChannelFailureDoesNotFailRequest(): void
    {
        $this->client->disableReboot();
        $category = $this->createCategory('MUGS');
        static::getContainer()->get(FailingNotificationChannel::class)->enable();

        $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => [$category],
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->logEntries());
        self::assertEmailCount(1);
    }

    private function sentEmail(): RawMessage
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email);

        return $email;
    }

    /**
     * @return list<array{string, int, array<string, mixed>}>
     */
    private function logEntries(): array
    {
        $rows = $this->db()
            ->fetchAllAssociative('SELECT operation, product_id, payload FROM operation_log ORDER BY id');

        return array_map(
            static fn (array $row): array => [$row['operation'], (int) $row['product_id'], json_decode($row['payload'], true)],
            $rows,
        );
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM $table");
    }

    private function categoryId(string $code): int
    {
        return (int) $this->db()->fetchOne('SELECT id FROM category WHERE code = ?', [$code]);
    }

    /**
     * @return array{string, int}
     */
    private function createKubek(): array
    {
        $id = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        return ['/api/products/'.$id, $id];
    }
}
