<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Core\Database\TransactionManager;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;

final class JournalPostingService
{
    public function __construct(
        private readonly TransactionManager $tx,
        private readonly JournalEntryRepository $journals,
        private readonly ChartOfAccountRepository $accounts,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly BranchRepository $branches,
        private readonly StockLedgerRepository $stockLedger,
        private readonly AuditLogService $audit
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function post(array $payload): int
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $createdBy = (int) ($payload['created_by'] ?? 0);
        $entryDate = (string) ($payload['entry_date'] ?? '');
        $referenceType = trim((string) ($payload['reference_type'] ?? 'manual'));
        $referenceId = isset($payload['reference_id']) ? (string) $payload['reference_id'] : null;
        $description = trim((string) ($payload['description'] ?? ''));
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $source = strtolower(trim((string) ($payload['source'] ?? ($metadata['source'] ?? ''))));
        $correlationId = trim((string) ($payload['correlation_id'] ?? ''));
        if ($correlationId === '') {
            $correlationId = $this->uuidV4();
        }
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : null;
        $lines = $payload['lines'] ?? null;

        if ($createdBy <= 0) {
            throw new JournalPostingValidationException([
                'code' => 'invalid_created_by',
            ], 'Journal posting requires a valid creator.');
        }

        if ($entryDate === '') {
            throw new JournalPostingValidationException([
                'code' => 'invalid_entry_date',
            ], 'Journal posting requires an entry date.');
        }

        if ($description === '') {
            throw new JournalPostingValidationException([
                'code' => 'invalid_description',
            ], 'Journal posting requires a description.');
        }

        if (strlen($correlationId) > 64) {
            throw new JournalPostingValidationException([
                'code' => 'invalid_correlation_id',
                'max_length' => 64,
            ], 'Correlation ID exceeds allowed length.');
        }

        if (!is_array($lines)) {
            throw new JournalPostingValidationException([
                'code' => 'invalid_lines_payload',
            ], 'Journal posting requires a lines array.');
        }

        if (count($lines) === 0) {
            throw new JournalPostingValidationException([
                'code' => 'zero_line_entries',
            ], 'Journal posting cannot contain zero lines.');
        }

        if (count($lines) < 2) {
            throw new JournalPostingValidationException([
                'code' => 'insufficient_lines',
                'line_count' => count($lines),
            ], 'Journal posting requires at least two lines.');
        }

        $normalizedLines = [];
        $accountIds = [];
        $totalDebitScaled = 0;
        $totalCreditScaled = 0;
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new JournalPostingValidationException([
                    'code' => 'invalid_line',
                    'line_index' => $index,
                ], 'Journal line format is invalid.');
            }

            $accountId = (int) ($line['account_id'] ?? 0);
            $rawDebit = $line['debit_amount'] ?? 0;
            $rawCredit = $line['credit_amount'] ?? 0;

            if (!is_numeric($rawDebit) || !is_numeric($rawCredit)) {
                throw new JournalPostingValidationException([
                    'code' => 'invalid_numeric_amount',
                    'line_index' => $index,
                    'debit_amount' => $rawDebit,
                    'credit_amount' => $rawCredit,
                ], 'Debit and credit amounts must be numeric.');
            }

            $debitScaled = $this->toScaledAmount($rawDebit);
            $creditScaled = $this->toScaledAmount($rawCredit);

            if ($accountId <= 0) {
                throw new JournalPostingValidationException([
                    'code' => 'invalid_account',
                    'line_index' => $index,
                ], 'Journal line has an invalid account.');
            }

            if ($debitScaled < 0 || $creditScaled < 0) {
                throw new JournalPostingValidationException([
                    'code' => 'negative_amount',
                    'line_index' => $index,
                    'debit_amount' => $this->toDecimalString($debitScaled),
                    'credit_amount' => $this->toDecimalString($creditScaled),
                ], 'Negative debit/credit amounts are not allowed.');
            }

            if ($debitScaled === 0 && $creditScaled === 0) {
                throw new JournalPostingValidationException([
                    'code' => 'zero_line_amount',
                    'line_index' => $index,
                ], 'Journal line cannot have both debit and credit as zero.');
            }

            if ($debitScaled > 0 && $creditScaled > 0) {
                throw new JournalPostingValidationException([
                    'code' => 'dual_sided_line',
                    'line_index' => $index,
                ], 'Journal line cannot have both debit and credit amounts.');
            }

