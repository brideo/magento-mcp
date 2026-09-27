<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Console\Command;

use Magento\User\Model\UserFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use UpturnStudio\Mcp\Model\Mcp\JsonRpcDispatcher;
use UpturnStudio\Mcp\Model\Oauth\AdminAclChecker;

/**
 * UpturnStudio_Mcp
 *
 * Runs the MCP server over stdio: `bin/magento mcp:serve --admin-user=<username>`. A local,
 * trusted alternative to the HTTP+OAuth transport, for use as a subprocess from Claude
 * Desktop/Claude Code's local MCP config (an mcpServers entry with a "command", not a URL) -
 * no network hop, no browser consent flow. Per the MCP spec, stdio implementations should not
 * use the HTTP authorization framework and should instead take credentials from the
 * environment; here that is simply "which admin to run as", supplied by whoever can already
 * run bin/magento on this server. Reuses the exact same JsonRpcDispatcher, ToolRegistry, and
 * read-only guard as the HTTP transport - only the transport (stdin/stdout vs HTTP) differs.
 */
class ServeCommand extends Command
{
    private const ACL_RESOURCE = 'UpturnStudio_Mcp::connector';
    private const OPTION_ADMIN_USER = 'admin-user';

    /**
     * @param UserFactory $userFactory
     * @param AdminAclChecker $adminAclChecker
     * @param JsonRpcDispatcher $jsonRpcDispatcher
     * @param string|null $name
     */
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly AdminAclChecker $adminAclChecker,
        private readonly JsonRpcDispatcher $jsonRpcDispatcher,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mcp:serve')
            ->setDescription(
                'Runs the MCP server over stdio, acting as the given admin user. For use as a '
                . 'local MCP client subprocess (e.g. Claude Desktop/Claude Code), not for remote or network use.'
            )
            ->addOption(
                self::OPTION_ADMIN_USER,
                null,
                InputOption::VALUE_REQUIRED,
                'The Magento admin username to act as - must have the "AI Connector (MCP)" permission.'
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

        $user = $this->userFactory->create();
        $user->loadByUsername($username);
        if (!$user->getId() || !$user->getIsActive()) {
            $output->writeln(sprintf('<error>No active admin user named "%s".</error>', $username));
            return Command::FAILURE;
        }

        $adminUserId = (int) $user->getId();
        if (!$this->adminAclChecker->isAllowed($adminUserId, self::ACL_RESOURCE)) {
            $output->writeln(sprintf(
                '<error>"%s" does not have the "AI Connector (MCP)" permission.</error>',
                $username
            ));
            return Command::FAILURE;
        }

        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $body = json_decode($line, true);
            if (!is_array($body)) {
                $this->writeLine($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32700, 'message' => 'Parse error.'],
                ]);
                continue;
            }

            try {
                $dispatched = $this->jsonRpcDispatcher->dispatch($body, [], $adminUserId, false);
                $this->writeLine($stdout, $dispatched['body']);
            } catch (\Throwable $e) {
                // JsonRpcDispatcher already catches its own internal errors; this is a last
                // resort so one bad line can never take down the whole server process.
                $this->writeLine($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => $body['id'] ?? null,
                    'error' => ['code' => -32603, 'message' => 'Internal error.'],
                ]);
            }
        }

        fclose($stdin);
        fclose($stdout);

        return Command::SUCCESS;
    }

    /**
     * @param resource $stdout
     * @param array $body
     * @return void
     */
    private function writeLine($stdout, array $body): void
    {
        fwrite($stdout, json_encode($body) . "\n");
        fflush($stdout);
    }
}
