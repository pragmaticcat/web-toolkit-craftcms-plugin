<?php

declare(strict_types=1);

namespace pragmatic\webtoolkit\tests\unit\domains\sync;

use CraftCms\Cms\Console\Application as CraftConsole;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use pragmatic\webtoolkit\console\commands\SyncSetupCommand;
use pragmatic\webtoolkit\console\commands\SyncTransferCommand;
use Symfony\Component\Console\Tester\ApplicationTester;

final class SyncConsoleTest extends TestCase
{
    public function testCraftListsAndResolvesTheShortSyncCommands(): void
    {
        $console = $this->console();
        $tester = new ApplicationTester($console);
        $this->assertSame(0, $tester->run(['command' => 'list', '--raw' => true]));

        $expected = [
            'sync/setup',
            'sync/all/down', 'sync/all/up',
            'sync/code/down', 'sync/code/up',
            'sync/db/down', 'sync/db/up',
            'sync/assets/down', 'sync/assets/up',
        ];
        foreach ($expected as $name) {
            $this->assertMatchesRegularExpression('~^' . preg_quote($name, '~') . '\s~m', $tester->getDisplay());
            $this->assertSame($name, $console->find($name)->getName());
        }
    }

    public function testProfileSelectionRefusesToUseAnEnvironmentFileWithTheSameName(): void
    {
        $app = new Application();
        $app->loadEnvironmentFrom('.env.staging');
        $tester = new ApplicationTester($this->console($app));
        $exit = $tester->run(['command' => 'sync/all/down', '--env' => 'staging', '--dry-run' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Use --profile=staging', $tester->getDisplay());
    }

    private function console(?Application $app = null): CraftConsole
    {
        $app ??= new Application();
        $console = new CraftConsole($app, new Dispatcher($app), 'test');
        $console->setAutoExit(false);
        $console->resolveCommands([SyncSetupCommand::class, ...SyncTransferCommand::commands()]);

        return $console;
    }
}