            $accountIds[] = $accountId;
            $totalDebitScaled += $debitScaled;
            $totalCreditScaled += $creditScaled;
            $normalizedLines[] = [
                'account_id' => $accountId,
                'debit_amount' => $this->toDecimalString($debitScaled),
                'credit_amount' => $this->toDecimalString($creditScaled),
            ];
        }

        if ($totalDebitScaled <= 0 || $totalCreditScaled <= 0 || $totalDebitScaled !== $totalCreditScaled) {
            throw new JournalPostingValidationException([
                'code' => 'unbalanced_entry',
                'total_debit' => $this->toDecimalString($totalDebitScaled),
                'total_credit' => $this->toDecimalString($totalCreditScaled),
            ], 'Journal entry is not balanced.');
        }

        $activeAccounts = $this->accounts->findActiveByIds($accountIds, $organizationId);
        if (count($activeAccounts) !== count(array_unique($accountIds))) {
            throw new JournalPostingValidationException([
                'code' => 'inactive_or_missing_account',
            ], 'One or more posting accounts are inactive or missing.');
        }

        $includesInventoryAccount = false;
        foreach ($normalizedLines as $line) {
            $account = $activeAccounts[(int) $line['account_id']] ?? null;
            if ($account !== null && (string) $account['code'] === '1300') {
                $includesInventoryAccount = true;
                break;
            }
        }

        if ($includesInventoryAccount) {
            $isInventorySource = $source === 'inventory';
            $hasStockCorrelation = $this->stockLedger->existsCorrelationId($correlationId, $organizationId);
            if (!$isInventorySource && !$hasStockCorrelation) {
                throw new JournalPostingValidationException([
                    'code' => 'manual_inventory_account_blocked',
                    'correlation_id' => $correlationId,
                    'source' => $source === '' ? null : $source,
                ], 'Direct manual postings to Inventory account are blocked.');
            }
        }

        if ($branchId !== null && !$this->branches->isActiveInOrganization($branchId, $organizationId)) {
            throw new JournalPostingValidationException([
                'code' => 'invalid_branch_scope',
                'branch_id' => $branchId,
            ], 'Branch scope is invalid for this organization.');
        }

        $openPeriod = $this->periodGuard->assertMutationAllowed($entryDate, 'journal.posting', $organizationId);
        $resolvedOrganizationId = (int) $openPeriod['organization_id'];
        $resolvedPeriodId = (int) $openPeriod['id'];

        return $this->tx->run(function () use (
            $organizationId,
            $resolvedOrganizationId,
            $resolvedPeriodId,
            $branchId,
            $entryDate,
            $referenceType,
            $referenceId,
            $description,
            $correlationId,
            $createdBy,
            $normalizedLines,
            $totalDebitScaled,
            $totalCreditScaled,
            $requestId,
            $payload
        ): int {
            $lockedPeriod = $this->periodGuard->lockOpenPeriodForPosting($resolvedPeriodId, $entryDate, $organizationId);

            $entryId = $this->journals->createJournalEntry([
                'branch_id' => $branchId,
                'entry_date' => $entryDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'period_id' => (int) $lockedPeriod['id'],
                'correlation_id' => $correlationId,
                'created_by' => $createdBy,
            ], $organizationId);

            $this->journals->insertJournalLines($entryId, $normalizedLines);

            $this->audit->log([
                'organization_id' => $resolvedOrganizationId,
                'branch_id' => $branchId,
                'user_id' => $createdBy,
                'action_type' => 'journal.posted',
                'entity_type' => 'journal_entry',
                'entity_id' => (string) $entryId,
                'metadata' => [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'line_count' => count($normalizedLines),
                ],
                'before_state' => null,
                'after_state' => [
                    'journal_entry_id' => $entryId,
                    'entry_date' => $entryDate,
                    'period_id' => (int) $lockedPeriod['id'],
                    'line_count' => count($normalizedLines),
                    'total_debit' => $this->toDecimalString($totalDebitScaled),
                    'total_credit' => $this->toDecimalString($totalCreditScaled),
                    'correlation_id' => $correlationId,
                ],
                'request_id' => $requestId ?? null,
                'correlation_id' => $correlationId,
                'ip' => $payload['ip'] ?? null,
                'ip_address' => $payload['ip_address'] ?? null,
                'user_agent' => $payload['user_agent'] ?? null,
            ]);

            return $entryId;
        });
    }

    private function toScaledAmount(mixed $amount): int
    {
        if (is_string($amount)) {
            $amount = trim($amount);
            if ($amount === '') {
                $amount = '0';
            }
        }

        return (int) round(((float) $amount) * 10000);
    }

    private function toDecimalString(int $scaledAmount): string
    {
        return number_format($scaledAmount / 10000, 4, '.', '');
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
