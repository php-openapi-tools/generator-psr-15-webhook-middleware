<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook;

use cebe\openapi\Reader;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Configuration\Package;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\WebHookMiddleware;
use OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests\GeneratedFiles;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function is_string;

final class PartialActionEnumWebHooksTest extends TestCase
{
    #[Test]
    public function generateUsesPartialActionEnumMatch(): void
    {
        $representation = Gatherer::gather(
            Reader::readFromYamlFile(__DIR__ . '/fixtures/PartialActionEnumWebHooks.yaml'),
            new Gathering(
                __DIR__ . '/fixtures/PartialActionEnumWebHooks.yaml',
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        $package = new Package(
            new Package\Metadata(
                'PartialActionEnumWebHooks',
                'Partial action enum webhook test client',
                [],
            ),
            'api-clients',
            'partial-action-enum-webhooks',
            null,
            null,
            null,
            new Package\Templates(
                __DIR__ . '/templates',
                [],
            ),
            new Package\Destination(
                'partial-action-enum-webhooks',
                'src',
                'tests',
            ),
            new Namespace_(
                'ApiClients\Client\PartialActionEnumWebHooks',
                'ApiClients\Tests\Client\PartialActionEnumWebHooks',
            ),
            new Package\QA(
                phpcs: new Package\QA\Tool(true, null),
                phpstan: new Package\QA\Tool(
                    true,
                    'etc/phpstan-extension.neon',
                ),
                psalm: new Package\QA\Tool(false, null),
            ),
            new Package\State(
                [
                    'composer.json',
                    'composer.lock',
                ],
            ),
            [],
        );

        $files = [];
        foreach (new WebHookMiddleware(new BuilderFactory(), ['/webhook'])->generate($package, $representation->namespace($package->namespace)) as $generatedFile) {
            $files[$generatedFile->fqcn] = new File(
                $generatedFile->pathPrefix,
                $generatedFile->fqcn,
                is_string($generatedFile->contents) ? $generatedFile->contents : new Standard()->prettyPrint([
                    new Node\Stmt\Declare_([
                        new Node\Stmt\DeclareDeclare('strict_types', new Node\Scalar\LNumber(1)),
                    ]),
                    $generatedFile->contents,
                ]),
                File::DO_LOAD_ON_WRITE,
            );
        }

        $webHooks = GeneratedFiles::contents($files, 'Internal\WebHook\WebHooks');

        self::assertStringContainsString("match (\$data['action'])", $webHooks);
        self::assertStringContainsString("'disabled'", $webHooks);
        self::assertStringContainsString("'enabled'", $webHooks);
        self::assertStringContainsString('WebhookBranchProtectionConfigurationDisabled', $webHooks);
        self::assertStringContainsString('WebhookBranchProtectionConfigurationEnabled', $webHooks);
        self::assertStringContainsString('WebhookCommitCommentCreated', $webHooks);
        self::assertStringContainsString('WebhookCustomPropertyCreated', $webHooks);
        self::assertStringContainsString('WebhookDeploymentProtectionRuleRequested', $webHooks);
        self::assertStringContainsString('WebhookCheckSuiteRequested', $webHooks);
        self::assertStringContainsString("'requested'", $webHooks);
        self::assertStringContainsString("if (\$data['action'] === 'requested')", $webHooks);
        self::assertStringContainsString('deployment_callback_url', $webHooks);
        self::assertStringNotContainsString(
            "\$data['action'] === 'requested' && array_key_exists('deployment_callback_url', \$data)",
            $webHooks,
        );
        self::assertStringNotContainsString(
            'return $this->validatedHydrate(WebhookDeploymentProtectionRuleRequested::class, $data);',
            $webHooks,
        );
        self::assertStringContainsString("if (\$data['action'] === 'deleted')", $webHooks);
        self::assertMatchesRegularExpression(
            '/if \(\$data\[\'action\'\] === \'deleted\'\) \{\s+if \(array_key_exists\(\'repository\', \$data\)\) \{\s+if \(array_key_exists\(\'sender\', \$data\)\)/',
            $webHooks,
        );
        self::assertStringContainsString('WebhookDiscussionDeleted', $webHooks);
        self::assertStringContainsString('WebhookIssuesDeleted', $webHooks);
        self::assertStringContainsString('WebhookCustomPropertyDeleted', $webHooks);
        self::assertStringContainsString('resolvedPayload', $webHooks);
    }
}
