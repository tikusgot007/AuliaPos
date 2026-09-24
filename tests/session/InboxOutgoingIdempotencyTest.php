<?php

use Config\Inbox as GatewayInboxConfig;
use App\Controllers\Inbox;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
final class InboxOutgoingIdempotencyTest extends CIUnitTestCase
{
    private ?Inbox $controller = null;

    /** @var list<string> Temp files created by the media upload double. */
    private array $tempMediaFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);
        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
        $db->table('gateway_status')->insert([
            'id' => 1,
            'status' => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->delete(['id' => 7]);
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Test Kasir', 'username' => 'kasir']);

        $_SESSION = [
            'id_user' => 7,
            'role' => 'kasir',
        ];
    }

    protected function tearDown(): void
    {
        $this->controller = null;
        unset($_SESSION);

        foreach ($this->tempMediaFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempMediaFiles = [];

        parent::tearDown();
    }

    public function testSuccessfulSendForwardsOperationIdAndDeduplicatesReplay(): void
    {
        $conversationId = $this->seedConversation();
        $operationId = 'client-operation-017';
        $controller = $this->controllerWithOperationId($operationId);
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $first = $method->invoke($controller, $this->conversation($conversationId), 'Hello once');
        $firstBody = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($operationId, $controller->capturedOperationId);
        $this->assertSame('sent', $firstBody['message']['send_status']);
        $this->assertSame($operationId, $firstBody['message']['gateway_operation_id']);
        $this->assertSame(1, $this->messageCount($operationId));

        $second = $method->invoke($controller, $this->conversation($conversationId), 'Hello once');
        $secondBody = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $second->getStatusCode());
        $this->assertTrue($secondBody['replayed']);
        $this->assertSame($firstBody['message']['id'], $secondBody['message']['id']);
        $this->assertSame(1, $this->messageCount($operationId));
    }

    public function testMediaSendDeduplicatesReplayWithoutSecondInsert(): void
    {
        $conversationId = $this->seedConversation();
        $operationId    = 'client-media-operation-017';
        $controller     = $this->controllerForMedia($operationId, $conversationId, $this->fakeUploadedMedia());
        $method         = (new ReflectionClass($controller))->getMethod('kirimMedia');
        $method->setAccessible(true);

        $first     = $method->invoke($controller);
        $firstBody = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($operationId, $controller->capturedOperationId);
        $this->assertSame($operationId, $firstBody['message']['gateway_operation_id']);
        $this->assertSame('sent', $firstBody['message']['send_status']);
        $this->assertSame('image', $firstBody['message']['message_type']);
        $this->assertSame(1, $this->messageCount($operationId));

        // F-03: a Gateway replay for the same key must return the stored row
        // instead of inserting a second one (the UNIQUE index would reject
        // it and the cashier would see HTTP 500 for a message that the
        // customer already received).
        $second     = $method->invoke($controller);
        $secondBody = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $second->getStatusCode());
        $this->assertTrue($secondBody['replayed']);
        $this->assertSame($firstBody['message']['id'], $secondBody['message']['id']);
        $this->assertSame(1, $this->messageCount($operationId));
    }

    public function testSendWithoutOperationIdDoesNotCreateServerSideKey(): void
    {
        $conversationId = $this->seedConversation();
        $controller = $this->controllerWithOperationId(null);
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $this->conversation($conversationId), 'Legacy send');
        $body = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($controller->capturedOperationId);
        $this->assertNull($body['message']['gateway_operation_id']);
        $this->assertSame(1, db_connect('inbox')->table('messages')->where('gateway_operation_id', null)->countAllResults());
    }

    /**
     * @dataProvider ambiguousGatewayResponseProvider
     */
    public function testAmbiguousGatewayResponseDoesNotInsertSuccessfulMessage(string $errorCode, int $httpCode, string $state): void
    {
        $conversationId = $this->seedConversation();
        $controller = $this->controllerWithOperationId('operation-ambiguous');
        $controller->gatewayResult = [
            'ok' => false, 'error' => 'ambiguous', 'error_code' => $errorCode,
            'state' => $state, 'replayed' => false, 'http_code' => $httpCode,
        ];
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $this->conversation($conversationId), 'Do not duplicate');
        $body = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($httpCode, $response->getStatusCode());
        $this->assertTrue($body['uncertain']);
        $this->assertSame($errorCode, $body['error_code']);
        $this->assertSame($state, $body['state']);
        $this->assertStringContainsString('jangan kirim ulang dulu', $body['message']);
        $this->assertSame(0, $this->messageCount('operation-ambiguous'));
    }

    public static function ambiguousGatewayResponseProvider(): array
    {
        return [
            ['SEND_IN_PROGRESS', 409, 'in_flight'],
            ['SEND_UNRESOLVED', 504, 'failed'],
        ];
    }

    public function testReusedOperationIdRequestsNewClientKey(): void
    {
        $conversationId = $this->seedConversation();
        $controller = $this->controllerWithOperationId('reused-operation');
        $controller->gatewayResult = [
            'ok' => false, 'error' => 'reused', 'error_code' => 'OPERATION_ID_REUSED',
            'state' => 'sent', 'replayed' => false, 'http_code' => 409,
        ];
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $this->conversation($conversationId), 'Retry with a new key');
        $body = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($body['new_key_required']);
        $this->assertSame('OPERATION_ID_REUSED', $body['error_code']);
        $this->assertSame(0, $this->messageCount('reused-operation'));
    }

    /**
     * @dataProvider ordinaryFailureProvider
     */
    public function testOrdinaryGatewayFailureIsNotReportedAsUncertain(string $errorCode): void
    {
        $conversationId = $this->seedConversation();
        $controller = $this->controllerWithOperationId('ordinary-operation');
        $controller->gatewayResult = [
            'ok' => false, 'error' => 'gateway unavailable', 'error_code' => $errorCode,
            'state' => 'failed', 'replayed' => false, 'http_code' => 409,
        ];
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $this->conversation($conversationId), 'Ordinary failure');
        $body = json_decode($this->responseBody($controller), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertArrayNotHasKey('uncertain', $body);
        $this->assertSame($errorCode, $body['error_code']);
        $this->assertStringContainsString('Gagal mengirim pesan', $body['message']);
        $this->assertSame(0, $this->messageCount('ordinary-operation'));
    }

    public static function ordinaryFailureProvider(): array
    {
        return [['NOT_CONNECTED'], ['DEAD_LETTERED']];
    }


    private function controllerForMedia(?string $operationId, int $conversationId, UploadedFile $file): InboxGatewayIdempotencySpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());

        $post = ['conversation_id' => (string) $conversationId, 'caption' => ''];
        if ($operationId !== null) {
            $post['operation_id'] = $operationId;
        }
        $request->setGlobal('post', $post);

        // CI4's UploadedFile::isValid() calls is_uploaded_file(), which is
        // always false under CLI, so kirimMedia() would reject every fixture.
        // The request therefore gets a FileCollection holding a test double
        // that only relaxes isValid(); every other file method keeps its real
        // framework behaviour (finfo mime detection included).
        $collection    = new FileCollection();
        $filesProperty = new \ReflectionProperty($collection, 'files');
        $filesProperty->setAccessible(true);
        $filesProperty->setValue($collection, ['media' => $file]);

        $requestFiles = new \ReflectionProperty($request, 'files');
        $requestFiles->setAccessible(true);
        $requestFiles->setValue($request, $collection);

        $controller = new InboxGatewayIdempotencySpy($operationId);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function fakeUploadedMedia(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aulia-media-');
        $this->assertIsString($path);

        // A real (minimal 1x1) PNG, so finfo detects image/png exactly like a
        // genuine cashier upload and kirimMedia() classifies it as 'image'.
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/wD/AH8AAAAASUVORK5CYII='));
        $this->tempMediaFiles[] = $path;

        return new InboxTestUploadedMedia($path, 'test.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function controllerWithOperationId(?string $operationId): InboxGatewayIdempotencySpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $operationId === null ? [] : ['operation_id' => $operationId]);
        $controller = new InboxGatewayIdempotencySpy($operationId);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function responseBody(Inbox $controller): string
    {
        $property = (new ReflectionClass($controller))->getParentClass()->getProperty('response');
        $property->setAccessible(true);

        return $property->getValue($controller)->getBody();
    }

    private function seedConversation(): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id' => '628123456789@s.whatsapp.net',
            'jid_type' => 'pn',
            'status' => 'open',
            'assigned_to' => 7,
            'last_message_direction' => 'incoming',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function conversation(int $id): array
    {
        $rows = db_connect('inbox')->table('conversations')->where('id', $id)->get()->getResultArray();

        return $rows[0];
    }

    private function messageCount(string $operationId): int
    {
        return db_connect('inbox')->table('messages')->where('gateway_operation_id', $operationId)->countAllResults();
    }
}

final class InboxGatewayIdempotencySpy extends Inbox
{
    public ?string $capturedOperationId = null;
    public array $gatewayResult = [];

    public function __construct(private readonly ?string $expectedOperationId)
    {
    }

    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null): array
    {
        $this->capturedOperationId = $operationId;
        if ($operationId !== $this->expectedOperationId) {
            throw new RuntimeException('Gateway received an unexpected operation_id.');
        }

        return $this->gatewayResult !== [] ? $this->gatewayResult : [
            'ok' => true,
            'wa_message_id' => 'wa-test-' . $operationId,
        ];
    }

    protected function callGatewaySendMedia(GatewayInboxConfig $config, string $chatId, string $mediaType, string $mediaBase64, ?string $mimetype, ?string $fileName, string $caption, ?string $operationId = null): array
    {
        $this->capturedOperationId = $operationId;
        if ($operationId !== $this->expectedOperationId) {
            throw new RuntimeException('Gateway received an unexpected operation_id.');
        }

        return $this->gatewayResult !== [] ? $this->gatewayResult : [
            'ok'            => true,
            'wa_message_id' => 'wa-media-test-' . $operationId,
            'media_ref'     => null,
            'error_code'    => null,
            'state'         => 'sent',
            'replayed'      => false,
        ];
    }
}

/**
 * Test-only upload whose isValid() no longer requires a real HTTP upload.
 *
 * CodeIgniter's UploadedFile::isValid() is `is_uploaded_file($path) &&
 * $error === UPLOAD_ERR_OK`, and is_uploaded_file() is always false under
 * CLI, so kirimMedia() would reject every test fixture. Only isValid() is
 * relaxed here: getMimeType() (finfo), getSize(), getTempName(), and
 * getClientName() keep the real framework behaviour.
 */
final class InboxTestUploadedMedia extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }
}
