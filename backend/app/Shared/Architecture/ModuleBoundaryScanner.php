<?php

declare(strict_types=1);

namespace App\Shared\Architecture;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static checker for V1.0 module boundaries.
 *
 * Rules (see docs/01-architecture.md):
 * - a module may import App\Shared\* and its own code;
 * - another module may be referenced only through its Contracts namespace;
 * - Platform must not depend on business modules at all;
 * - direct DB::table / DB::connection()->table access is allowed only for
 *   tables owned by the calling module; unknown tables are warnings;
 * - Schema::* DDL is allowed only in database/migrations, never in app code.
 *
 * The checker is token-based rather than regex-based so grouped imports and
 * references inside string literals are handled without matching comments.
 */
final class ModuleBoundaryScanner
{
    /**
     * Deliberate, narrow exceptions to the Contracts-only rule. Each entry
     * must carry the architectural reason and be documented in
     * docs/01-architecture.md. Empty is the intended default.
     *
     * @var array<string, string>
     */
    public const EXPLICIT_ALLOWLIST = [];

    /**
     * Table ownership used to attribute raw table access. Keep in sync with
     * the module table in docs/01-architecture.md.
     *
     * @var array<string, list<string>>
     */
    public const TABLE_OWNERS = [
        'IAM' => ['users', 'sessions', 'capabilities', 'authority_bindings', 'auth_events', 'roles', 'role_capabilities', 'user_roles', 'iam_data_scope_policies', 'iam_field_policies'],
        'People' => ['persons', 'employments', 'assignments', 'employment_terms', 'documents', 'employment_user_links', 'employment_contracts', 'contract_templates'],
        'Organization' => ['org_units', 'org_unit_closure', 'positions', 'position_qualifications', 'position_grades', 'company_profile'],
        'Lifecycle' => ['cases', 'decisions', 'effects', 'lifecycle_tasks', 'lifecycle_intents', 'onboarding_checklist_templates', 'employment_contract_versions'],
        'Workflow' => ['workflow_definitions', 'workflow_versions', 'workflow_instances', 'workflow_tasks', 'workflow_delegations'],
        'Time' => ['shifts', 'schedules', 'punch_records', 'attendance_days', 'attendance_corrections', 'attendance_rule_versions', 'attendance_period_closures', 'attendance_devices', 'shift_schedules', 'leave_accounts', 'leave_ledger_entries', 'leave_types', 'leave_balances', 'leave_requests', 'overtime_records', 'overtime_settlements', 'overtime_requests', 'business_trip_records', 'travel_policies', 'travel_expenses'],
        'Payroll' => ['payroll_items', 'payroll_schemes', 'payroll_runs', 'payslips', 'payslip_lines', 'payroll_input_snapshots', 'payroll_ytd', 'payroll_receipts', 'payroll_adjustments', 'employee_compensations', 'salary_adjustments', 'benefit_plans', 'benefit_enrollments', 'benefit_transactions'],
        'Talent' => ['recruitment_requisitions', 'candidates', 'candidate_evaluations', 'candidate_communications', 'talent_pool_tags', 'candidate_imports', 'candidate_conversions', 'requisition_openings', 'interviews', 'offers', 'performance_cycles', 'performance_templates', 'performance_reviews', 'performance_appeals', 'performance_indicators', 'performance_goals', 'performance_feedback', 'performance_calibrations', 'training_programs', 'training_sessions', 'training_enrollments', 'training_plans', 'training_courses', 'training_checkins', 'training_exams', 'training_feedback'],
        'Engagement' => ['notices', 'notice_templates', 'notice_acknowledgements', 'notifications'],
        'Reporting' => ['report_definitions', 'report_subscriptions', 'export_jobs', 'projections'],
        'Platform' => ['audit_events', 'outbox_events', 'idempotency_keys', 'file_objects', 'dictionaries', 'parameters', 'sys_dictionaries', 'sys_parameters', 'menu_overrides', 'notification_templates', 'notification_outbox', 'notification_deliveries', 'feature_flags', 'migration_runs', 'migration_issues', 'staging_rows', 'migration_shadow_rows', 'demo_datasets', 'retention_policies', 'legal_holds', 'platform_installation_states'],
    ];

    private const FACADE_DB = 'Illuminate\Support\Facades\DB';

    private const FACADE_SCHEMA = 'Illuminate\Support\Facades\Schema';

    /**
     * @var array<string, list<string>>
     */
    private array $tableOwners = [];

    public function __construct()
    {
        foreach (self::TABLE_OWNERS as $module => $tables) {
            foreach ($tables as $table) {
                $this->tableOwners[$table] = [...($this->tableOwners[$table] ?? []), $module];
            }
        }
    }

