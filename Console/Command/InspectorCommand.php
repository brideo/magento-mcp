<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * UpturnStudio_Mcp
 *
 * Convenience wrapper around the official MCP Inspector (`npx @modelcontextprotocol/
 * inspector`) - mirrors Laravel MCP's `artisan mcp:inspector`: constructs the correct stdio
 * invocation of `mcp:serve` automatically, so you don't have to remember the PHP binary path
 * or repeat --admin-user by hand. This only launches the Inspector via npx; it does not
 * implement or bundle it, and it requires Node.js/npx on PATH.
 *
 * Uses a temporary MCP client config file rather than inline command arguments - passing the
 * target command's own arguments inline on the Inspector's command line was found not to be
 * forwarded reliably (in either --cli or default/web mode); the config-file form is the one
 * confirmed to work, and matches how laravel/mcp's own InspectorCommand builds its config.
 */
class InspectorCommand extends Command
{
    private const OPTION_ADMIN_USER = 'admin-user';
    private const OPTION_INSPECTOR_VERSION = 'inspector-version';
    private const OPTION_CLI = 'cli';
    private const SERVER_NAME = 'magento-demo';

    /**
     * The Inspector's own dependencies (its bundler toolchain) declare "node": ">=22.19.0" -
     * below this, npm's install of its platform-specific optional dependencies has been
     * observed to fail in several different ways (a missing native binding, a broken ESM
     * import, or a startup crash), all going away once a genuinely-active Node >=22 is used.
     */
    private const MIN_NODE_VERSION = '22.19.0';

    /**
     * Only the Herd-bundled nvm is checked - this wrapper is inherently a bit tied to the
     * local dev machine already (it already assumes Herd's PHP layout via PHP_BINARY/BP).
     */
    private const NVM_SCRIPT_PATH = '/Library/Application Support/Herd/config/nvm/nvm.sh';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mcp:inspector')
            ->setDescription(
                'Launches the official MCP Inspector (via npx) against this store\'s stdio MCP '
                . 'server, for interactive debugging in a browser.'
            )
            ->addOption(
                self::OPTION_ADMIN_USER,
                null,
                InputOption::VALUE_REQUIRED,
                'The Magento admin username to act as - must have the "AI Connector (MCP)" permission.'
            )
            ->addOption(
                self::OPTION_INSPECTOR_VERSION,
                null,
                InputOption::VALUE_REQUIRED,
                'Pin a specific @modelcontextprotocol/inspector version instead of "latest".'
            )
            ->addOption(
                self::OPTION_CLI,
                null,
                InputOption::VALUE_NONE,
                'Run the Inspector\'s one-shot --cli mode instead of the interactive web UI.'
            )
            ->addArgument(
                'inspector-args',
                InputArgument::IS_ARRAY,
                'Extra arguments forwarded to the Inspector as-is (e.g. --method tools/list --format json). '
                . 'Put them after a literal "--" so Magento\'s own console parser leaves them alone.'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getOption(self::OPTION_ADMIN_USER);
        if ($username === '') {
            $output->writeln('<error>--admin-user is required.</error>');
            return Command::INVALID;
        }

        $configPath = sys_get_temp_dir() . '/upturnstudio_mcp_inspector_' . bin2hex(random_bytes(6)) . '.json';
        $config = [
            'mcpServers' => [
                self::SERVER_NAME => [
                    'type' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [BP . '/bin/magento', 'mcp:serve', '--admin-user=' . $username],
                    // Our server only implements the 2026-07-28 stateless protocol. With
                    // --config, the Inspector requires this set per-server in the file itself
                    // rather than as a separate --protocol-era flag (the two are mutually
                    // exclusive and it errors if both are given). Matches how laravel/mcp's
                    // own InspectorCommand sets this in its generated config.
                    'protocolEra' => 'modern',
                ],
            ],
        ];
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $version = $input->getOption(self::OPTION_INSPECTOR_VERSION);
        $package = $version !== null
            ? '@modelcontextprotocol/inspector@' . $version
            : '@modelcontextprotocol/inspector';

        $commandParts = ['npx', '--yes', $package];
        if ($input->getOption(self::OPTION_CLI)) {
            $commandParts[] = '--cli';
        }
        $commandParts = array_merge($commandParts, ['--config', $configPath, '--server', self::SERVER_NAME]);
        $commandParts = array_merge($commandParts, (array) $input->getArgument('inspector-args'));

        $innerCommand = implode(' ', array_map('escapeshellarg', $commandParts));
        $output->writeln('<info>Launching: ' . $innerCommand . '</info>');

        $process = $this->buildProcess($innerCommand, $output);

        // Symfony Process (matching laravel/mcp's own InspectorCommand), not passthru():
        // gives real-time output streaming plus explicit control over the timeout - Process
        // defaults to killing the child after 60s, which would silently cut off the
        // interactive web UI mode since it's meant to run indefinitely until the user stops it.
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->setTty(Process::isTtySupported());

        try {
            $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } finally {
            unlink($configPath);
        }

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * If the currently-active `node` already satisfies MIN_NODE_VERSION, runs the inner
     * command directly (no shell needed). Otherwise, if Herd's nvm is present, wraps it in a
     * `bash -c` that sources nvm and switches to Node 22 first - confirmed necessary: merely
     * pointing at a newer Node binary by absolute path is not sufficient on its own, nvm's
     * `use` also sets environment (NVM_BIN and friends) that npm's own resolution depends on.
     *
     * @param string $innerCommand Already shell-escaped
     * @param OutputInterface $output
     * @return Process
     */
    private function buildProcess(string $innerCommand, OutputInterface $output): Process
    {
        $activeVersion = $this->getActiveNodeVersion();
        if ($activeVersion !== null && version_compare($activeVersion, self::MIN_NODE_VERSION, '>=')) {
            return Process::fromShellCommandline($innerCommand);
        }

        $nvmScript = $this->findNvmScript();
        if ($nvmScript === null) {
            $output->writeln(sprintf(
                '<comment>Warning: active Node is %s, older than the %s the Inspector\'s own '
                . 'dependencies require, and no nvm installation was found to switch '
                . 'automatically. This will likely fail with a native-dependency or module '
                . 'resolution error - run `nvm use 22` yourself first if so.</comment>',
                $activeVersion ?? 'unknown',
                self::MIN_NODE_VERSION
            ));
            return Process::fromShellCommandline($innerCommand);
        }

        $output->writeln(sprintf(
            '<comment>Active Node is %s (Inspector needs >=%s) - switching to Node 22 via nvm for this run.</comment>',
            $activeVersion ?? 'unknown',
            self::MIN_NODE_VERSION
        ));

        return Process::fromShellCommandline(sprintf(
            'source %s >/dev/null 2>&1 && nvm use 22 >/dev/null 2>&1 && exec %s',
            escapeshellarg($nvmScript),
            $innerCommand
        ));
    }

    /**
     * @return string|null e.g. "21.7.3", or null if `node` isn't runnable at all
     */
    private function getActiveNodeVersion(): ?string
    {
        $process = Process::fromShellCommandline('node --version 2>/dev/null');
        $process->run();
        $version = ltrim(trim($process->getOutput()), 'v');
        return $version !== '' ? $version : null;
    }

    /**
     * @return string|null Absolute path to nvm.sh, or null if not found
     */
    private function findNvmScript(): ?string
    {
        $path = ($_SERVER['HOME'] ?? getenv('HOME') ?? '') . self::NVM_SCRIPT_PATH;
        return is_readable($path) ? $path : null;
    }
}
