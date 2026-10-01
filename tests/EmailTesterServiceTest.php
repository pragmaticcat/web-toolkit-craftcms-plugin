<?php

namespace pragmatic\webtoolkit\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\controllers\EmailTesterController;
use pragmatic\webtoolkit\domains\emailTester\EmailTesterFeature;
use pragmatic\webtoolkit\domains\emailTester\services\EmailTesterService;
use yii\base\InvalidArgumentException;

class EmailTesterServiceTest extends TestCase
{
    public function testValidatesConfiguration(): void
    {
        $service = new EmailTesterService();
        $result = $service->validateTemplates([
            'welcome' => [
                'label' => 'Welcome',
                'template' => 'emails/welcome',
                'subject' => 'Hello',
                'requiredVariables' => ['user'],
            ],
        ]);

        self::assertSame('emails/welcome', $result['welcome']['template']);
        self::assertSame(['user'], $result['welcome']['requiredVariables']);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testRejectsInvalidConfiguration(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EmailTesterService())->validateTemplates($configuration);
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'missing template' => [['key' => ['label' => 'Label', 'subject' => 'Subject']]],
            'invalid required variables' => [['key' => [
                'label' => 'Label',
                'template' => 'emails/test',
                'subject' => 'Subject',
                'requiredVariables' => 'user',
            ]]],
        ];
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON is invalid');
        (new EmailTesterService())->decodeAdditionalVariables('{invalid');
    }

    public function testRejectsMissingRequiredVariables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('user');
        (new EmailTesterService())->validateRequiredVariables(['user'], []);
    }

    public function testNormalizesTemplateRowsFromTheUi(): void
    {
        $templates = (new EmailTesterService())->normalizeTemplateRows([[
            'key' => 'orderReady',
            'label' => 'Order ready',
            'template' => 'emails/order-ready',
            'subject' => 'Your order is ready',
            'requiredVariables' => 'user, order, user',
        ]]);

        self::assertSame(['user', 'order'], $templates['orderReady']['requiredVariables']);
    }

    public function testRejectsDuplicateUiKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicated');
        (new EmailTesterService())->normalizeTemplateRows([
            ['key' => 'welcome'],
            ['key' => 'welcome'],
        ]);
    }

    public function testControllerUsesDedicatedPermission(): void
    {
        self::assertSame('pragmaticWebToolkit:useEmailTester', EmailTesterController::PERMISSION);
        self::assertArrayHasKey(
            EmailTesterController::PERMISSION,
            (new EmailTesterFeature())->permissions()
        );
    }

    public function testPreviewDoesNotDeliverAndSendUsesExplicitRecipient(): void
    {
        $service = new class extends EmailTesterService {
            public int $deliveries = 0;
            public ?string $recipient = null;

            public function preview(array $input): array
            {
                return [
                    'html' => '<p>Preview</p>',
                    'subject' => 'Subject',
                    'recipient' => (string)$input['recipient'],
                    'user' => $input['user'] ?? new \stdClass(),
                    'values' => $input,
                ];
            }

            protected function deliver(string $recipient, string $subject, string $html): bool
            {
                $this->deliveries++;
                $this->recipient = $recipient;
                return true;
            }
        };

        $service->preview(['recipient' => 'preview@example.test']);
        self::assertSame(0, $service->deliveries);

        self::assertTrue($service->send([
            'recipient' => 'override@example.test',
            'user' => (object)['email' => 'user@example.test'],
        ]));
        self::assertSame(1, $service->deliveries);
        self::assertSame('override@example.test', $service->recipient);
    }
}
