<?php
declare(strict_types=1);

namespace FloCMS\CLI\Tests\Commands;

use FloCMS\CLI\Console\Testing\ApplicationTester;
use FloCMS\CLI\Tests\Support\TempProject;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Backlog B6, on the skeleton's users table (SQLite).
 */
final class UserCommandsTest extends TestCase
{
    private TempProject $project;

    private ApplicationTester $tester;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->sqlite();
        $this->pdo = new PDO('sqlite:' . $this->project->path('storage/database.sqlite'));
        $this->pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name VARCHAR(190) NOT NULL DEFAULT '',
            login VARCHAR(190) NOT NULL DEFAULT '',
            password VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL UNIQUE,
            image VARCHAR(255) NOT NULL DEFAULT '',
            role TINYINT NOT NULL DEFAULT 0,
            status TINYINT NOT NULL DEFAULT 2,
            is_verified TINYINT NOT NULL DEFAULT 0,
            verify_type TINYINT NOT NULL DEFAULT 0,
            token VARCHAR(255) NOT NULL DEFAULT ''
        )");
        $this->tester = ApplicationTester::forProject($this->project->root);
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->tester);
        $this->project->remove();
    }

    /** @return array<string, mixed> */
    private function user(string $email): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([$email]);

        return (array) $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function testCreateTheFirstAdminInteractively(): void
    {
        $code = $this->tester->run(['user:create'], ['Site Admin', 'not-an-email', 'admin@example.com', '', 'short', 'correct horse', 'correct horse']);

        self::assertSame(0, $code, $this->tester->getErrorOutput());
        self::assertStringContainsString('Enter a valid email address.', $this->tester->getErrorOutput());
        self::assertStringContainsString('Use at least 8 characters.', $this->tester->getErrorOutput());
        self::assertStringContainsString('created: admin@example.com (Super Admin (3), active)', $this->tester->getDisplay());
        self::assertStringNotContainsString('correct horse', $this->tester->getDisplay() . $this->tester->getErrorOutput());

        $user = $this->user('admin@example.com');
        self::assertSame('Site Admin', $user['full_name']);
        self::assertSame('admin@example.com', $user['login']);
        self::assertSame(3, (int) $user['role']);
        self::assertSame(1, (int) $user['status']);
        self::assertSame(1, (int) $user['is_verified']);
        self::assertTrue(password_verify('correct horse', (string) $user['password']));
    }

    public function testCreateForScripts(): void
    {
        $code = $this->tester->run(['user:create', '-n', '--name=Ed', '--email=ed@example.com', '--login=ed', '--role=editor', '--password-stdin'], ['s3cret-pass']);

        self::assertSame(0, $code, $this->tester->getErrorOutput());
        $user = $this->user('ed@example.com');
        self::assertSame('ed', $user['login']);
        self::assertSame(1, (int) $user['role']);
        self::assertTrue(password_verify('s3cret-pass', (string) $user['password']));
    }

    public function testCreateRefusesDuplicatesAndMissingInput(): void
    {
        $this->tester->run(['user:create', '-n', '--name=A', '--email=a@example.com', '--password-stdin'], ['password1']);

        self::assertSame(1, $this->tester->run(['user:create', '-n', '--name=B', '--email=a@example.com', '--password-stdin'], ['password2']));
        self::assertStringContainsString('already exists', $this->tester->getErrorOutput());
        self::assertSame(1, $this->tester->run(['user:create', '-n', '--name=C', '--email=c@example.com']));
        self::assertStringContainsString('--password-stdin', $this->tester->getErrorOutput());
        self::assertSame(1, $this->tester->run(['user:create', '-n', '--email=d@example.com', '--password-stdin'], ['password3']));
        self::assertSame(2, $this->tester->run(['user:create', '-n', '--name=E', '--email=e@example.com', '--role=boss', '--password-stdin'], ['password4']));
        self::assertStringContainsString('Unknown role "boss"', $this->tester->getErrorOutput());
        self::assertSame(1, $this->tester->run(['user:create', '-n', '--name=F', '--email=f@example.com', '--password-stdin'], ['short']));
    }

    public function testAMissingUsersTableIsExplained(): void
    {
        $this->pdo->exec('DROP TABLE users');

        self::assertSame(1, $this->tester->run(['user:list']));
        self::assertStringContainsString('The users table does not exist yet. Run "php flo migrate"', $this->tester->getErrorOutput());
    }

    public function testPasswordRoleSuspendAndList(): void
    {
        $this->tester->run(['user:create', '-n', '--name=Ann', '--email=ann@example.com', '--role=user', '--password-stdin'], ['first-password']);

        self::assertSame(0, $this->tester->run(['user:password', 'ann@example.com'], ['new-password', 'mismatch!', 'new-password', 'new-password']));
        self::assertStringContainsString('do not match', $this->tester->getErrorOutput());
        self::assertTrue(password_verify('new-password', (string) $this->user('ann@example.com')['password']));

        self::assertSame(0, $this->tester->run(['user:role', 'ann@example.com', 'Super Admin']));
        self::assertStringContainsString('is now Super Admin (3) (was User (0))', $this->tester->getDisplay());
        self::assertSame(3, (int) $this->user('ann@example.com')['role']);

        self::assertSame(0, $this->tester->run(['user:suspend', 'ann@example.com']));
        self::assertSame(3, (int) $this->user('ann@example.com')['status']);
        self::assertStringContainsString('api:token:revoke --user=', $this->tester->getDisplay());
        self::assertSame(0, $this->tester->run(['user:suspend', 'ann@example.com']));
        self::assertStringContainsString('already suspended', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['user:list', '--status=suspended']));
        self::assertMatchesRegularExpression('/\| 1 +\| Ann +\| ann@example\.com +\| Super Admin \(3\) +\| Suspended +\| yes +\|/', $this->tester->getDisplay());
        self::assertSame(0, $this->tester->run(['user:unsuspend', 'ann@example.com']));
        self::assertSame(1, (int) $this->user('ann@example.com')['status']);

        $this->tester->run(['user:list', '--format=json', '--role=3']);
        self::assertSame('ann@example.com', json_decode($this->tester->getDisplay(), true)[0]['email']);
        $this->tester->run(['user:list', '--role=editor']);
        self::assertStringContainsString('No users.', $this->tester->getDisplay());
        self::assertSame(2, $this->tester->run(['user:list', '--status=gone']));

        self::assertSame(1, $this->tester->run(['user:role', 'nobody@example.com', 'admin']));
        self::assertStringContainsString('No user with the email "nobody@example.com".', $this->tester->getErrorOutput());
    }
}
