<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;

/**
 * M0 — preflight gate enforced by Claude Code hooks (spec §11.7).
 */
class PreflightGateTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/gate_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/.preflight', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/.preflight/PASS');
        @rmdir($this->tmp . '/.preflight');
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** Run the guard hook with a given tool call against a throwaway project root; returns exit code. */
    private function guard(array $call): int
    {
        $proc = proc_open(
            [PHP_BINARY, base_path('.claude/hooks/guard-preflight.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['CLAUDE_PROJECT_DIR' => $this->tmp]),
        );
        fwrite($pipes[0], json_encode($call));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        return proc_close($proc);
    }

    private function write(string $rel): array
    {
        return ['tool_name' => 'Write', 'tool_input' => ['file_path' => $this->tmp . '/' . $rel]];
    }

    /** REQ-0551, REQ-0552, REQ-0553 — hooks call php directly, block on failure, run at session start. */
    public function test_req_0552_hooks_are_configured_with_direct_php_and_block_on_failure(): void
    {
        $s = json_decode(file_get_contents(base_path('.claude/settings.json')), true);
        $start = $s['hooks']['SessionStart'][0];
        $this->assertSame('startup|resume|clear|compact', $start['matcher']);
        $this->assertSame('php', $start['hooks'][0]['command']);
        $pre = $s['hooks']['PreToolUse'][0];
        foreach (['Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'Bash', 'PowerShell'] as $tool) {
            $this->assertStringContainsString($tool, $pre['matcher']);
        }
        $this->assertSame('php', $pre['hooks'][0]['command']);
        $this->assertSame('block', $pre['hooks'][0]['onFailure']);
    }

    /** REQ-0539, REQ-0554, REQ-0556 — app-code writes are blocked (exit 2) without a pass marker. */
    public function test_req_0554_app_code_write_is_blocked_without_pass_marker(): void
    {
        $this->assertSame(2, $this->guard($this->write('app/Models/Lease.php')));
    }

    /** REQ-0554 — setup files stay writable without a pass marker. */
    public function test_req_0554_setup_files_stay_writable(): void
    {
        foreach (['docs/QUESTIONS.md', 'scripts/x.php', '.env', 'CLAUDE.md', '.gitignore'] as $rel) {
            $this->assertSame(0, $this->guard($this->write($rel)), $rel);
        }
    }

    /** REQ-0554 — a marker older than 24 hours does not unlock writes; a fresh one does. */
    public function test_req_0554_marker_must_be_fresher_than_24_hours(): void
    {
        touch($this->tmp . '/.preflight/PASS', time() - 86400 - 60);
        $this->assertSame(2, $this->guard($this->write('app/x.php')));
        touch($this->tmp . '/.preflight/PASS');
        $this->assertSame(0, $this->guard($this->write('app/x.php')));
    }

    /** REQ-0555 — build commands are blocked in Bash and PowerShell without a pass marker. */
    public function test_req_0555_build_commands_are_blocked_without_pass_marker(): void
    {
        foreach (['composer require foo/bar', 'php artisan migrate', 'npm install', 'npm run build', 'npx vite', 'laravel new x', 'composer create-project laravel/laravel'] as $cmd) {
            foreach (['Bash', 'PowerShell'] as $tool) {
                $this->assertSame(2, $this->guard(['tool_name' => $tool, 'tool_input' => ['command' => $cmd]]), "$tool: $cmd");
            }
        }
        $this->assertSame(0, $this->guard(['tool_name' => 'Bash', 'tool_input' => ['command' => 'git status']]));
    }

    /** REQ-0540, REQ-0541, REQ-0542, REQ-0543, REQ-0544, REQ-0545, REQ-0546, REQ-0547, REQ-0548, REQ-0550, REQ-0558 — the preflight script exists, covers each category, writes report + marker. */
    public function test_req_0540_preflight_script_covers_all_categories(): void
    {
        $src = file_get_contents(base_path('scripts/preflight.php'));
        foreach (['git', 'composer', 'node', 'npm', 'intl', 'pdo_pgsql', '1448', 'server_version_num', 'packagist', 'registry.npmjs.org', 'PREFLIGHT_REPORT.md', '.preflight/PASS'] as $needle) {
            $this->assertStringContainsString($needle, $src, $needle);
        }
    }

    /** REQ-0549 — repository holds spec, prompt (register), traceability and CLAUDE.md. REQ-0512 — Claude Code package. */
    public function test_req_0549_repository_holds_required_documents(): void
    {
        foreach (['docs/SPEC.md', 'PROMPT.md', 'docs/TRACEABILITY.md', 'CLAUDE.md', 'tools/coverage_check.py', 'tools/trace_check.php'] as $f) {
            $this->assertFileExists(base_path($f));
        }
        $this->assertDirectoryExists(base_path('.git'));
    }
}