    public function scan(string $modulesRoot): BoundaryReport
    {
        $root = rtrim(str_replace('\\', '/', $modulesRoot), '/');
        $violations = [];
        $warnings = [];
        $filesScanned = 0;

        foreach ($this->phpFiles($root) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            $module = explode('/', $relative)[0];

            if ($module === '' || ! str_contains($relative, '/')) {
                continue;
            }

            $filesScanned++;
            $source = (string) file_get_contents($file);
            [$fileViolations, $fileWarnings] = $this->scanSource($source, $module, $relative);
            $violations = [...$violations, ...$fileViolations];
            $warnings = [...$warnings, ...$fileWarnings];
        }

        usort(
            $violations,
            static fn (BoundaryFinding $a, BoundaryFinding $b): int => [$a->file, $a->line, $a->reference] <=> [$b->file, $b->line, $b->reference],
        );
        usort(
            $warnings,
            static fn (BoundaryFinding $a, BoundaryFinding $b): int => [$a->file, $a->line, $a->reference] <=> [$b->file, $b->line, $b->reference],
        );

        return new BoundaryReport($filesScanned, $violations, $warnings);
    }

    /**
     * @return array{0: list<BoundaryFinding>, 1: list<BoundaryFinding>}
     */
    private function scanSource(string $source, string $module, string $file): array
    {
        $tokens = PhpToken::tokenize($source);
        $significant = array_values(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $violations = [];
        $warnings = [];
        $importAliases = [];
        $importTokenIndexes = [];

        foreach ($significant as $index => $token) {
            if (! $token->is(T_USE) || $this->isClosureUse($significant, $index)) {
                continue;
            }

            $statement = '';
            $usesGroup = false;

            for ($cursor = $index + 1; isset($significant[$cursor]); $cursor++) {
                $part = $significant[$cursor];
                $importTokenIndexes[$cursor] = true;
                $statement .= $part->text;

                if ($part->text === '{') {
                    $usesGroup = true;
                }

                if ($part->text === ';') {
                    break;
                }
            }

            $this->collectImports($statement, $token->line, $module, $file, $importAliases, $violations);
        }

        foreach ($significant as $index => $token) {
            if (isset($importTokenIndexes[$index])) {
                continue;
            }

            if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
                $finding = $this->checkReference(ltrim($token->text, '\\'), $module, $file, $token->line);

                if ($finding !== null) {
                    $violations[] = $finding;
                }
            }

            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $literal = trim($token->text, '\'"');

                if (str_starts_with($literal, 'App\\Modules\\')) {
                    $finding = $this->checkReference($literal, $module, $file, $token->line);

                    if ($finding !== null) {
                        $violations[] = $finding;
                    }
                }
            }

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && isset($significant[$index + 1]) && $significant[$index + 1]->text === '::') {
                $receiver = $this->resolveIdentifier($token->text, $importAliases);

                if ($this->isFacade($receiver, self::FACADE_SCHEMA)
                    && ! ($module === 'Platform' && str_contains(str_replace(chr(92), '/', $file), 'Platform/Migration/Adapters/'))) {
                    $violations[] = new BoundaryFinding(
                        'schema',
                        $file,
                        $token->line,
                        $module,
                        $token->text.'::'.$this->nextCallName($significant, $index + 1),
                        sprintf('%s must not run Schema DDL outside database/migrations.', $module),
                    );
                }
            }

            if (! $token->is(T_STRING) || $token->text !== 'table' || ! isset($significant[$index + 1]) || $significant[$index + 1]->text !== '(') {
                continue;
            }

            $receiver = $this->tableReceiver($significant, $index, $importAliases);

            if ($receiver === null || $this->isFacade($receiver, self::FACADE_SCHEMA)) {
                continue;
            }

            $table = $this->firstStringArgument($significant, $index + 1);

            if ($table !== null) {
                // Normalise "table as alias": the alias is presentation, not a
                // different table, and must not appear as an unknown owner.
                $table = preg_replace('/\s+as\s+.+$/i', '', trim($table)) ?? $table;
            }

            if ($table === null) {
                $warnings[] = new BoundaryFinding(
                    'unresolved_table',
                    $file,
                    $token->line,
                    $module,
                    'dynamic table name',
                    'Cannot resolve dynamic table name to an owning module; review manually.',
                );

                continue;
            }

            $owners = $this->tableOwners[$table] ?? [];

            if ($owners === []) {
                $warnings[] = new BoundaryFinding(
                    'unknown_table',
                    $file,
                    $token->line,
                    $module,
                    $table,
                    "Table {$table} has no documented owner; add it to the ownership map.",
                );

                continue;
            }

            if (in_array($module, $owners, true)) {
                continue;
            }

            // Documented one-way exception: the migration bootstrapper writes
            // target module tables before those modules' services exist. It is
            // confined to Platform/Migration/Adapters and audited separately.
            if ($module === 'Platform' && str_contains(str_replace(chr(92), '/', $file), 'Platform/Migration/Adapters/')) {
                continue;
            }

            $violations[] = new BoundaryFinding(
                'table_access',
                $file,
                $token->line,
                $module,
                $table,
                sprintf('%s must not access %s-owned table %s directly; use a Contracts query or projection.', $module, implode('/', $owners), $table),
            );
        }

        return [$violations, $warnings];
    }

    /**
     * @param  array<string, string>  $aliases
     * @param  list<BoundaryFinding>  $violations
     */
    private function collectImports(
        string $statement,
        int $line,
        string $module,
        string $file,
        array &$aliases,
        array &$violations,
    ): void {
        $statement = trim(preg_replace('/^use\s+/i', '', $statement) ?? '');

        if ($statement === '' || str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
            return;
        }

        $statement = rtrim($statement, ';');
        $entries = [];

        if (str_contains($statement, '{')) {
            [$prefix, $rest] = explode('{', $statement, 2);
            $prefix = rtrim(trim($prefix), '\\');
            $inner = explode('}', $rest, 2)[0];

            foreach ($this->splitTopLevel($inner) as $entry) {
                $entries[] = $prefix.'\\'.trim($entry);
            }
        } else {
            $entries = $this->splitTopLevel($statement);
        }

        foreach ($entries as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $entry) ?: [$entry];
            $reference = ltrim(trim($parts[0]), '\\');
            $alias = isset($parts[1]) ? trim($parts[1]) : $this->shortName($reference);
            $aliases[$alias] = $reference;

            $finding = $this->checkReference($reference, $module, $file, $line);

            if ($finding !== null) {
                $violations[] = $finding;
            }
        }
    }

    private function checkReference(string $reference, string $module, string $file, int $line): ?BoundaryFinding
    {
        $reference = ltrim(trim($reference), '\\');

        if (! str_starts_with($reference, 'App\\Modules\\')) {
            return null;
        }

        if (isset(self::EXPLICIT_ALLOWLIST[$reference])) { // @phpstan-ignore isset.offset
            return null;
        }

        $parts = explode('\\', $reference);
        $targetModule = $parts[2] ?? '';

        if ($targetModule === '' || $targetModule === $module) {
            return null;
        }

        if ($module !== 'Platform' && ($parts[3] ?? '') === 'Contracts') {
            return null;
        }

        $message = $module === 'Platform'
            ? sprintf('Platform must not depend on business module %s (%s).', $targetModule, $reference)
            : sprintf('%s may not import %s; only another module\'s Contracts namespace is public.', $module, $reference);

        return new BoundaryFinding('module_reference', $file, $line, $module, $reference, $message);
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function isClosureUse(array $tokens, int $index): bool
    {
        for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            return $tokens[$cursor]->text === '(';
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $value): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        foreach (str_split($value) as $character) {
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }

    private function shortName(string $reference): string
    {
        $parts = explode('\\', $reference);

        return (string) end($parts);
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private function tableReceiver(array $tokens, int $tableIndex, array $aliases): ?string
    {
        $arrowIndex = $tableIndex - 1;

        if (! isset($tokens[$arrowIndex]) || ! in_array($tokens[$arrowIndex]->text, ['::', '->'], true)) {
            return null;
        }

        $receiverIndex = $arrowIndex - 1;

        if (! isset($tokens[$receiverIndex])) {
            return null;
        }

        $receiver = $this->resolveIdentifier($tokens[$receiverIndex]->text, $aliases);

        if ($tokens[$arrowIndex]->text === '::') {
            return $receiver;
        }

        // Detect DB::connection(...)->table('x') without treating arbitrary
        // builders (for example $query->table()) as SQL.
        for ($cursor = $receiverIndex - 1; $cursor >= 0 && $tokens[$cursor]->text !== ';'; $cursor--) {
            if ($tokens[$cursor]->text === 'connection' && isset($tokens[$cursor - 2])) {
                $candidate = $this->resolveIdentifier($tokens[$cursor - 2]->text, $aliases);

                if ($this->isFacade($candidate, self::FACADE_DB)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function firstStringArgument(array $tokens, int $openParenIndex): ?string
    {
        for ($cursor = $openParenIndex + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor]->text === ')') {
                return null;
            }

            if ($tokens[$cursor]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return trim($tokens[$cursor]->text, '\'"');
            }

            if (! $tokens[$cursor]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function nextCallName(array $tokens, int $doubleColonIndex): string
    {
        for ($cursor = $doubleColonIndex + 1; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor]->is([T_STRING, T_NAME_QUALIFIED])) {
                return $tokens[$cursor]->text;
            }

            if ($tokens[$cursor]->text === ';') {
                break;
            }
        }

        return 'call';
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function resolveIdentifier(string $identifier, array $aliases): string
    {
        $identifier = ltrim($identifier, '\\');

        if (isset($aliases[$identifier])) {
            return $aliases[$identifier];
        }

        return $identifier;
    }

    private function isFacade(string $identifier, string $facade): bool
    {
        $identifier = ltrim($identifier, '\\');

        if ($identifier === $facade) {
            return true;
        }

        $short = $this->shortName($facade);

        return $identifier === $short;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }
}
